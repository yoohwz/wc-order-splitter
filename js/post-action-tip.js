(function() {
    'use strict';

    var config = window.wcosPremiumUpsell || {};
    // Keep V1 dismissals/shown campaigns; do not replay its later-page notices.
    var storageKey = 'wcosPremiumUpsellStateV1';
    var validActions = ['split', 'duplicate', 'merge', 'return'];
    var seenLimit = 40;
    var memoryState = null;
    var storageFailed = false;
    var resultSelectors = {
        split: '.wcos-split-result, .wcos-strategy-result',
        duplicate: '.wcos-duplicate-result',
        merge: '.wcos-merge-result',
        return: '.wcos-return-result'
    };

    function thresholdFor(action) {
        var threshold = parseInt((config.thresholds || {})[action], 10);
        return isFinite(threshold) && threshold > 0 ? Math.min(threshold, 3) : 0;
    }

    function emptyState() {
        return {
            usage: { split: 0, duplicate: 0, merge: 0, return: 0 },
            seenOperations: [],
            shown: {},
            dismissed: {},
            hints: { splitRoutingDismissed: false }
        };
    }

    function normalizeState(rawState) {
        var normalized = emptyState();
        var state = rawState && typeof rawState === 'object' ? rawState : {};
        validActions.forEach(function(action) {
            var count = parseInt((state.usage || {})[action], 10);
            normalized.usage[action] = isFinite(count) && count > 0 ? Math.min(count, thresholdFor(action)) : 0;
            normalized.shown[action] = !!(state.shown && state.shown[action]);
            normalized.dismissed[action] = !!(state.dismissed && state.dismissed[action]);
        });
        if (Array.isArray(state.seenOperations)) {
            normalized.seenOperations = state.seenOperations.filter(function(operation, index, operations) {
                return typeof operation === 'string' && /^(split|duplicate|merge|return):[a-z0-9_-]{1,100}$/.test(operation) && operations.indexOf(operation) === index;
            }).slice(-seenLimit);
        }
        normalized.hints.splitRoutingDismissed = !!(state.hints && state.hints.splitRoutingDismissed);
        return normalized;
    }

    function readState() {
        if (storageFailed) {
            return normalizeState(memoryState);
        }
        try {
            var stored = window.localStorage.getItem(storageKey);
            return stored ? normalizeState(JSON.parse(stored)) : normalizeState(memoryState);
        } catch (error) {
            storageFailed = true;
            return normalizeState(memoryState);
        }
    }

    function saveState(state) {
        memoryState = normalizeState(state);
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(memoryState));
        } catch (error) { storageFailed = true; }
    }

    function recordSuccessfulOperation(action, operationId) {
        var state = readState();
        var operationKey = action + ':' + operationId;
        if (state.seenOperations.indexOf(operationKey) !== -1) {
            return false;
        }
        // Saturating counts retain the IDs needed to reach each threshold.
        // Later traffic cannot evict those IDs and inflate a campaign count.
        if (state.usage[action] < thresholdFor(action)) {
            state.seenOperations.push(operationKey);
            state.usage[action] += 1;
            saveState(state);
        }
        return true;
    }

    function eligible(action, state) {
        return thresholdFor(action) > 0 && state.usage[action] >= thresholdFor(action) && !state.shown[action] && !state.dismissed[action];
    }

    function canPresent() {
        return !storageFailed && config.productUrl === 'https://yoohw.com/product/woocommerce-advanced-order-actions/';
    }

    function visibleContent(target) {
        return target && target.isConnected && target.closest('.wcos-admin-backbone-modal__body') &&
            !target.closest('footer, [hidden], [aria-hidden="true"], [aria-busy="true"]');
    }

    function createCard(message, className, dismiss) {
        var card = document.createElement('aside');
        card.className = 'wcos-modal-upsell ' + className;
        var paragraph = document.createElement('p');
        paragraph.textContent = message;
        card.appendChild(paragraph);
        var link = document.createElement('a');
        link.href = config.productUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = config.ctaLabel || 'Explore Advanced Order Actions →';
        card.appendChild(link);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button-link wcos-modal-upsell-dismiss';
        button.textContent = config.dismissLabel || 'Dismiss';
        button.addEventListener('click', function() {
            dismiss();
            var result = card.parentNode;
            card.remove();
            // Do not strand keyboard focus when the focused dismiss button goes away.
            var method = result.querySelector('.wcos-split-method-option');
            (method || result).focus();
        });
        card.appendChild(button);
        return card;
    }

    function completed(event) {
        var detail = event.detail || {};
        var action = detail.action;
        var result = event.target;
        if (validActions.indexOf(action) === -1 || detail.status !== 'completed' ||
            typeof detail.operationId !== 'string' || !/^[a-z0-9_-]{1,100}$/.test(detail.operationId) ||
            !visibleContent(result) || !result.matches(resultSelectors[action]) || !result.firstElementChild) {
            return;
        }
        var root = result.closest('#wc-backbone-modal-dialog');
        if (!root || root.querySelector('[aria-busy="true"]')) {
            return;
        }
        // This event comes only after the action client has rendered its verified
        // result and cleared busy. It carries no order/customer/payment data.
        if (!recordSuccessfulOperation(action, detail.operationId)) {
            return;
        }
        var state = readState();
        if (!canPresent() || !eligible(action, state) || !(config.actionTips || {})[action] || root.querySelector('.wcos-modal-upsell')) {
            return;
        }
        var card = createCard(config.actionTips[action], 'wcos-completed-upsell', function() {
            var latest = readState();
            latest.dismissed[action] = true;
            saveState(latest);
        });
        card.setAttribute('data-wcos-upsell-action', action);
        result.appendChild(card);
        // No page-load advertising: completion consumes this campaign once.
        state.shown[action] = true;
        saveState(state);
    }

    function splitChooser(event) {
        var body = event.target;
        if (!canPresent() || !config.splitHint || !visibleContent(body) ||
            !body.matches('.wcos-admin-backbone-modal__body') || !body.closest('.wcos-split-method-backbone-modal') ||
            !body.querySelector('.wcos-split-method-options') || body.querySelector('.wcos-modal-upsell') ||
            readState().hints.splitRoutingDismissed) {
            return;
        }
        var card = createCard(config.splitHint, 'wcos-split-upgrade-hint', function() {
            var state = readState();
            state.hints.splitRoutingDismissed = true;
            saveState(state);
        });
        var heading = document.createElement('strong');
        heading.textContent = config.splitHintTitle || 'Need more advanced routing?';
        card.insertBefore(heading, card.firstChild);
        body.appendChild(card);
    }

    ['wcosPostActionTip', 'wcosPostSplitTip', 'wcosPostActionTipDismissedAt'].forEach(function(key) {
        try { window.localStorage.removeItem(key); } catch (error) {}
    });
    // If durable local frequency limits are unavailable, omit advertising.
    saveState(readState());

    // Listener failures are isolated from every operational client and control.
    document.addEventListener('wcos:operation-completed', function(event) {
        try { completed(event); } catch (error) {}
    });
    document.addEventListener('wcos:split-method-chooser', function(event) {
        try { splitChooser(event); } catch (error) {}
    });
})();
