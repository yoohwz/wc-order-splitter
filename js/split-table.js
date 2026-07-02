jQuery(document).ready(function($) {
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

    function buildExternalLink(url, label, className) {
        return '<a href="' + escapeHtmlAttr(url) + '" class="' + escapeHtmlAttr(className || '') + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label) + '</a>';
    }

    function showPostSplitTip() {
        var storedTip = null;

        try {
            storedTip = window.localStorage.getItem('wcosPostSplitTip');
            window.localStorage.removeItem('wcosPostSplitTip');
        } catch (error) {
            storedTip = null;
        }

        if (!storedTip || $('.wcos-post-split-tip').length) {
            return;
        }

        var tip = '<div class="notice notice-success inline wcos-post-split-tip">' +
            '<p>' + escapeHtml(storedTip) + ' ' +
            buildExternalLink(splitOrderTranslations.premiumUrl, splitOrderTranslations.learnMore, '') +
            '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + escapeHtml(splitOrderTranslations.dismiss) + '</span></button>' +
            '</div>';

        $('#split-order-container').before(tip);
    }

    showPostSplitTip();

    $(document).on('click', '.wcos-post-split-tip .notice-dismiss', function() {
        $(this).closest('.wcos-post-split-tip').remove();
    });

    $('.split-order').on('click', function() {
        var orderId = woocommerce_admin_meta_boxes.post_id;

        // Toggle the display of the split order container
        var container = $('#split-order-container');
        if (container.is(':visible')) {
            container.hide();
            return;
        }

        // AJAX request to get order items
        $.ajax({
            url: splitOrderTranslations.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_order_items',
                order_id: orderId,
                nonce: splitOrderTranslations.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayOrderItems(response.data, container);
                } else {
                    alert(splitOrderTranslations.unableToFetch);
                }
            },
            error: function() {
                alert(splitOrderTranslations.errorOccurredFetchingOrder);
            }
        });
    });

    function displayOrderItems(items, container) {
        var splitMethod = $('#split-method').val() || 'default'; // Default value
        var showCategory = splitMethod === 'category';
        var showStockStatus = splitMethod === 'stock-status';
    
        var html = '<table class="woocommerce_order_items">';
        html += '<thead><tr><th class="product-column">' + escapeHtml(splitOrderTranslations.product) + '</th>';
        if (showCategory) {
            html += '<th class="category-column">' + escapeHtml(splitOrderTranslations.category) + '</th>';
        }
        if (showStockStatus) {
            html += '<th class="stock-status-column">' + escapeHtml(splitOrderTranslations.stockStatus) + '</th>';
        }
        html += '<th class="order-column">' + escapeHtml(splitOrderTranslations.order) + '</th><th class="quantity-column">' + escapeHtml(splitOrderTranslations.quantity) + '</th><th class="split-quantity-column">' + escapeHtml(splitOrderTranslations.splitQuantity) + '</th></tr></thead><tbody>';
    
        $.each(items, function(index, item) {
            var itemId = parseInt(item.id, 10) || 0;
            var itemQuantity = parseInt(item.quantity, 10) || 0;

            html += '<tr>';
            html += '<td>' + escapeHtml(item.name) + '</td>';
            if (showCategory) {
                html += '<td>' + escapeHtml(item.category) + '</td>';
            }
            if (showStockStatus) {
                html += '<td>' + escapeHtml(item.stock_status) + '</td>';
            }
            html += '<td><select name="split_order[' + itemId + ']"' + (splitMethod !== 'default' ? ' disabled' : '') + '>';
            for (var i = 1; i <= 10; i++) {
                html += '<option value="' + escapeHtmlAttr(splitOrderTranslations.newOrder + i) + '">' + escapeHtml(splitOrderTranslations.newOrder + i) + '</option>';
            }
            html += '</select></td>';
            html += '<td>' + itemQuantity + '</td>';
            html += '<td><input type="number" name="split_quantity[' + itemId + ']" min="0" max="' + itemQuantity + '"' + (splitMethod !== 'default' ? ' disabled' : '') + '></td>';
            html += '</tr>';
        });
    
        html += '</tbody></table>';
        html += '<div class="wcos-split-toolbar">';
        html += '<button type="button" class="button button-secondary cancel-split-order">' + escapeHtml(splitOrderTranslations.cancel) + '</button>';
        html += '<select id="split-method" class="split-method" aria-describedby="wcos-split-mode-hint">';
        html += '<option value="default">' + escapeHtml(splitOrderTranslations.default) + '</option>';
        html += '<option value="unit" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.unit) + '</option>';
        html += '<option value="group" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.group) + '</option>';
        html += '<option value="ingroup" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.inGroup) + '</option>';
        html += '<option value="nongroup" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.nonGroup) + '</option>';
        html += '<option value="category">' + escapeHtml(splitOrderTranslations.category) + '</option>';
        html += '<option value="stock-status">' + escapeHtml(splitOrderTranslations.stockStatus) + '</option>';
        html += '<option value="tags" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.tag) + '</option>';
        html += '<option value="attribute" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.attribute) + '</option>';
        html += '<option value="bundle" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.bundle) + '</option>';
        html += '<option value="vendor" disabled title="' + escapeHtmlAttr(splitOrderTranslations.premiumModeHint) + '">' + escapeHtml(splitOrderTranslations.vendor) + '</option>';
        html += '</select>';
        html += '<button type="button" class="button button-primary split-order-confirm">' + escapeHtml(splitOrderTranslations.splitIt) + '</button>';
        html += '</div>';
        html += '<div id="wcos-split-mode-hint" class="wcos-limit-state">' +
            '<strong>' + escapeHtml(splitOrderTranslations.premiumModeHintTitle) + '</strong> ' +
            escapeHtml(splitOrderTranslations.premiumModeHint) + ' ' +
            buildExternalLink(splitOrderTranslations.premiumUrl, splitOrderTranslations.learnMore, '') +
            '</div>';
    
        // Populate the container with the table and show it
        container.html(html).show();
    
        // Set the dropdown to the previously selected value
        $('#split-method').val(splitMethod);
    }

    // Event handler to disable/enable split quantity and order fields based on split method selection
    $(document).on('change', '#split-method', function() {
        var splitMethod = $(this).val();
        if (splitMethod !== 'default') {
            $('#split-order-container input[name^="split_quantity"]').prop('disabled', true);
            $('#split-order-container select[name^="split_order"]').prop('disabled', true);
        } else {
            $('#split-order-container input[name^="split_quantity"]').prop('disabled', false);
            $('#split-order-container select[name^="split_order"]').prop('disabled', false);
        }

        // Re-display order items to add/remove the category or stock status column
        var orderId = woocommerce_admin_meta_boxes.post_id;
        $.ajax({
            url: splitOrderTranslations.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_order_items',
                order_id: orderId,
                nonce: splitOrderTranslations.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayOrderItems(response.data, $('#split-order-container'));
                } else {
                    alert(splitOrderTranslations.unableToFetch);
                }
            },
            error: function() {
                alert(splitOrderTranslations.errorOccurredFetchingOrder);
            }
        });
    });

    // Event handler for the Cancel button
    $(document).on('click', '.cancel-split-order', function() {
        $('#split-order-container').hide();
    });
        
    // Event handler for 'Split it' button
    $(document).on('click', '.split-order-confirm', function() {
        var splitMethod = $('#split-method').val();
        var action = splitMethod === 'category' ? 'split_order_by_category' : (splitMethod === 'stock-status' ? 'split_order_by_stock_status' : 'split_order');
        var $button = $(this);
        var originalButtonText = $button.text();

        // Update button text to "Splitting..." and disable the button
        $button.text(splitOrderTranslations.splitting).prop('disabled', true);

        var orderId = woocommerce_admin_meta_boxes.post_id;
        var splitData = {};

        $('#split-order-container input[name^="split_quantity"]').each(function() {
            var itemId = $(this).attr('name').match(/\[(\d+)\]/)[1];
            var quantity = parseInt($(this).val(), 10);
            var selectedOrder = $('select[name="split_order[' + itemId + ']"]').val();

            if (quantity > 0) {
                splitData[itemId] = {
                    quantity: quantity,
                    order: selectedOrder
                };
            }
        });

        // AJAX request to create a new order with the split items
        $.ajax({
            url: splitOrderTranslations.ajaxUrl,
            type: 'POST',
            data: {
                action: action,
                order_id: orderId,
                split_data: splitData,
                nonce: splitOrderTranslations.nonce
            },
            success: function(response) {
                if (response.success) {
                    try {
                        window.localStorage.setItem(
                            'wcosPostActionTip',
                            JSON.stringify({
                                action: 'split',
                                message: splitOrderTranslations.orderSplitSuccess + ' ' + response.data.new_order_ids.join(', ') + '. ' + splitOrderTranslations.splitSuccessTip
                            })
                        );
                    } catch (error) {}
                    window.location.reload();
                } else {
                    alert(response.data || splitOrderTranslations.failedToSplitOrder);
                }
                $button.text(originalButtonText).prop('disabled', false);
            },
            error: function() {
                alert(splitOrderTranslations.errorOccurred);
                $button.text(originalButtonText).prop('disabled', false);
            }
        });
    });
});
