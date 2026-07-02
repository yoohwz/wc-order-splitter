jQuery(document).ready(function($) {
    // Function to process and style order rows
    function processOrderRows() {
        $('tr.type-shop_order').each(function() {
            var orderId;

            // Check for HPOS or legacy ID format
            if ($(this).attr('id').startsWith('post-')) {
                orderId = $(this).attr('id').replace('post-', '');
            } else if ($(this).attr('id').startsWith('order-')) {
                orderId = $(this).attr('id').replace('order-', '');
            }

            // Check if orderId is a valid number
            if (!isNaN(orderId)) {
                // Normalize order ID to integer for matching
                orderId = parseInt(orderId, 10);

                // Check if the order ID exists in the localized data
                if (yoohw_order_data.hasOwnProperty(orderId)) {
                    var orderData = yoohw_order_data[orderId];

                    // Add classes and data attributes based on meta value
                    if (orderData.original_order) {
                        $(this).find('a.order-view')
                            .addClass('yoohw-split-order')
                            .attr('data-original-order', orderData.original_order);
                    }

                    if (orderData.splitted_order) {
                        $(this).find('a.order-view')
                            .addClass('yoohw-splitted-order')
                            .attr('data-splitted-order', orderData.splitted_order);
                    }
                }
            }
        });

        // Add custom CSS once
        if (!$('style#yoohw-order-styles').length) {
            $('<style>')
                .attr('id', 'yoohw-order-styles')
                .prop('type', 'text/css')
                .html(`
                    tr.type-shop_order a.order-view.yoohw-split-order::after {
                        content: "O#" attr(data-original-order);
                        font-size: 10px;
                        text-shadow: none;
                        color: #2c4700;
                        background-color: #c6e1c6;
                        padding: 0 5px;
                        margin-left: 5px;
                        border-radius: 5px;
                        display: inline-block;
                    }
                    tr.type-shop_order a.order-view.yoohw-splitted-order::after {
                        content: "S#" attr(data-splitted-order);
                        font-size: 10px;
                        text-shadow: none;
                        color: #ffffff;
                        background-color: #00a32a;
                        padding: 0 5px;
                        margin-left: 5px;
                        border-radius: 5px;
                        display: inline-block;
                    }
                `)
                .appendTo('head');
        }
    }

    // Observe the orders table for dynamically added rows
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                processOrderRows();
            }
        });
    });

    // Start observing the orders table for changes
    var ordersTable = $('#the-list'); // The table containing order rows
    if (ordersTable.length) {
        observer.observe(ordersTable[0], {
            childList: true, // Monitor child nodes for addition/removal
        });
    }

    // Initial processing of existing rows on page load
    processOrderRows();
});
