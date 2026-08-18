<?php
/**
 * Execution-context and final specification contracts.
 */

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/preflight-contract-test.php';
ob_end_clean();

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-strict-preflight.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-execution-preflight.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-quantity-split-specification.php';
require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-execution-specification.php';

/**
 * Minimal tax item used by the order snapshot adapter.
 */
final class WCOS_V2_Test_Tax_Item {
	private $data;

	public function __construct(array $data) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_meta_data() {
		return array();
	}

	public function get_type() {
		return 'tax';
	}
}

/**
 * Extends the base order stub with copyable context and tax items.
 */
class WCOS_V2_Context_Test_Order extends WC_Order {
	private $context;
	private $tax_items;

	public function __construct(array $data, array $context, array $tax_items) {
		parent::__construct($data);
		$this->context   = $context;
		$this->tax_items = $tax_items;
	}

	public function get_items($type = 'line_item') {
		if ('tax' === $type) {
			return $this->tax_items;
		}

		return parent::get_items($type);
	}

	public function get_address($type) {
		return isset($this->context[$type . '_address']) ? $this->context[$type . '_address'] : array();
	}

	public function get_payment_method() {
		return $this->context['payment_method'];
	}

	public function get_payment_method_title() {
		return $this->context['payment_method_title'];
	}

	public function get_customer_note() {
		return $this->context['customer_note'];
	}

	public function get_customer_ip_address() {
		return $this->context['customer_ip_address'];
	}

	public function get_customer_user_agent() {
		return $this->context['customer_user_agent'];
	}
}

function wcos_v2_execution_assert($condition, $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function wcos_v2_context_order(array $context_overrides = array()): WCOS_V2_Context_Test_Order {
	$base_order = wcos_v2_test_order();
	$reflection = new ReflectionClass($base_order);
	$property   = $reflection->getProperty('data');
	$property->setAccessible(true);
	$data = $property->getValue($base_order);

	$context = array_replace_recursive(
		array(
			'billing_address' => array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.test',
				'country'    => 'US',
			),
			'shipping_address' => array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => '1 Test Street',
				'country'    => 'US',
			),
			'payment_method'       => 'bacs',
			'payment_method_title' => 'Direct bank transfer',
			'customer_note'        => 'Handle carefully.',
			'customer_ip_address'  => '192.0.2.10',
			'customer_user_agent'  => 'WCOS-Test/1.0',
		),
		$context_overrides
	);

	$tax_items = array(
		301 => new WCOS_V2_Test_Tax_Item(
			array(
				'rate_id'            => 1,
				'label'              => 'Test tax',
				'compound'           => false,
				'rate_code'          => 'US-TEST-1',
				'rate_percent'       => '10.0000',
				'tax_total'          => '1.40',
				'shipping_tax_total' => '0.50',
			)
		),
	);

	return new WCOS_V2_Context_Test_Order($data, $context, $tax_items);
}

$base = WCOS_V2_Execution_Preflight::validate(wcos_v2_context_order(), array(101 => '1'));
wcos_v2_execution_assert(!is_wp_error($base), 'A valid execution-complete preflight was rejected.');
wcos_v2_execution_assert('execution_complete_order_state' === $base['fingerprint_scope'], 'Execution fingerprint scope is incorrect.');
wcos_v2_execution_assert('Ada' === $base['execution_context']['billing_address']['first_name'], 'Billing context was not captured.');
wcos_v2_execution_assert('bacs' === $base['execution_context']['payment_method'], 'Payment context was not captured.');

$address_changed = WCOS_V2_Execution_Preflight::validate(
	wcos_v2_context_order(array('shipping_address' => array('address_1' => '2 Changed Street'))),
	array(101 => '1')
);
wcos_v2_execution_assert(!is_wp_error($address_changed), 'Changed address context produced an unexpected preflight error.');
wcos_v2_execution_assert($base['fingerprint'] !== $address_changed['fingerprint'], 'Shipping address changes must alter the execution fingerprint.');

$payment_changed = WCOS_V2_Execution_Preflight::validate(
	wcos_v2_context_order(array('payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery')),
	array(101 => '1')
);
wcos_v2_execution_assert(!is_wp_error($payment_changed), 'Changed payment context produced an unexpected preflight error.');
wcos_v2_execution_assert($base['fingerprint'] !== $payment_changed['fingerprint'], 'Payment method changes must alter the execution fingerprint.');

$specification = WCOS_V2_Execution_Specification::build($base);
wcos_v2_execution_assert('execution_complete_order_state' === $specification['fingerprint_scope'], 'The final specification lost execution scope.');
wcos_v2_execution_assert('Ada' === $specification['child_context']['billing_address']['first_name'], 'The final specification lost billing context.');
wcos_v2_execution_assert('bacs' === $specification['child_context']['payment_method'], 'The final specification lost payment context.');
wcos_v2_execution_assert('wc-order-splitter-v2' === $specification['child_context']['created_via'], 'The child created_via marker is incorrect.');
wcos_v2_execution_assert('' === $specification['child_context']['transaction_id'], 'The source transaction ID must not enter child context.');
wcos_v2_execution_assert(false === $specification['settlement']['copy_transaction_id'], 'Settlement policy must retain transaction ownership on the source.');
wcos_v2_execution_assert('suppress_target_transactional_emails' === $specification['notification_policy']['build_phase'], 'The build-phase notification policy is incorrect.');

$changed_specification = WCOS_V2_Execution_Specification::build($address_changed);
wcos_v2_execution_assert($specification['fingerprint'] !== $changed_specification['fingerprint'], 'A changed child copy context must alter the final specification fingerprint.');

$repeat = WCOS_V2_Execution_Specification::build($base);
wcos_v2_execution_assert($specification['fingerprint'] === $repeat['fingerprint'], 'An identical final specification must have a stable fingerprint.');

echo "WCOS v2 execution-context contract tests passed.\n";
