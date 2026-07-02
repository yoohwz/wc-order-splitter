jQuery(document).ready(function($) {
    var storageKey = 'wcosPostActionTip';
    var legacySplitKey = 'wcosPostSplitTip';
    var dismissedKey = 'wcosPostActionTipDismissedAt';
    var throttleMs = 7 * 24 * 60 * 60 * 1000;

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeHtmlAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
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
        } catch (error) {}
    }

    function removeStoredItem(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {}
    }

    function recentlyDismissed() {
        var dismissedAt = parseInt(getStoredItem(dismissedKey), 10);

        return dismissedAt && Date.now() - dismissedAt < throttleMs;
    }

    function getTipFromStorage() {
        var storedTip = getStoredItem(storageKey);
        var legacyTip = getStoredItem(legacySplitKey);

        removeStoredItem(storageKey);
        removeStoredItem(legacySplitKey);

        if (storedTip) {
            try {
                return JSON.parse(storedTip);
            } catch (error) {
                return {
                    action: 'custom',
                    message: storedTip
                };
            }
        }

        if (legacyTip) {
            return {
                action: 'split',
                message: legacyTip
            };
        }

        return null;
    }

    function getTipFromUrl() {
        var params = new URLSearchParams(window.location.search);
        var action = params.get('wcos_action_tip');

        if (!action || !window.wcosPostActionTip || !window.wcosPostActionTip.actionTips || !window.wcosPostActionTip.actionTips[action]) {
            return null;
        }

        params.delete('wcos_action_tip');

        var newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
        window.history.replaceState({}, document.title, newUrl);

        return {
            action: action,
            message: window.wcosPostActionTip.actionTips[action]
        };
    }

    function getInsertionPoint() {
        var $orderData = $('#woocommerce-order-data');
        var $wpbody = $('#wpbody-content .wrap').first();

        if ($orderData.length) {
            return $orderData;
        }

        if ($wpbody.length) {
            return $wpbody;
        }

        return $('#wpbody-content').first();
    }

    function renderTip(tip) {
        if (!tip || !tip.message || $('.wcos-post-action-tip').length || recentlyDismissed()) {
            return;
        }

        var html = '<div class="notice notice-success is-dismissible wcos-post-action-tip">' +
            '<p>' +
            escapeHtml(tip.message) + ' ' +
            '<a href="' + escapeHtmlAttr(window.wcosPostActionTip.premiumUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(window.wcosPostActionTip.comparePremium) + '</a>' +
            '</p>' +
            '</div>';

        getInsertionPoint().before(html);
    }

    $(document).on('click', '.wcos-post-action-tip .notice-dismiss', function() {
        setStoredItem(dismissedKey, String(Date.now()));
    });

    renderTip(getTipFromUrl() || getTipFromStorage());
});
