jQuery(document).ready(function($) {
    function setReturnTip(message) {
        if (!window.wcosPostActionTip || !window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem('wcosPostActionTip', JSON.stringify({
                action: 'return',
                message: window.wcosPostActionTip.actionTips.return || message
            }));
        } catch (error) {}
    }

    $('<option>').val('return_to_original_order').text(splitOrderTranslations.returnToOriginalOrder).appendTo("select[name='action']");
    $('<option>').val('return_to_original_order').text(splitOrderTranslations.returnToOriginalOrder).appendTo("select[name='action2']");

    $(document).on('click', '#doaction, #doaction2', function(e) {
        var action = $(this).closest('form').find('select[name="action"]').val() || $(this).closest('form').find('select[name="action2"]').val();
        if (action === 'return_to_original_order') {
            e.preventDefault();

            var selectedOrders = [];
            $('input[type="checkbox"][name$="[]"]:checked').each(function() {
                selectedOrders.push($(this).val());
            });

            if (selectedOrders.length > 0) {
                $.ajax({
                    url: splitOrderTranslations.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'yoos_handle_bulk_action',
                        doaction: 'return_to_original_order',
                        order_ids: selectedOrders,
                        security: splitOrderTranslations.bulkReturnOrderNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            setReturnTip(response.data);
                            location.reload(); // Optionally reload the page to reflect changes
                        } else {
                            alert(response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                        console.error(xhr.responseText);
                    }
                });
            } else {
                alert(splitOrderTranslations.pleaseSelectAtLeastOneOrder);
            }
        }
    });
});
