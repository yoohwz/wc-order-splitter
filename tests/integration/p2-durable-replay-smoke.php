<?php

if (!defined('ABSPATH')) {
    exit(1);
}

function wcos_p2_replay_expect_transport($code, $http_status, callable $callback, $message) {
    try {
        $callback();
    } catch (WCOS_Split_Transport_Exception $exception) {
        wcos_p2_adapter_assert($code === $exception->get_error_code(), $message . ' Wrong code: ' . $exception->get_error_code());
        wcos_p2_adapter_assert($http_status === $exception->get_http_status(), $message . ' Wrong HTTP status.');
        return $exception;
    }
    throw new RuntimeException($message);
}

$replay_previous_user = get_current_user_id();
$replay_allowed_statuses = get_option('order_splitter_status_allowed', array('wc-processing'));
$replay_user_id = wp_insert_user(array(
    'user_login' => 'wcos_p2_replay_admin_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(24, true),
    'user_email' => 'wcos-p2-replay-' . wp_generate_uuid4() . '@example.test',
    'role' => 'administrator',
));
wcos_p2_adapter_assert(!is_wp_error($replay_user_id), 'Unable to create durable replay test user.');

$replay_product = wcos_p2_adapter_product('WCOS P2 durable replay', '11.00');
list($replay_source, $replay_item_id) = wcos_p2_adapter_order($replay_product, 4);
$replay_source_id = $replay_source->get_id();
$replay_controller = new WCOS_Split_Admin_Controller();
$replay_operation = '';

try {
    wp_set_current_user($replay_user_id);
    update_option('order_splitter_status_allowed', array('wc-pending'));
    $replay_nonce = wp_create_nonce('wcos_split_order_' . $replay_source_id);
    $replay_plan = array('child-1' => array($replay_item_id => '1.000000'));

	$review = $replay_controller->review_request(array(
        'order_id' => $replay_source_id,
        'nonce' => $replay_nonce,
        'plan' => wp_json_encode(array('child-1' => array((string) $replay_item_id => '1.000000'))),
	));
	$replay_operation = $review['operation_id'];
	$legacy_manual_authority = $review['preflight']['manual_quantity_authority'];
	$legacy_manual_authority['policy_version'] = WCOS_Manual_Split_Quantity_Authority::LEGACY_POLICY_VERSION;
	foreach ($legacy_manual_authority['lines'] as &$legacy_line) {
		$legacy_maximum_units = $legacy_line['source_quantity_units'] > $legacy_line['step_units']
			? $legacy_line['source_quantity_units'] - $legacy_line['step_units']
			: 0;
		$legacy_line['maximum_quantity_units'] = $legacy_maximum_units;
		$legacy_line['maximum_quantity'] = WCOS_Decimal::from_units($legacy_maximum_units, WCOS_Manual_Split_Quantity_Authority::PRECISION);
		$legacy_line['can_partially_split'] = $legacy_maximum_units >= $legacy_line['step_units'];
	}
	unset($legacy_line, $legacy_manual_authority['authority_fingerprint']);
	$legacy_manual_authority['authority_fingerprint'] = WCOS_Mutation_Fingerprint::create(
		'manual_split_quantity_authority_v1',
		$replay_source_id,
		$legacy_manual_authority
	);
	$legacy_manual_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid($legacy_manual_authority);
	wcos_p2_adapter_assert(WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY === WCOS_Manual_Split_Quantity_Authority::execution_policy($legacy_manual_authority), 'Legacy Manual authority no longer derives partial-only replay policy.');

    $fail_once = true;
    $crash = static function($stage) use (&$fail_once) {
        if ($fail_once && 'after_child_save' === $stage) {
            $fail_once = false;
            throw new RuntimeException('Injected durable replay crash.');
        }
    };
    add_action('wcos_split_mutation_checkpoint', $crash, 10, 4);
    $crashed = false;
    try {
        (new WCOS_Split_WooCommerce_Adapter())->split(
            wc_get_order($replay_source_id),
            $replay_plan,
            $replay_operation,
			$review['preflight']['price_precision'],
			WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY,
			array('manual_quantity_authority' => $legacy_manual_authority)
        );
    } catch (RuntimeException $exception) {
        $crashed = false !== strpos($exception->getMessage(), 'Injected durable replay crash.');
    }
    remove_action('wcos_split_mutation_checkpoint', $crash, 10);
    wcos_p2_adapter_assert($crashed, 'Durable replay fixture did not crash at the intended boundary.');

    $replay_source = wc_get_order($replay_source_id);
    $journal = WCOS_Operation_Journal::get($replay_source, $replay_operation);
    wcos_p2_adapter_assert(is_array($journal), 'Interrupted Split did not create a durable journal.');
    wcos_p2_adapter_assert(!empty($journal['context']['plan']) && array_key_exists('price_precision', $journal['context']), 'Durable journal is missing replay plan or precision.');
    $persisted_children = wcos_p2_adapter_children($replay_source_id, $replay_operation);
    wcos_p2_adapter_assert(1 === count($persisted_children), 'Interrupted Split did not persist exactly one reusable child.');

    /* Simulate confirmation TTL expiry: replay must now come from the journal. */
    WCOS_Split_Confirmation_Store::delete($replay_operation);
    $durable = WCOS_Split_Confirmation_Store::verify(
        wc_get_order($replay_source_id),
        $replay_operation,
        '',
        $replay_user_id
    );
    wcos_p2_adapter_assert('journal' === $durable['replay_authority'], 'Expired confirmation did not fall back to durable journal replay authority.');
    wcos_p2_adapter_assert($replay_operation === $durable['operation_id'], 'Durable replay returned the wrong operation ID.');
    wcos_p2_adapter_assert($journal['context']['plan'] === $durable['plan'], 'Durable replay did not return the journal plan exactly.');
	wcos_p2_adapter_assert((int) $journal['context']['price_precision'] === (int) $durable['price_precision'], 'Durable replay did not return journal price precision.');
	wcos_p2_adapter_assert(WCOS_Manual_Split_Quantity_Authority::LEGACY_POLICY_VERSION === $durable['manual_quantity_authority']['policy_version'], 'Durable replay silently reinterpreted legacy Manual quantity policy.');

    $hard_off = wcos_p2_replay_expect_transport(
        'workflow_disabled',
        503,
        static function() use ($replay_controller, $replay_source_id, $replay_nonce, $replay_operation) {
            $replay_controller->execute_request(array(
                'order_id' => $replay_source_id,
                'nonce' => $replay_nonce,
                'operation_id' => $replay_operation,
                'confirmation_token' => '',
            ));
        },
        'Controller treated an expired confirmation as fatal even though a durable journal existed.'
    );
    wcos_p2_adapter_assert(!$hard_off->is_retryable(), 'Hard-off durable replay response became retryable.');

    /* Direct adapter completion proves durable replay points to the reusable target set. */
    $replayed_children = (new WCOS_Split_WooCommerce_Adapter())->split(
        wc_get_order($replay_source_id),
        $durable['plan'],
        $replay_operation,
		$durable['price_precision'],
		WCOS_Split_Execution_Policy::PARTIAL_LINES_ONLY,
		array('manual_quantity_authority' => $durable['manual_quantity_authority'])
    );
    wcos_p2_adapter_assert(1 === count($replayed_children), 'Durable replay did not complete with one child.');
    wcos_p2_adapter_assert($persisted_children[0]->get_id() === $replayed_children[0]->get_id(), 'Durable replay created a duplicate child.');
    $journal = WCOS_Operation_Journal::get(wc_get_order($replay_source_id), $replay_operation);
    wcos_p2_adapter_assert('completed' === $journal['status'], 'Durable replay did not complete the original journal.');

    /* Manual reconciliation must override durable replay and receive HTTP 409. */
    wcos_p2_adapter_assert(
        WCOS_Operation_Journal::mark_manual_reconciliation(
            wc_get_order($replay_source_id),
            $replay_operation,
            array('reason' => 'transport-replay-regression', 'automatic_compensation_allowed' => false)
        ),
        'Unable to place completed replay fixture into manual reconciliation.'
    );
    wcos_p2_replay_expect_transport(
        'confirmation_manual_reconciliation',
        409,
        static function() use ($replay_controller, $replay_source_id, $replay_nonce, $replay_operation) {
            $replay_controller->execute_request(array(
                'order_id' => $replay_source_id,
                'nonce' => $replay_nonce,
                'operation_id' => $replay_operation,
                'confirmation_token' => '',
            ));
        },
        'Controller did not expose unresolved manual reconciliation as a conflict.'
    );

    wcos_p2_adapter_assert(
        WCOS_Operation_Journal::mark_manual_reconciled(
            wc_get_order($replay_source_id),
            $replay_operation,
            array('reconciliation_note' => 'durable-replay-test-resolved')
        ),
        'Unable to resolve durable replay manual reconciliation fixture.'
    );
    wcos_p2_replay_expect_transport(
        'confirmation_operation_closed',
        409,
        static function() use ($replay_controller, $replay_source_id, $replay_nonce, $replay_operation) {
            $replay_controller->execute_request(array(
                'order_id' => $replay_source_id,
                'nonce' => $replay_nonce,
                'operation_id' => $replay_operation,
                'confirmation_token' => '',
            ));
        },
        'Controller allowed replay of a manually closed operation.'
    );
} finally {
    if ($replay_operation) {
        WCOS_Split_Confirmation_Store::delete($replay_operation);
        wcos_p2_adapter_cleanup($replay_source_id, $replay_operation);
    } else {
        $source = wc_get_order($replay_source_id);
        if ($source instanceof WC_Order) {
            $source->delete(true);
        }
    }
    wp_delete_post($replay_product->get_id(), true);
    update_option('order_splitter_status_allowed', $replay_allowed_statuses);
    wp_set_current_user($replay_previous_user);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    if (!is_wp_error($replay_user_id)) {
        wp_delete_user($replay_user_id);
    }
}

echo "p2-durable-replay-ok\n";
