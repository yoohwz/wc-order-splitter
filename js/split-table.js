jQuery(function($) {
    'use strict';

    var state = {
        items: [],
        mode: 'default',
        preview: null,
        splitData: {}
    };

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function orderId() {
        return parseInt($('#split-order-container').data('order-id'), 10) || 0;
    }

    function responseMessage(response, fallback) {
        if (response && response.data && typeof response.data === 'object' && response.data.message) {
            return response.data.message;
        }
        if (response && typeof response.data === 'string') {
            return response.data;
        }
        return fallback;
    }

    function announce(message, type) {
        var $status = $('#wc-order-splitter-status');
        if (!$status.length) {
            return;
        }
        $status.attr('class', type === 'error' ? 'notice notice-error inline' : 'notice notice-info inline');
        $status.html('<p>' + escapeHtml(message) + '</p>');
    }

    function setExpanded(expanded) {
        $('.split-order').attr('aria-expanded', expanded ? 'true' : 'false');
        $('#split-order-container').prop('hidden', !expanded);
    }

    function loadItems() {
        announce(splitOrderTranslations.loadingItems, 'info');
        return $.post(splitOrderTranslations.ajaxUrl, {
            action: 'get_order_items',
            order_id: orderId(),
            nonce: splitOrderTranslations.nonce
        }).done(function(response) {
            if (!response.success) {
                announce(responseMessage(response, splitOrderTranslations.unableToFetch), 'error');
                return;
            }
            state.items = response.data || [];
            renderEditor();
        }).fail(function() {
            announce(splitOrderTranslations.errorOccurredFetchingOrder, 'error');
        });
    }

    function renderShell() {
        var html = '';
        html += '<div id="wc-order-splitter-status" class="notice notice-info inline" aria-live="polite"><p>' + escapeHtml(splitOrderTranslations.loadingItems) + '</p></div>';
        html += '<div id="wc-order-splitter-editor"></div>';
        html += '<div id="wc-order-splitter-preview" aria-live="polite"></div>';
        $('#split-order-container').html(html);
    }

    function renderEditor() {
        var showCategory = state.mode === 'category';
        var showStock = state.mode === 'stock-status';
        var disabled = state.mode !== 'default';
        var html = '<table class="widefat striped wc-order-splitter-table"><thead><tr>';
        html += '<th scope="col">' + escapeHtml(splitOrderTranslations.product) + '</th>';
        if (showCategory) {
            html += '<th scope="col">' + escapeHtml(splitOrderTranslations.category) + '</th>';
        }
        if (showStock) {
            html += '<th scope="col">' + escapeHtml(splitOrderTranslations.stockStatus) + '</th>';
        }
        html += '<th scope="col">' + escapeHtml(splitOrderTranslations.order) + '</th>';
        html += '<th scope="col">' + escapeHtml(splitOrderTranslations.quantity) + '</th>';
        html += '<th scope="col">' + escapeHtml(splitOrderTranslations.splitQuantity) + '</th></tr></thead><tbody>';

        $.each(state.items, function(index, item) {
            var itemId = parseInt(item.id, 10) || 0;
            var qty = parseFloat(item.quantity) || 0;
            var inputId = 'wc-order-splitter-qty-' + itemId;
            var orderSelectId = 'wc-order-splitter-destination-' + itemId;
            html += '<tr>';
            html += '<th scope="row">' + escapeHtml(item.name) + '</th>';
            if (showCategory) {
                html += '<td>' + escapeHtml(item.category) + '</td>';
            }
            if (showStock) {
                html += '<td>' + escapeHtml(item.stock_status) + '</td>';
            }
            html += '<td><label class="screen-reader-text" for="' + orderSelectId + '">' + escapeHtml(splitOrderTranslations.order) + '</label>';
            html += '<select id="' + orderSelectId + '" name="split_order[' + itemId + ']"' + (disabled ? ' disabled' : '') + '>';
            for (var i = 1; i <= 10; i++) {
                html += '<option value="child-' + i + '">' + escapeHtml(splitOrderTranslations.newOrder + i) + '</option>';
            }
            html += '</select></td>';
            html += '<td>' + escapeHtml(item.quantity) + '</td>';
            html += '<td><label class="screen-reader-text" for="' + inputId + '">' + escapeHtml(splitOrderTranslations.splitQuantity + ': ' + item.name) + '</label>';
            html += '<input id="' + inputId + '" type="number" step="any" min="0" max="' + escapeHtml(qty) + '" name="split_quantity[' + itemId + ']"' + (disabled ? ' disabled' : '') + '></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';

        html += '<div class="wcos-split-toolbar">';
        html += '<label for="split-method">' + escapeHtml(splitOrderTranslations.splitMethod) + '</label> ';
        html += '<select id="split-method" class="split-method">';
        html += '<option value="default">' + escapeHtml(splitOrderTranslations.default) + '</option>';
        html += '<option value="category">' + escapeHtml(splitOrderTranslations.category) + '</option>';
        html += '<option value="stock-status">' + escapeHtml(splitOrderTranslations.stockStatus) + '</option>';
        html += '</select> ';
        html += '<button type="button" class="button button-primary wc-order-splitter-preview-button">' + escapeHtml(splitOrderTranslations.previewSplit) + '</button> ';
        html += '<button type="button" class="button wc-order-splitter-cancel">' + escapeHtml(splitOrderTranslations.cancel) + '</button>';
        html += '</div>';

        $('#wc-order-splitter-editor').html(html);
        $('#split-method').val(state.mode);
        $('#wc-order-splitter-preview').empty();
        state.preview = null;
        announce(splitOrderTranslations.previewRequired, 'info');
    }

    function collectSplitData() {
        var data = {};
        if (state.mode !== 'default') {
            return data;
        }
        $('#wc-order-splitter-editor input[name^="split_quantity"]').each(function() {
            var match = String($(this).attr('name')).match(/\[(\d+)\]/);
            if (!match) {
                return;
            }
            var itemId = match[1];
            var quantity = parseFloat($(this).val());
            if (!(quantity > 0)) {
                return;
            }
            data[itemId] = {
                quantity: quantity,
                order: $('select[name="split_order[' + itemId + ']"]').val()
            };
        });
        return data;
    }

    function previewSplit() {
        var $button = $('.wc-order-splitter-preview-button');
        state.splitData = collectSplitData();
        $button.prop('disabled', true).text(splitOrderTranslations.previewing);
        announce(splitOrderTranslations.previewing, 'info');

        $.post(splitOrderTranslations.ajaxUrl, {
            action: 'preview_order_split',
            order_id: orderId(),
            split_mode: state.mode,
            split_data: state.splitData,
            nonce: splitOrderTranslations.nonce
        }).done(function(response) {
            if (!response.success) {
                announce(responseMessage(response, splitOrderTranslations.failedToSplitOrder), 'error');
                return;
            }
            state.preview = response.data;
            renderPreview(response.data);
        }).fail(function() {
            announce(splitOrderTranslations.errorOccurred, 'error');
        }).always(function() {
            $button.prop('disabled', false).text(splitOrderTranslations.previewSplit);
        });
    }

    function renderPreview(preview) {
        var html = '<div class="wc-order-splitter-preview-card">';
        html += '<h3>' + escapeHtml(splitOrderTranslations.previewTitle) + '</h3>';
        html += '<p>' + escapeHtml(splitOrderTranslations.previewPolicy + ': ' + preview.policies.shipping_policy) + '</p>';
        $.each(preview.destinations || {}, function(destination, data) {
            html += '<section><h4>' + escapeHtml(destination) + '</h4><ul>';
            $.each(data.items || [], function(index, item) {
                html += '<li>' + escapeHtml(item.name + ' × ' + item.quantity) + '</li>';
            });
            html += '</ul><p>' + escapeHtml(splitOrderTranslations.previewAmount + ': ' + data.total + ' ' + preview.currency) + '</p></section>';
        });
        html += '<p><strong>' + escapeHtml(splitOrderTranslations.confirmWarning) + '</strong></p>';
        html += '<button type="button" class="button button-primary wc-order-splitter-confirm">' + escapeHtml(splitOrderTranslations.confirmSplit) + '</button> ';
        html += '<button type="button" class="button wc-order-splitter-cancel-preview">' + escapeHtml(splitOrderTranslations.changeAllocation) + '</button>';
        html += '</div>';
        $('#wc-order-splitter-preview').html(html);
        announce(splitOrderTranslations.previewReady, 'info');
        $('.wc-order-splitter-confirm').trigger('focus');
    }

    function confirmSplit() {
        if (!state.preview) {
            announce(splitOrderTranslations.previewRequired, 'error');
            return;
        }
        var action = state.mode === 'category' ? 'split_order_by_category' : (state.mode === 'stock-status' ? 'split_order_by_stock_status' : 'split_order');
        var $button = $('.wc-order-splitter-confirm');
        $button.prop('disabled', true).text(splitOrderTranslations.splitting);

        $.post(splitOrderTranslations.ajaxUrl, {
            action: action,
            order_id: orderId(),
            split_mode: state.mode,
            split_data: state.splitData,
            nonce: splitOrderTranslations.nonce,
            confirm_nonce: state.preview.confirm_nonce,
            idempotency_key: state.preview.idempotency_key
        }).done(function(response) {
            if (!response.success) {
                announce(responseMessage(response, splitOrderTranslations.failedToSplitOrder), 'error');
                $button.prop('disabled', false).text(splitOrderTranslations.confirmSplit);
                return;
            }
            try {
                window.localStorage.setItem('wcosPostActionTip', JSON.stringify({
                    action: 'split',
                    message: splitOrderTranslations.orderSplitSuccess + ' ' + (response.data.new_order_ids || []).join(', ')
                }));
            } catch (error) {}
            window.location.reload();
        }).fail(function() {
            announce(splitOrderTranslations.errorOccurred, 'error');
            $button.prop('disabled', false).text(splitOrderTranslations.confirmSplit);
        });
    }

    $(document).on('click', '.split-order', function() {
        var expanded = $(this).attr('aria-expanded') === 'true';
        if (expanded) {
            setExpanded(false);
            return;
        }
        setExpanded(true);
        renderShell();
        loadItems();
    });

    $(document).on('change', '#split-method', function() {
        state.mode = $(this).val();
        renderEditor();
    });

    $(document).on('click', '.wc-order-splitter-preview-button', previewSplit);
    $(document).on('click', '.wc-order-splitter-confirm', confirmSplit);
    $(document).on('click', '.wc-order-splitter-cancel-preview', function() {
        state.preview = null;
        $('#wc-order-splitter-preview').empty();
        announce(splitOrderTranslations.previewRequired, 'info');
        $('.wc-order-splitter-preview-button').trigger('focus');
    });
    $(document).on('click', '.wc-order-splitter-cancel', function() {
        setExpanded(false);
        $('.split-order').trigger('focus');
    });
});
