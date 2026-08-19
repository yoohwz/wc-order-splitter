<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$duplicate_precision_original = get_option('woocommerce_price_num_decimals', 2);
$duplicate_precision_adapter = new WCOS_Duplicate_WooCommerce_Adapter();

try {
    foreach (array(
        array('precision' => 0, 'currency' => 'JPY', 'line' => '10', 'tax' => '1'),
        array('precision' => 3, 'currency' => 'BHD', 'line' => '10.001', 'tax' => '1.001'),
    ) as $case) {
        update_option('woocommerce_price_num_decimals', (string) $case['precision']);
        list($source, $product_id, $item_id) = wcos_p2_precision_build_order(
            $case['precision'],
            $case['currency'],
            $case['line'],
            $case['tax']
        );
        $source_id = $source->get_id();
        $source_contract = WCOS_Order_Contract_Snapshot::aggregate(array($source), $case['precision']);
        unset($source_contract['stock_reduced']);
        $operation = 'p2-duplicate-precision-' . $case['precision'] . '-' . wp_generate_uuid4();

        $report = $duplicate_precision_adapter->preflight($source, $operation);
        wcos_p2_adapter_assert($case['precision'] === (int) $report['price_precision'], 'Duplicate preflight reported the wrong price precision.');
        $target = $duplicate_precision_adapter->duplicate($source, $operation);
        $target = wc_get_order($target->get_id());
        $target_contract = WCOS_Order_Contract_Snapshot::aggregate(array($target), $case['precision']);
        unset($target_contract['stock_reduced']);
        WCOS_Mutation_Contract::assert_conserved($source_contract, $target_contract, $case['precision']);
        WCOS_Order_Totals_Rebuilder::assert_consistent($target, $case['precision']);

        $record = WCOS_Operation_Journal::get(wc_get_order($source_id), $operation);
        wcos_p2_adapter_assert(is_array($record) && $case['precision'] === (int) $record['context']['price_precision'], 'Duplicate journal did not capture the reviewed price precision.');
        wcos_p2_adapter_assert((int) WCOS_Duplicate_Preflight::POLICY_VERSION === (int) $record['context']['policy_version'], 'Duplicate journal did not capture the reviewed policy version.');

        WCOS_Operation_Journal::delete(wc_get_order($source_id), $operation);
        $target->delete(true);
        wc_get_order($source_id)->delete(true);
        wp_delete_post($product_id, true);
    }

    /* Interrupted Duplicate must retain journal precision/policy authority after confirmation expiry. */
    $previous_user = get_current_user_id();
    $allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
    $user_id = wp_insert_user(array(
        'user_login' => 'wcos_duplicate_replay_' . wp_generate_password(8, false),
        'user_pass' => wp_generate_password(24, true),
        'user_email' => 'wcos-duplicate-replay-' . wp_generate_uuid4() . '@example.test',
        'role' => 'administrator',
    ));
    wcos_p2_adapter_assert(!is_wp_error($user_id), 'Unable to create Duplicate replay user.');

    update_option('woocommerce_price_num_decimals', '3');
    update_option('order_splitter_status_allowed', array('wc-pending'));
    wp_set_current_user($user_id);
    list($replay_source, $replay_product_id, $replay_item_id) = wcos_p2_precision_build_order(3, 'BHD', '10.001', '1.001');
    $replay_source_id = $replay_source->get_id();
    $controller = new WCOS_Duplicate_Admin_Controller();
    $nonce = wp_create_nonce('wcos_duplicate_order_' . $replay_source_id);
    $review = $controller->review_request(array('order_id' => $replay_source_id, 'nonce' => $nonce));
    $operation = $review['operation_id'];
    wcos_p2_adapter_assert(3 === (int) $review['preflight']['price_precision'], 'Duplicate replay review did not capture three-decimal precision.');

    $fail_once = true;
    $crash = static function($stage) use (&$fail_once) {
        if ($fail_once && 'after_target_save' === $stage) {
            $fail_once = false;
            throw new RuntimeException('Injected Duplicate durable replay crash.');
        }
    };
    add_action('wcos_duplicate_mutation_checkpoint', $crash, 10, 4);
    $crashed = false;
    try {
        $duplicate_precision_adapter->duplicate(
            wc_get_order($replay_source_id),
            $operation,
            $review['preflight']['price_precision']
        );
    } catch (RuntimeException $exception) {
        $crashed = false !== strpos($exception->getMessage(), 'Injected Duplicate durable replay crash.');
    }
    remove_action('wcos_duplicate_mutation_checkpoint', $crash, 10);
    wcos_p2_adapter_assert($crashed, 'Duplicate durable replay fixture did not crash at the intended persistence boundary.');

    $record = WCOS_Operation_Journal::get(wc_get_order($replay_source_id), $operation);
    wcos_p2_adapter_assert(is_array($record), 'Interrupted Duplicate did not create a durable journal.');
    wcos_p2_adapter_assert(3 === (int) $record['context']['price_precision'], 'Interrupted Duplicate lost its three-decimal precision.');
    wcos_p2_adapter_assert((int) WCOS_Duplicate_Preflight::POLICY_VERSION === (int) $record['context']['policy_version'], 'Interrupted Duplicate lost its policy authority.');
    $persisted_targets = wcos_duplicate_targets($replay_source_id, $operation);
    wcos_p2_adapter_assert(1 === count($persisted_targets), 'Interrupted Duplicate did not preserve exactly one reusable target.');

    WCOS_Duplicate_Confirmation_Store::delete($operation);
    update_option('woocommerce_price_num_decimals', '0');
    $durable = WCOS_Duplicate_Confirmation_Store::verify(
        wc_get_order($replay_source_id),
        $operation,
        '',
        $user_id
    );
    wcos_p2_adapter_assert('journal' === $durable['replay_authority'], 'Expired Duplicate confirmation did not fall back to journal authority.');
    wcos_p2_adapter_assert(3 === (int) $durable['price_precision'], 'Durable Duplicate replay did not preserve journal price precision.');
    wcos_p2_adapter_assert((int) WCOS_Duplicate_Preflight::POLICY_VERSION === (int) $durable['policy_version'], 'Durable Duplicate replay did not preserve journal policy version.');
    wcos_p2_adapter_assert(0 === wc_get_price_decimals(), 'Duplicate confirmation replay leaked precision scope into ambient state.');

    $completed = $duplicate_precision_adapter->duplicate(
        wc_get_order($replay_source_id),
        $operation,
        $durable['price_precision']
    );
    wcos_p2_adapter_assert($persisted_targets[0]->get_id() === $completed->get_id(), 'Duplicate durable replay created another target.');
    $record = WCOS_Operation_Journal::get(wc_get_order($replay_source_id), $operation);
    wcos_p2_adapter_assert('completed' === $record['status'], 'Duplicate durable replay did not complete the original journal.');

    /* A changed durable policy fails closed rather than replaying under new semantics. */
    $journal_key = 'wcos_mutation_op_' . hash('sha256', absint($replay_source_id) . '|' . sanitize_key($operation));
    $original_record = $record;
    $tampered = $record;
    $tampered['context']['policy_version'] = WCOS_Duplicate_Preflight::POLICY_VERSION + 1;
    update_option($journal_key, $tampered, false);
    wp_cache_delete($journal_key, 'options');
    $policy_changed = false;
    try {
        WCOS_Duplicate_Confirmation_Store::verify(wc_get_order($replay_source_id), $operation, '', $user_id);
    } catch (WCOS_Duplicate_Confirmation_Exception $exception) {
        $policy_changed = 'policy_changed' === $exception->get_reason();
    }
    wcos_p2_adapter_assert($policy_changed, 'Duplicate durable replay crossed a changed safety policy.');
    update_option($journal_key, $original_record, false);
    wp_cache_delete($journal_key, 'options');

    WCOS_Operation_Journal::delete(wc_get_order($replay_source_id), $operation);
    foreach (wcos_duplicate_targets($replay_source_id, $operation) as $target) {
        $target->delete(true);
    }
    wc_get_order($replay_source_id)->delete(true);
    wp_delete_post($replay_product_id, true);
    update_option('order_splitter_status_allowed', $allowed_statuses);
    wp_set_current_user($previous_user);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    wp_delete_user($user_id);
} finally {
    update_option('woocommerce_price_num_decimals', $duplicate_precision_original);
}

echo "p2-duplicate-precision-replay-ok\n";
