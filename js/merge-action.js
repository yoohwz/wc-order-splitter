jQuery(document).ready(function ($) {
    // Listen for changes on the wc_order_action dropdown
    $(document).on('change', 'select[name="wc_order_action"]', function () {
        var selectedAction = $(this).val();

        // Check if the selected action is "Merge this order to..."
        if (selectedAction === 'yoos_merge_order') {
            // Prompt the user to enter the order ID to merge into
            var mergeOrderId = prompt(wc_order_splitter_params.texts.promptMessage);

            if (mergeOrderId === null) {
                $(this).val(''); // Reset dropdown
            } else if (mergeOrderId && $.isNumeric(mergeOrderId)) {
                $.post(wc_order_splitter_params.ajax_url, {
                    action: 'yoos_merge_order_action',
                    order_id: wc_order_splitter_params.order_id,
                    merge_order_id: mergeOrderId,
                    nonce: wc_order_splitter_params.nonce
                }, function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        alert(response.data.message || wc_order_splitter_params.texts.errorMessage);
                        $('select[name="wc_order_action"]').val(''); // Reset dropdown
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    alert(wc_order_splitter_params.texts.ajaxFailMessage);
                    $('select[name="wc_order_action"]').val(''); // Reset dropdown
                });
            } else if (mergeOrderId) {
                alert(wc_order_splitter_params.texts.invalidMessage);
                $(this).val(''); // Reset dropdown
            }
        }
    });
});
