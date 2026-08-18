<?php
/**
 * Execution-complete quantity split specification.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Adds immutable child-copy context to the conservation-first write plan.
 */
final class WCOS_V2_Execution_Specification {

	/**
	 * Build an execution-complete specification.
	 *
	 * @param array $preflight Result from WCOS_V2_Execution_Preflight::validate().
	 * @return array
	 */
	public static function build(array $preflight) {
		if (!isset($preflight['fingerprint_scope']) || 'execution_complete_order_state' !== $preflight['fingerprint_scope']) {
			throw new InvalidArgumentException('An execution-complete preflight is required.');
		}

		if (empty($preflight['execution_context']) || empty($preflight['base_strict_fingerprint'])) {
			throw new InvalidArgumentException('The execution preflight context is incomplete.');
		}

		$base_input                      = $preflight;
		$base_input['fingerprint_scope'] = 'complete_commercial_order_state';
		$base_input['fingerprint']       = $preflight['fingerprint'];
		$specification                   = WCOS_V2_Quantity_Split_Specification::build($base_input);
		$context                         = $preflight['execution_context'];

		$specification['source_fingerprint']       = (string) $preflight['fingerprint'];
		$specification['base_strict_fingerprint']  = (string) $preflight['base_strict_fingerprint'];
		$specification['fingerprint_scope']        = 'execution_complete_order_state';
		$specification['child_context']            = array(
			'billing_address'      => isset($context['billing_address']) ? (array) $context['billing_address'] : array(),
			'shipping_address'     => isset($context['shipping_address']) ? (array) $context['shipping_address'] : array(),
			'payment_method'       => isset($context['payment_method']) ? (string) $context['payment_method'] : '',
			'payment_method_title' => isset($context['payment_method_title']) ? (string) $context['payment_method_title'] : '',
			'customer_note'        => isset($context['customer_note']) ? (string) $context['customer_note'] : '',
			'customer_ip_address'  => isset($context['customer_ip_address']) ? (string) $context['customer_ip_address'] : '',
			'customer_user_agent'  => isset($context['customer_user_agent']) ? (string) $context['customer_user_agent'] : '',
			'created_via'          => 'wc-order-splitter-v2',
			'transaction_id'       => '',
		);
		$specification['notification_policy']      = array(
			'build_phase' => 'suppress_target_transactional_emails',
			'after_commit' => 'explicit_status_transition_only',
		);
		$specification['fingerprint'] = hash('sha256', self::canonical_json($specification));

		return $specification;
	}

	/**
	 * Canonically encode a specification.
	 *
	 * @param array $value Specification.
	 * @return string
	 */
	private static function canonical_json(array $value) {
		$value = self::canonicalize($value);
		$json  = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (false === $json) {
			throw new LogicException('Unable to encode the execution specification.');
		}

		return $json;
	}

	/**
	 * Recursively sort associative arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize($value) {
		if (!is_array($value)) {
			return $value;
		}

		$result = array();

		foreach ($value as $key => $nested) {
			$result[$key] = self::canonicalize($nested);
		}

		if (array() !== $result && array_keys($result) !== range(0, count($result) - 1)) {
			ksort($result, SORT_STRING);
		}

		return $result;
	}
}
