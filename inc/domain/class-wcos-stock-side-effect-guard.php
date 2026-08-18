<?php

defined('ABSPATH') || exit;

/**
 * Raised when the current order-only mutation request attempts or performs a
 * physical product-stock write.
 */
final class WCOS_Unexpected_Stock_Mutation_Exception extends RuntimeException {
	private $events;

	public function __construct(array $events, Throwable $previous = null) {
		$this->events = array_values($events);
		parent::__construct(
			__('A physical product-stock write was attempted during the current order-mutation request.', 'wc-order-splitter'),
			0,
			$previous
		);
	}

	public function get_events() {
		return $this->events;
	}
}

/**
 * Request-local guard for WooCommerce stock writes.
 *
 * Core WooCommerce stock writes are blocked at the before-set-stock hook, before
 * the data store is changed. The corresponding after-set-stock hooks remain
 * observed as a fallback for integrations that somehow enter the active request
 * after a physical stock write. Concurrent checkouts are different requests and
 * therefore cannot dirty this scope.
 */
final class WCOS_Stock_Side_Effect_Guard {
	const PHASE_BLOCKED_BEFORE_WRITE = 'blocked_before_write';
	const PHASE_OBSERVED_AFTER_WRITE = 'observed_after_write';

	private static $bootstrapped = false;
	private static $scopes = array();
	private static $stack = array();

	public static function bootstrap() {
		if (self::$bootstrapped) {
			return;
		}
		self::$bootstrapped = true;
		add_action('woocommerce_product_before_set_stock', array(__CLASS__, 'block_product_stock_write'), PHP_INT_MIN, 1);
		add_action('woocommerce_variation_before_set_stock', array(__CLASS__, 'block_product_stock_write'), PHP_INT_MIN, 1);
		add_action('woocommerce_product_set_stock', array(__CLASS__, 'record_product_stock_write'), PHP_INT_MAX, 1);
		add_action('woocommerce_variation_set_stock', array(__CLASS__, 'record_product_stock_write'), PHP_INT_MAX, 1);
	}

	public static function begin($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A stock-guard operation ID is required.', 'wc-order-splitter'));
		}
		self::bootstrap();
		$token = hash('sha256', $operation_id . '|' . wp_generate_uuid4() . '|' . microtime(true));
		self::$scopes[$token] = array(
			'operation_id' => $operation_id,
			'events' => array(),
		);
		self::$stack[] = $token;
		return $token;
	}

	public static function end($token) {
		$token = (string) $token;
		if (!isset(self::$scopes[$token])) {
			return false;
		}
		unset(self::$scopes[$token]);
		self::$stack = array_values(
			array_filter(
				self::$stack,
				static function($active_token) use ($token) {
					return $active_token !== $token;
				}
			)
		);
		return true;
	}

	public static function has_active_scope() {
		return !empty(self::$stack);
	}

	public static function has_dirty_active_scope() {
		return !empty(self::current_events());
	}

	public static function has_physical_write_active_scope() {
		return self::events_require_manual_reconciliation(self::current_events());
	}

	public static function current_events() {
		$token = self::current_token();
		return null === $token ? array() : self::events($token);
	}

	public static function events($token) {
		$token = (string) $token;
		return isset(self::$scopes[$token]['events']) ? array_values(self::$scopes[$token]['events']) : array();
	}

	public static function events_require_manual_reconciliation(array $events) {
		foreach ($events as $event) {
			if (isset($event['phase']) && self::PHASE_OBSERVED_AFTER_WRITE === $event['phase']) {
				return true;
			}
		}
		return false;
	}

	public static function assert_current_clean() {
		$events = self::current_events();
		if (!empty($events)) {
			throw new WCOS_Unexpected_Stock_Mutation_Exception($events);
		}
	}

	public static function assert_clean($token) {
		$events = self::events($token);
		if (!empty($events)) {
			throw new WCOS_Unexpected_Stock_Mutation_Exception($events);
		}
	}

	public static function block_product_stock_write($product) {
		if (empty(self::$scopes) || !$product instanceof WC_Product) {
			return;
		}
		self::append_event(self::event($product, self::PHASE_BLOCKED_BEFORE_WRITE));
		throw new WCOS_Unexpected_Stock_Mutation_Exception(self::current_events());
	}

	public static function record_product_stock_write($product) {
		if (empty(self::$scopes) || !$product instanceof WC_Product) {
			return;
		}
		self::append_event(self::event($product, self::PHASE_OBSERVED_AFTER_WRITE));
	}

	private static function event(WC_Product $product, $phase) {
		$managed_id = method_exists($product, 'get_stock_managed_by_id')
			? absint($product->get_stock_managed_by_id())
			: absint($product->get_id());
		$quantity = $product->get_stock_quantity();
		return array(
			'phase' => sanitize_key((string) $phase),
			'product_id' => absint($product->get_id()),
			'stock_owner_id' => $managed_id,
			'product_type' => sanitize_key((string) $product->get_type()),
			'stock_quantity' => null === $quantity ? null : WCOS_Decimal::normalize($quantity, 6),
		);
	}

	private static function append_event(array $event) {
		foreach (array_keys(self::$scopes) as $token) {
			self::$scopes[$token]['events'][] = $event;
		}
	}

	private static function current_token() {
		if (empty(self::$stack)) {
			return null;
		}
		$stack = self::$stack;
		$token = end($stack);
		return isset(self::$scopes[$token]) ? $token : null;
	}
}
