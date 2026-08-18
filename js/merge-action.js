jQuery(function($) {
    'use strict';

    var preview = null;

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function message(response, fallback) {
        return response && response.data && response.data.message ? response.data.message : fallback;
    }

    function openPanel() {
        preview = null;
        var $panel = $('#wc-order-splitter-merge-panel');
        $panel.prop('hidden', false);
        $panel.find('.wc-order-splitter-merge-result').empty();
        $panel.find('#wc-order-splitter-merge-target').val('').trigger('focus');
    }

    function closePanel() {
        preview = null;
        $('#wc-order-splitter-merge-panel').prop('hidden', true);
        $('select[name="wc_order_action"]').val('');
    }

    function previewMerge() {
        var targetId = parseInt($('#wc-order-splitter-merge-target').val(), 10) || 0;
        var $result = $('.wc-order-splitter-merge-result');
        if (!targetId) {
            $result.html('<div class="notice notice-error inline"><p>' + escapeHtml(wcOrderSplitterMerge.texts.invalid) + '</p></div>');
            return;
        }

        $result.html('<p>' + escapeHtml(wcOrderSplitterMerge.texts.loading) + '</p>');
        $.post(wcOrderSplitterMerge.ajaxUrl, {
            action: 'yoos_merge_order_preview',
            order_id: wcOrderSplitterMerge.orderId,
            merge_order_id: targetId,
            nonce: wcOrderSplitterMerge.nonce
        }).done(function(response) {
            if (!response.success) {
                preview = null;
                $result.html('<div class="notice notice-error inline"><p>' + escapeHtml(message(response, wcOrderSplitterMerge.texts.failed)) + '</p></div>');
                return;
            }
            preview = response.data;
            var html = '<div class="wc-order-splitter-preview-card">';
            html += '<p><strong>' + escapeHtml(wcOrderSplitterMerge.texts.source) + ':</strong> #' + escapeHtml(preview.source_order_number) + ' — ' + escapeHtml(preview.source_total + ' ' + preview.currency) + '</p>';
            html += '<p><strong>' + escapeHtml(wcOrderSplitterMerge.texts.target) + ':</strong> #' + escapeHtml(preview.target_order_number) + ' — ' + escapeHtml(preview.target_total + ' ' + preview.currency) + '</p>';
            html += '<p><strong>' + escapeHtml(wcOrderSplitterMerge.texts.combinedTotal) + ':</strong> ' + escapeHtml(preview.combined_total + ' ' + preview.currency) + '</p>';
            html += '<button type="button" class="button button-primary wc-order-splitter-merge-confirm">' + escapeHtml(wcOrderSplitterMerge.texts.confirm) + '</button>';
            html += '</div>';
            $result.html(html);
            $('.wc-order-splitter-merge-confirm').trigger('focus');
        }).fail(function() {
            preview = null;
            $result.html('<div class="notice notice-error inline"><p>' + escapeHtml(wcOrderSplitterMerge.texts.failed) + '</p></div>');
        });
    }

    function confirmMerge() {
        if (!preview) {
            return;
        }
        var $button = $('.wc-order-splitter-merge-confirm');
        $button.prop('disabled', true);
        $.post(wcOrderSplitterMerge.ajaxUrl, {
            action: 'yoos_merge_order_action',
            order_id: wcOrderSplitterMerge.orderId,
            merge_order_id: preview.target_order_id,
            nonce: wcOrderSplitterMerge.nonce,
            confirm_nonce: preview.confirm_nonce
        }).done(function(response) {
            if (response.success && response.data.redirect_url) {
                window.location.href = response.data.redirect_url;
                return;
            }
            $('.wc-order-splitter-merge-result').html('<div class="notice notice-error inline"><p>' + escapeHtml(message(response, wcOrderSplitterMerge.texts.failed)) + '</p></div>');
            preview = null;
        }).fail(function() {
            $('.wc-order-splitter-merge-result').html('<div class="notice notice-error inline"><p>' + escapeHtml(wcOrderSplitterMerge.texts.failed) + '</p></div>');
            preview = null;
        });
    }

    $(document).on('change', 'select[name="wc_order_action"]', function() {
        if ($(this).val() === 'yoos_merge_order') {
            openPanel();
        }
    });
    $(document).on('click', '.wc-order-splitter-merge-preview', previewMerge);
    $(document).on('click', '.wc-order-splitter-merge-confirm', confirmMerge);
    $(document).on('click', '.wc-order-splitter-merge-cancel', closePanel);
    $(document).on('input', '#wc-order-splitter-merge-target', function() {
        preview = null;
        $('.wc-order-splitter-merge-result').empty();
    });
});
