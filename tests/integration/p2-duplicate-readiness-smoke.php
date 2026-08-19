<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_duplicate_expect_transport($code, $http_status, callable $callback, $message) {
    try {
        $callback();
    } catch (WCOS_Duplicate_Transport_Exception $exception) {
        wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
        wcos_p2_adapter_assert($http_status === $exception->get_http_status(), $message . ' Wrong HTTP status.');
        return $exception;
    }
    throw new RuntimeException($message);
}

wcos_p2_adapter_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE), 'Duplicate readiness test unexpectedly found the production gate enabled.');

$duplicate_previous_user = get_current_user_id();
$duplicate_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$duplicate_manager_permission = get_option('order_splitter_shop_manager_permission', 'no');
$duplicate_admin_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_duplicate_admin_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-duplicate-admin-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
$duplicate_subscriber_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_duplicate_subscriber_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-duplicate-subscriber-' . wp_generate_uuid4() . '@example.test',
    'role' => 'subscriber',
));
wcos_p2_adapter_assert(!is_wp_error($duplicate_admin_id) && !is_wp_error($duplicate_subscriber_id), 'Unable to create Duplicate readiness test users.');

$duplicate_product = wcos_p2_adapter_product('WCOS P2 hardened Duplicate', '12.34', 40);
list($duplicate_source, $duplicate_item_id) = wcos_p2_adapter_order($duplicate_product, 4, 'pending');
$duplicate_source_id = $duplicate_source->get_id();
$duplicate_line = $duplicate_source->get_item($duplicate_item_id);
$duplicate_line->add_meta_data('engraving', 'Duplicate-A', true);
$duplicate_line->save();

$duplicate_shipping = new WC_Order_Item_Shipping();
$duplicate_shipping->set_method_title('Duplicate historical shipping');
$duplicate_shipping->set_method_id('flat_rate');
$duplicate_shipping->set_instance_id(42);
$duplicate_shipping->set_total('3.21');
$duplicate_source->add_item($duplicate_shipping);

$duplicate_fee = new WC_Order_Item_Fee();
$duplicate_fee->set_name('Duplicate historical fee');
$duplicate_fee->set_amount('1.11');
$duplicate_fee->set_total('1.11');
$duplicate_source->add_item($duplicate_fee);

$duplicate_coupon = new WC_Order_Item_Coupon();
$duplicate_coupon->set_code('historic-duplicate-coupon');
$duplicate_coupon->set_discount('0.44');
$duplicate_coupon->set_discount_tax('0');
$duplicate_source->add_item($duplicate_coupon);
$duplicate_source->calculate_totals(false);
$duplicate_source->set_payment_method('cod');
$duplicate_source->set_payment_method_title('Cash on delivery');
$duplicate_source->set_transaction_id('source-duplicate-transaction');
$duplicate_source->set_billing_first_name('DuplicatePrivateProbe');
$duplicate_source->set_billing_email('duplicate-private@example.test');
$duplicate_source->update_meta_data('_third_party_order_state', 'must-not-copy');
$duplicate_source->save();

wc_reduce_stock_levels($duplicate_source);
$duplicate_source->get_data_store()->set_stock_reduced($duplicate_source_id, true);
$duplicate_source = wc_get_order($duplicate_source_id);
$duplicate_stock_before = wc_get_product($duplicate_product->get_id())->get_stock_quantity();
$duplicate_source_signature = WCOS_Order_Contract_Snapshot::source_signature($duplicate_source);
$duplicate_adapter = new WCOS_Duplicate_WooCommerce_Adapter();
$duplicate_controller = new WCOS_Duplicate_Admin_Controller();
$duplicate_operation = '';
$duplicate_direct_operation = '';

try {
    update_option('order_splitter_status_allowed', array('wc-pending'));
    update_option('order_splitter_shop_manager_permission', 'no');
    wp_set_current_user($duplicate_admin_id);

    $preflight = $duplicate_adapter->preflight($duplicate_source);
    wcos_p2_adapter_assert(!empty($preflight['supported']), 'Valid source failed Duplicate preflight.');
    wcos_p2_adapter_assert('copy_exact' === $preflight['policy']['coupons'], 'Duplicate coupon policy was not explicit.');
    wcos_p2_adapter_assert('do_not_copy' === $preflight['policy']['payment_transaction'], 'Duplicate transaction policy was not explicit.');
    wcos_p2_adapter_assert(!empty($preflight['source_signature']), 'Duplicate preflight omitted the PII-free source signature.');
    $preflight_json = wp_json_encode($preflight);
    wcos_p2_adapter_assert(false === strpos($preflight_json, 'DuplicatePrivateProbe'), 'Duplicate preflight leaked billing PII.');
    wcos_p2_adapter_assert(false === strpos($preflight_json, 'duplicate-private@example.test'), 'Duplicate preflight leaked billing email.');

    /* Custom order-level meta is deliberately not copied by the service. */
    $duplicate_direct_operation = 'p2-duplicate-adapter-' . wp_generate_uuid4();
    $target = $duplicate_adapter->duplicate($duplicate_source, $duplicate_direct_operation);
    $target = wc_get_order($target->get_id());
    wcos_p2_adapter_assert($target instanceof WC_Order, 'Duplicate adapter did not create a target.');
    wcos_p2_adapter_assert('pending' === $target->get_status(), 'Duplicate target did not remain Pending payment.');
    wcos_p2_adapter_assert('' === (string) $target->get_transaction_id(), 'Duplicate target copied the transaction ID.');
    wcos_p2_adapter_assert(false === (bool) $target->get_data_store()->get_stock_reduced($target->get_id()), 'Duplicate target inherited order-level stock-reduced state.');
    wcos_p2_adapter_assert('' === (string) $target->get_meta('_third_party_order_state', true), 'Duplicate target copied unsupported custom order-level metadata.');
    wcos_p2_adapter_assert($duplicate_stock_before == wc_get_product($duplicate_product->get_id())->get_stock_quantity(), 'Duplicate adapter changed physical stock.');
    wcos_p2_adapter_assert($duplicate_source_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($duplicate_source_id)), 'Duplicate adapter changed the source commercial state.');

    $target_line = current($target->get_items('line_item'));
    wcos_p2_adapter_assert('Duplicate-A' === (string) $target_line->get_meta('engraving', true), 'Duplicate target lost public business metadata.');
    wcos_p2_adapter_assert('' === (string) $target_line->get_meta('_reduced_stock', true), 'Duplicate target inherited _reduced_stock.');
    $target_shipping = current($target->get_items('shipping'));
    wcos_p2_adapter_assert('flat_rate' === $target_shipping->get_method_id() && 42 === (int) $target_shipping->get_instance_id(), 'Duplicate target lost shipping method identity.');
    $target_coupon = current($target->get_items('coupon'));
    wcos_p2_adapter_assert('historic-duplicate-coupon' === $target_coupon->get_code(), 'Duplicate target lost historical coupon row.');

    $direct_retry = $duplicate_adapter->duplicate(wc_get_order($duplicate_source_id), $duplicate_direct_operation);
    wcos_p2_adapter_assert($direct_retry->get_id() === $target->get_id(), 'Duplicate adapter retry created a different target.');
    wcos_p2_adapter_assert(1 === count(wcos_duplicate_targets($duplicate_source_id, $duplicate_direct_operation)), 'Duplicate adapter retry produced more than one target.');
    wcos_p2_adapter_assert($duplicate_stock_before == wc_get_product($duplicate_product->get_id())->get_stock_quantity(), 'Duplicate adapter retry changed physical stock.');

    $journal = WCOS_Operation_Journal::get(wc_get_order($duplicate_source_id), $duplicate_direct_operation);
    wcos_p2_adapter_assert(is_array($journal) && 'completed' === $journal['status'], 'Duplicate adapter journal did not complete.');
    wcos_p2_adapter_assert((int) WCOS_Duplicate_Preflight::POLICY_VERSION === (int) $journal['context']['policy_version'], 'Duplicate journal lost policy-version authority.');
    wcos_p2_adapter_assert(array_key_exists('price_precision', $journal['context']), 'Duplicate journal lost price precision.');

    /* Unknown private line metadata fails before a mutation journal/target exists. */
    $unknown_product = wcos_p2_adapter_product('WCOS Duplicate unknown meta', '5.00');
    list($unknown_source, $unknown_item_id) = wcos_p2_adapter_order($unknown_product, 2);
    $unknown_item = $unknown_source->get_item($unknown_item_id);
    $unknown_item->add_meta_data('_bundle_private_config', 'opaque', true);
    $unknown_item->save();
    $unknown_source = wc_get_order($unknown_source->get_id());
    $unknown_report = $duplicate_adapter->preflight($unknown_source);
    wcos_p2_adapter_assert(empty($unknown_report['supported']) && 'unclassified_private_metadata' === $unknown_report['reason'], 'Duplicate preflight accepted unknown private metadata.');
    $unknown_operation = 'p2-duplicate-unknown-' . wp_generate_uuid4();
    $unknown_rejected = false;
    try {
        $duplicate_adapter->duplicate($unknown_source, $unknown_operation);
    } catch (WCOS_Duplicate_Preflight_Exception $exception) {
        $unknown_rejected = 'unclassified_private_metadata' === $exception->get_reason();
    }
    wcos_p2_adapter_assert($unknown_rejected, 'Duplicate adapter did not fail closed on unknown private metadata.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get($unknown_source, $unknown_operation), 'Unknown private metadata rejection created a journal.');
    wcos_p2_adapter_assert(empty(wcos_duplicate_targets($unknown_source->get_id(), $unknown_operation)), 'Unknown private metadata rejection created a target.');
    $unknown_source->delete(true);
    wp_delete_post($unknown_product->get_id(), true);

    /* Refunded orders fail closed before mutation. */
    $refund_product = wcos_p2_adapter_product('WCOS Duplicate refund reject', '7.00');
    list($refund_source, $refund_item_id) = wcos_p2_adapter_order($refund_product, 2);
    $refund = wc_create_refund(array(
        'order_id' => $refund_source->get_id(),
        'amount' => '1.00',
        'reason' => 'duplicate-readiness-test',
    ));
    wcos_p2_adapter_assert(!is_wp_error($refund), 'Unable to create Duplicate refund fixture.');
    $refund_report = $duplicate_adapter->preflight(wc_get_order($refund_source->get_id()));
    wcos_p2_adapter_assert(empty($refund_report['supported']) && 'refund_policy_missing' === $refund_report['reason'], 'Duplicate preflight accepted a refunded order.');
    wc_get_order($refund_source->get_id())->delete(true);
    wp_delete_post($refund_product->get_id(), true);

    /* Production transport is fully wired but remains hard-off. */
    wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Duplicate_Admin_Controller::REVIEW_ACTION), 'Duplicate review AJAX route was not bootstrapped.');
    wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Duplicate_Admin_Controller::EXECUTE_ACTION), 'Duplicate execute AJAX route was not bootstrapped.');
    $nonce = wp_create_nonce('wcos_duplicate_order_' . $duplicate_source_id);
    $review = $duplicate_controller->review_request(array(
        'order_id' => $duplicate_source_id,
        'nonce' => $nonce,
    ));
    $duplicate_operation = $review['operation_id'];
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($duplicate_source_id), $duplicate_operation), 'Read-only Duplicate review created a journal.');
    wcos_p2_adapter_assert($duplicate_source_signature === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($duplicate_source_id)), 'Read-only Duplicate review changed the source.');

    $invalid_token = wcos_p2_duplicate_expect_transport(
        'confirmation_invalid_token',
        403,
        static function() use ($duplicate_controller, $duplicate_source_id, $nonce, $review) {
            $duplicate_controller->execute_request(array(
                'order_id' => $duplicate_source_id,
                'nonce' => $nonce,
                'operation_id' => $review['operation_id'],
                'confirmation_token' => 'wrong-duplicate-token',
            ));
        },
        'Duplicate execute accepted an invalid token.'
    );
    wcos_p2_adapter_assert(!$invalid_token->is_retryable(), 'Invalid Duplicate token became retryable.');

    $disabled = wcos_p2_duplicate_expect_transport(
        'workflow_disabled',
        503,
        static function() use ($duplicate_controller, $duplicate_source_id, $nonce, $review) {
            $duplicate_controller->execute_request(array(
                'order_id' => $duplicate_source_id,
                'nonce' => $nonce,
                'operation_id' => $review['operation_id'],
                'confirmation_token' => $review['confirmation_token'],
            ));
        },
        'Duplicate hard-off execute reached mutation runtime.'
    );
    wcos_p2_adapter_assert(!$disabled->is_retryable(), 'Hard-off Duplicate response became retryable.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($duplicate_source_id), $duplicate_operation), 'Hard-off Duplicate execute created a journal.');

    wcos_p2_duplicate_expect_transport(
        'invalid_nonce',
        403,
        static function() use ($duplicate_controller, $duplicate_source_id) {
            $duplicate_controller->review_request(array('order_id' => $duplicate_source_id, 'nonce' => 'invalid'));
        },
        'Duplicate review accepted an invalid nonce.'
    );
    wp_set_current_user($duplicate_subscriber_id);
    $subscriber_nonce = wp_create_nonce('wcos_duplicate_order_' . $duplicate_source_id);
    wcos_p2_duplicate_expect_transport(
        'authorization_failed',
        403,
        static function() use ($duplicate_controller, $duplicate_source_id, $subscriber_nonce) {
            $duplicate_controller->review_request(array('order_id' => $duplicate_source_id, 'nonce' => $subscriber_nonce));
        },
        'Subscriber was allowed to review Duplicate.'
    );
    wp_set_current_user($duplicate_admin_id);

    ob_start();
    $duplicate_controller->render_launcher(wc_get_order($duplicate_source_id));
    $launcher_html = ob_get_clean();
    wcos_p2_adapter_assert('' === $launcher_html, 'Duplicate launcher rendered while its feature gate is hard-off.');

    $dialog_html = $duplicate_controller->dialog_html(wc_get_order($duplicate_source_id), $preflight);
    foreach (array('role="dialog"', 'aria-modal="true"', 'role="status"', 'role="alert"', 'wcos-duplicate-confirm-checkbox', 'Confirm and duplicate', 'Custom order-level metadata') as $needle) {
        wcos_p2_adapter_assert(false !== strpos($dialog_html, $needle), 'Duplicate dialog is missing required markup/policy: ' . $needle);
    }
    wcos_p2_adapter_assert(false === strpos($dialog_html, 'DuplicatePrivateProbe'), 'Duplicate dialog leaked billing PII.');
    wcos_p2_adapter_assert(false === strpos($dialog_html, 'duplicate-private@example.test'), 'Duplicate dialog leaked billing email.');

    $js = file_get_contents(dirname(__DIR__, 2) . '/js/p2-duplicate-admin.js');
    wcos_p2_adapter_assert(is_string($js) && '' !== $js, 'Unable to read Duplicate admin client.');
    wcos_p2_adapter_assert(false === strpos($js, 'innerHTML'), 'Duplicate admin client uses innerHTML.');
    wcos_p2_adapter_assert(false === strpos($js, 'alert('), 'Duplicate admin client uses blocking alert().');
    foreach (array("event.key === 'Escape'", "event.key !== 'Tab'", 'returnFocus.focus()', 'var completed = false;', 'reviewButton.disabled = busy || completed;', 'executeButton.disabled = busy || completed') as $needle) {
        wcos_p2_adapter_assert(false !== strpos($js, $needle), 'Duplicate admin client is missing terminal/accessibility behavior: ' . $needle);
    }
} finally {
    if ($duplicate_operation) {
        WCOS_Duplicate_Confirmation_Store::delete($duplicate_operation);
    }
    if ($duplicate_direct_operation) {
        WCOS_Operation_Journal::delete(wc_get_order($duplicate_source_id), $duplicate_direct_operation);
        foreach (wcos_duplicate_targets($duplicate_source_id, $duplicate_direct_operation) as $target) {
            $target->delete(true);
        }
    }
    $source = wc_get_order($duplicate_source_id);
    if ($source instanceof WC_Order) {
        $source->delete(true);
    }
    wp_delete_post($duplicate_product->get_id(), true);
    update_option('order_splitter_status_allowed', $duplicate_allowed_statuses);
    update_option('order_splitter_shop_manager_permission', $duplicate_manager_permission);
    wp_set_current_user($duplicate_previous_user);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    if (!is_wp_error($duplicate_admin_id)) {
        wp_delete_user($duplicate_admin_id);
    }
    if (!is_wp_error($duplicate_subscriber_id)) {
        wp_delete_user($duplicate_subscriber_id);
    }
}

echo "p2-duplicate-readiness-ok\n";
