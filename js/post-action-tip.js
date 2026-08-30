(function($) {
    'use strict';

    var config = window.wcosPremiumUpsell || {};
    var storageKey = 'wcosPremiumUpsellStateV1';
    var legacyKeys = [
        'wcosPostActionTip',
        'wcosPostSplitTip',
        'wcosPostActionTipDismissedAt'
    ];
    var validActions = ['split', 'duplicate', 'merge'];
    var seenLimit = 40;

    function emptyState() {
        return {
            usage: {
                split: 0,
                duplicate: 0,
                merge: 0
            },
            seenOperations: [],
            pending: {},
            shown: {},
            dismissed: {}
        };
    }

    function getStoredItem(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function setStoredItem(key, value) {
        try {
            window.localStorage.setItem(key, value);
            return true;
        } catch (error) {
            return false;
        }
    }

    function removeStoredItem(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {}
    }

    function isValidAction(action) {
        return validActions.indexOf(action) !== -1;
    }

    function normalizeCount(value) {
        var count = parseInt(value, 10);
        return isFinite(count) && count > 0 ? count : 0;
    }

    function normalizeState(rawState) {
        var normalized = emptyState();
        var state = rawState && typeof rawState === 'object' ? rawState : {};
        var i;

        for (i = 0; i < validActions.length; i++) {
            var action = validActions[i];
            normalized.usage[action] = normalizeCount(state.usage && state.usage[action]);
            normalized.pending[action] = !!(state.pending && state.pending[action]);
            normalized.shown[action] = !!(state.shown && state.shown[action]);
            normalized.dismissed[action] = !!(state.dismissed && state.dismissed[action]);
        }

        if (Array.isArray(state.seenOperations)) {
            normalized.seenOperations = state.seenOperations
                .filter(function(operation) {
                    return typeof operation === 'string' && operation.length > 0 && operation.length <= 100;
                })
                .slice(-seenLimit);
        }

        return normalized;
    }

    function readState() {
        var stored = getStoredItem(storageKey);
        if (!stored) {
            return emptyState();
        }

        try {
            return normalizeState(JSON.parse(stored));
        } catch (error) {
            return emptyState();
        }
    }

    function saveState(state) {
        setStoredItem(storageKey, JSON.stringify(normalizeState(state)));
    }

    function cleanupLegacyState() {
        legacyKeys.forEach(function(key) {
            removeStoredItem(key);
        });
    }

    function actionFromBody(body) {
        if (!body) {
            return '';
        }

        if (typeof body === 'string') {
            try {
                return new URLSearchParams(body).get('action') || '';
            } catch (error) {
                var match = body.match(/(?:^|&)action=([^&]+)/);
                return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
            }
        }

        if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
            return body.get('action') || '';
        }

        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            return body.get('action') || '';
        }

        return '';
    }

    function requestAction(settings) {
        var data = settings && settings.data;

        if (data && typeof data === 'object' && typeof data.action === 'string') {
            return data.action;
        }

        return actionFromBody(data);
    }

    function responsePayload(xhr, data) {
        if (data && typeof data === 'object') {
            return data;
        }

        if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
            return xhr.responseJSON;
        }

        if (xhr && typeof xhr.responseText === 'string') {
            try {
                return JSON.parse(xhr.responseText);
            } catch (error) {}
        }

        return null;
    }

    function thresholdFor(action) {
        var thresholds = config.thresholds || {};
        var threshold = parseInt(thresholds[action], 10);
        return isFinite(threshold) && threshold > 0 ? threshold : 0;
    }

    function operationAlreadySeen(state, operationKey) {
        return state.seenOperations.indexOf(operationKey) !== -1;
    }

    function recordSuccessfulOperation(action, operationId) {
        if (!isValidAction(action) || typeof operationId !== 'string' || !operationId) {
            return;
        }

        var state = readState();
        var operationKey = action + ':' + operationId;
        if (operationAlreadySeen(state, operationKey)) {
            return;
        }

        state.seenOperations.push(operationKey);
        state.seenOperations = state.seenOperations.slice(-seenLimit);
        state.usage[action] = normalizeCount(state.usage[action]) + 1;

        var threshold = thresholdFor(action);
        if (threshold > 0 && state.usage[action] >= threshold && !state.shown[action] && !state.dismissed[action]) {
            state.pending[action] = true;
        }

        saveState(state);
    }

    function nextPendingAction(state) {
        var i;
        for (i = 0; i < validActions.length; i++) {
            var action = validActions[i];
            if (state.pending[action] && !state.shown[action] && !state.dismissed[action]) {
                return action;
            }
        }
        return '';
    }

    function renderPendingTip() {
        if (!config.productUrl || !config.actionTips || $('.wcos-post-action-tip').length) {
            return;
        }

        var state = readState();
        var action = nextPendingAction(state);
        if (!action || !config.actionTips[action]) {
            return;
        }

        var $insertionPoint = $('#woocommerce-order-data').first();
        if (!$insertionPoint.length) {
            return;
        }

        state.pending[action] = false;
        state.shown[action] = true;
        saveState(state);

        var $notice = $('<div>', {
            'class': 'notice notice-info wcos-post-action-tip',
            'data-wcos-upsell-action': action
        });
        var $paragraph = $('<p>');
        $paragraph.append(document.createTextNode(config.actionTips[action] + ' '));
        $('<a>', {
            href: config.productUrl,
            target: '_blank',
            rel: 'noopener noreferrer',
            text: config.ctaLabel || 'Explore Advanced Order Actions'
        }).appendTo($paragraph);
        $notice.append($paragraph);

        $('<button>', {
            type: 'button',
            'class': 'notice-dismiss',
            'aria-label': config.dismissLabel || 'Dismiss'
        }).append($('<span>', {
            'class': 'screen-reader-text',
            text: config.dismissLabel || 'Dismiss'
        })).appendTo($notice);

        $notice.on('click', '.notice-dismiss', function() {
            var current = readState();
            current.dismissed[action] = true;
            current.pending[action] = false;
            current.shown[action] = true;
            saveState(current);
            $notice.remove();
        });

        $insertionPoint.before($notice);
    }

    function renderSplitHint() {
        if (!config.productUrl || !config.splitHint || $('.wcos-split-upgrade-hint').length) {
            return;
        }

        var $anchor = $('.wcos-split-launcher').last();
        if (!$anchor.length) {
            return;
        }

        var $hint = $('<p>', {
            'class': 'description wcos-split-upgrade-hint'
        });
        $hint.append(document.createTextNode(config.splitHint + ' '));
        $('<a>', {
            href: config.productUrl,
            target: '_blank',
            rel: 'noopener noreferrer',
            text: config.splitHintCta || 'See advanced split methods'
        }).appendTo($hint);

        $anchor.after($hint);
    }

    cleanupLegacyState();

    $(function() {
        // Promotions are rendered from state that existed before this page's AJAX work.
        // A success recorded later in this page lifecycle can only surface on a later load.
        renderPendingTip();
        renderSplitHint();
    });

    function observePayload(action, payload) {
        if (!isValidAction(action) || !payload || payload.success !== true || !payload.data || typeof payload.data.operation_id !== 'string') {
            return;
        }

        recordSuccessfulOperation(action, payload.data.operation_id);
    }

    if (window.wcosPremiumUpsellTestHooks && typeof window.wcosPremiumUpsellTestHooks === 'object') {
        window.wcosPremiumUpsellTestHooks.readState = readState;
        window.wcosPremiumUpsellTestHooks.recordSuccessfulOperation = recordSuccessfulOperation;
        window.wcosPremiumUpsellTestHooks.nextPendingAction = nextPendingAction;
        window.wcosPremiumUpsellTestHooks.observePayload = observePayload;
    }

    function installFetchObserver() {
        if (typeof window.fetch !== 'function' || window.fetch.__wcosPremiumUpsellObserved) {
            return;
        }

        var originalFetch = window.fetch;
        var observedFetch = function(input, init) {
            var actionMap = config.executeActions || {};
            var actionName = actionFromBody(init && init.body);
            var action = actionMap[actionName];

            var requestPromise = originalFetch.apply(this, arguments);
            if (!isValidAction(action)) {
                return requestPromise;
            }

            return requestPromise.then(function(response) {
                if (response && typeof response.clone === 'function') {
                    response.clone().json().then(function(payload) {
                        observePayload(action, payload);
                    }).catch(function() {});
                }
                return response;
            });
        };

        observedFetch.__wcosPremiumUpsellObserved = true;
        observedFetch.__wcosPremiumUpsellOriginal = originalFetch;
        window.fetch = observedFetch;
    }

    installFetchObserver();

    $(document).ajaxSuccess(function(event, xhr, settings, data) {
        var actionMap = config.executeActions || {};
        var action = actionMap[requestAction(settings)];
        observePayload(action, responsePayload(xhr, data));
    });
})(jQuery);
