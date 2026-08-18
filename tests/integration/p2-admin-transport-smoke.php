<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_transport_expect($code, callable $callback, $message) {
    try {
        $callback();
    } catch (WCOS_Split_Transport_Exception $exception) {
        wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
        return $exception;
    }
    throw new RuntimeException($message);
}

function wcos_p2_transport_expect_invalid_plan(callable $callback, $message) {
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

$original_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$original_manager_permission = get_option('order_splitter_shop_manager_permission', 'no');
$previous_user_id = get_current_user_id();

$admin_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_transport_admin_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-transport-admin-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
$subscriber_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_transport_subscriber_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-transport-subscriber-' . wp_generate_uuid4() . '@example.test',
    'role' => 'subscriber',
));
wcos_p2_adapter_assert(!is_wp_error($admin_id) && !is_wp_error($subscriber_id), 'Unable to create Split transport test users.');

$transport_product = wcos_p2_adapter_product('WCOS P2 transport product', '12.00');
list($transport_source, $transport_item_id) = wcos_p2_adapter_order($transport_product, 4);
$transport_source->set_billing_first_name('PrivateFirstNameProbe');
$transport_source->set_billing_email('private-probe@example.test');
$transport_source->save();
$transport_source = wc_get_order($transport_source->get_id());
$source_id = $transport_source->get_id();
$source_signature_before = WCOS_Order_Contract_Snapshot::source_signature($transport_source);

$controller = new WCOS_Split_Admin_Controller();
$review_operations = array();

try {
    update_option('order_splitter_status_allowed', array('wc-pending'));
    update_option('order_splitter_shop_manager_permission', 'no');
    wp_set_current_user($admin_id);

    wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Admin_Controller::REVIEW_ACTION), 'Split review AJAX transport was not bootstrapped.');
    wcos_p2_adapter_assert(false !== has_action('wp_ajax_' . WCOS_Split_Admin_Controller::EXECUTE_ACTION), 'Split execute AJAX transport was not bootstrapped.');
    wcos_p2_adapter_assert(!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT), 'Transport acceptance test unexpectedly found Split enabled.');

    /* Strict parser rejects ambiguous or unsafe request shapes. */
    wcos_p2_transport_expect_invalid_plan(
        static function() use ($transport_source, $transport_item_id) {
            WCOS_Split_Request_Parser::parse_json(wp_json_encode(array('new-order' => array($transport_item_id => '1.000000'))), $transport_source);
        },
        'Parser accepted a non-canonical child key.'
    );
    wcos_p2_transport_expect_invalid_plan(
        static function() use ($transport_source, $transport_item_id) {
            WCOS_Split_Request_Parser::parse_json(wp_json_encode(array('child-1' => array($transport_item_id => 1))), $transport_source);
        },
        'Parser accepted a JSON numeric quantity instead of a decimal string.'
    );
    wcos_p2_transport_expect_invalid_plan(
        static function() use ($transport_source) {
            WCOS_Split_Request_Parser::parse_json(wp_json_encode(array('child-1' => array('99999999' => '1.000000'))), $transport_source);
        },
        'Parser accepted an order item outside the source.'
    );
    wcos_p2_transport_expect_invalid_plan(
        static function() use ($transport_source, $transport_item_id) {
            WCOS_Split_Request_Parser::parse_json(wp_json_encode(array('child-1' => array($transport_item_id => '4.000000'))), $transport_source);
        },
        'Parser allowed a Split plan to consume the entire source line.'
    );
    wcos_p2_transport_expect_invalid_plan(
        static function() use ($transport_source, $transport_item_id) {
            WCOS_Split_Request_Parser::parse_json(wp_json_encode(array('child-1' => array($transport_item_id => '1e0'))), $transport_source);
        },
        'Parser accepted scientific-notation quantity input.'
    );

    $nonce = wp_create_nonce('wcos_split_order_' . $source_id);
    $plan_json = wp_json_encode(array('child-1' => array((string) $transport_item_id => '1.000000')));
    $review = $controller->review_request(array(
        'order_id' => $source_id,
        'nonce' => $nonce,
        'plan' => $plan_json,
    ));
    $review_operations[] = $review['operation_id'];
    wcos_p2_adapter_assert(1 === preg_match('/^[0-9a-f-]{36}$/D', $review['operation_id']), 'Review did not return a server-generated operation UUID.');
    wcos_p2_adapter_assert(is_string($review['confirmation_token']) && strlen($review['confirmation_token']) >= 32, 'Review did not return a strong confirmation token.');
    wcos_p2_adapter_assert(!empty($review['preflight']['supported']), 'Review did not return a supported preflight.');
    wcos_p2_adapter_assert(1 === (int) $review['summary']['child_count'], 'Review summary child count is wrong.');
    wcos_p2_adapter_assert(1 === (int) $review['summary']['affected_line_count'], 'Review summary affected-line count is wrong.');
    wcos_p2_adapter_assert('1.000000' === $review['summary']['moved_quantity'], 'Review summary moved quantity is wrong.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($source_id), $review['operation_id']), 'Read-only review created a mutation journal.');
    wcos_p2_adapter_assert($source_signature_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source_id)), 'Read-only review changed the source order.');
    wcos_p2_adapter_assert(empty(wcos_p2_adapter_children($source_id, $review['operation_id'])), 'Read-only review created a child order.');

    $review_json = wp_json_encode($review);
    wcos_p2_adapter_assert(false === strpos($review_json, 'PrivateFirstNameProbe'), 'PII leaked into the review response.');
    wcos_p2_adapter_assert(false === strpos($review_json, 'private-probe@example.test'), 'Billing email leaked into the review response.');

    /* Execute endpoint is fully wired but must remain non-runnable while SPLIT=false. */
    $disabled = wcos_p2_transport_expect(
        'workflow_disabled',
        static function() use ($controller, $source_id, $nonce, $review) {
            $controller->execute_request(array(
                'order_id' => $source_id,
                'nonce' => $nonce,
                'operation_id' => $review['operation_id'],
                'confirmation_token' => $review['confirmation_token'],
            ));
        },
        'Hard-off execute transport reached mutation runtime.'
    );
    wcos_p2_adapter_assert(503 === $disabled->get_http_status() && !$disabled->is_retryable(), 'Hard-off execute response semantics are incorrect.');
    wcos_p2_adapter_assert(null === WCOS_Operation_Journal::get(wc_get_order($source_id), $review['operation_id']), 'Hard-off execute created a journal.');
    wcos_p2_adapter_assert(empty(wcos_p2_adapter_children($source_id, $review['operation_id'])), 'Hard-off execute created a child order.');
    wcos_p2_adapter_assert($source_signature_before === WCOS_Order_Contract_Snapshot::source_signature(wc_get_order($source_id)), 'Hard-off execute changed the source.');

    /* Token binding rejects a different token before workflow gate evaluation. */
    wcos_p2_transport_expect(
        'confirmation_invalid_token',
        static function() use ($controller, $source_id, $nonce, $review) {
            $controller->execute_request(array(
                'order_id' => $source_id,
                'nonce' => $nonce,
                'operation_id' => $review['operation_id'],
                'confirmation_token' => 'different-confirmation-token',
            ));
        },
        'Execute accepted a mismatched confirmation token.'
    );

    /* A source change invalidates review before any mutation begins. */
    $review_changed = $controller->review_request(array(
        'order_id' => $source_id,
        'nonce' => $nonce,
        'plan' => $plan_json,
    ));
    $review_operations[] = $review_changed['operation_id'];
    $changed_source = wc_get_order($source_id);
    $changed_source->set_customer_note('changed-after-review');
    $changed_source->save();
    wcos_p2_transport_expect(
        'confirmation_source_changed',
        static function() use ($controller, $source_id, $nonce, $review_changed) {
            $controller->execute_request(array(
                'order_id' => $source_id,
                'nonce' => $nonce,
                'operation_id' => $review_changed['operation_id'],
                'confirmation_token' => $review_changed['confirmation_token'],
            ));
        },
        'Execute accepted a source changed after review.'
    );
    $changed_source = wc_get_order($source_id);
    $changed_source->set_customer_note('');
    $changed_source->save();

    /* Nonce, authorization, and configured order-status policy are independent gates. */
    wcos_p2_transport_expect(
        'invalid_nonce',
        static function() use ($controller, $source_id, $plan_json) {
            $controller->review_request(array('order_id' => $source_id, 'nonce' => 'invalid', 'plan' => $plan_json));
        },
        'Review accepted an invalid nonce.'
    );

    wp_set_current_user($subscriber_id);
    $subscriber_nonce = wp_create_nonce('wcos_split_order_' . $source_id);
    wcos_p2_transport_expect(
        'authorization_failed',
        static function() use ($controller, $source_id, $subscriber_nonce, $plan_json) {
            $controller->review_request(array('order_id' => $source_id, 'nonce' => $subscriber_nonce, 'plan' => $plan_json));
        },
        'Subscriber was allowed to review a Split operation.'
    );

    wp_set_current_user($admin_id);
    update_option('order_splitter_status_allowed', array('wc-processing'));
    $status_nonce = wp_create_nonce('wcos_split_order_' . $source_id);
    wcos_p2_transport_expect(
        'status_disabled',
        static function() use ($controller, $source_id, $status_nonce, $plan_json) {
            $controller->review_request(array('order_id' => $source_id, 'nonce' => $status_nonce, 'plan' => $plan_json));
        },
        'Transport ignored the configured allowed-status policy.'
    );
    update_option('order_splitter_status_allowed', array('wc-pending'));

    /* Server-rendered dialog carries semantic labels and no address/customer PII. */
    $fresh_source = wc_get_order($source_id);
    $preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($fresh_source);
    $html = $controller->dialog_html($fresh_source, $preflight);
    foreach (array(
        'role="dialog"',
        'aria-modal="true"',
        'aria-labelledby=',
        'role="status"',
        'aria-live="polite"',
        'role="alert"',
        'wcos-split-confirm-checkbox',
        'wcos-split-review-button',
        'wcos-split-execute-button',
        'for="wcos-split-quantity-',
        'for="wcos-split-target-',
        '>Child 10<',
        'change stock directly in the database',
    ) as $needle) {
        wcos_p2_adapter_assert(false !== strpos($html, $needle), 'Accessible Split dialog is missing required markup: ' . $needle);
    }
    wcos_p2_adapter_assert(false === strpos($html, 'PrivateFirstNameProbe'), 'Billing first name leaked into the Split dialog.');
    wcos_p2_adapter_assert(false === strpos($html, 'private-probe@example.test'), 'Billing email leaked into the Split dialog.');

    ob_start();
    $controller->render_launcher($fresh_source);
    $launcher_html = ob_get_clean();
    wcos_p2_adapter_assert('' === $launcher_html, 'Production Split launcher rendered while the feature gate is hard-off.');
} finally {
    foreach ($review_operations as $operation_id) {
        WCOS_Split_Confirmation_Store::delete($operation_id);
    }
    update_option('order_splitter_status_allowed', $original_allowed_statuses);
    update_option('order_splitter_shop_manager_permission', $original_manager_permission);
    wp_set_current_user($previous_user_id);
    $transport_source = wc_get_order($source_id);
    if ($transport_source instanceof WC_Order) {
        $transport_source->delete(true);
    }
    wp_delete_post($transport_product->get_id(), true);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    if (!is_wp_error($admin_id)) {
        wp_delete_user($admin_id);
    }
    if (!is_wp_error($subscriber_id)) {
        wp_delete_user($subscriber_id);
    }
}

echo "p2-admin-transport-accessibility-ok\n";
