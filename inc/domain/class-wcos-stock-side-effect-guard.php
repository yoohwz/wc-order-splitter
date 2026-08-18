<?php

defined('ABSPATH') || exit;

/**
 * Raised when the current order-only mutation request writes physical product stock.
 */
final class WCOS_Unexpected_Stock_Mutation_Exception extends RuntimeException {
	private $events;

	public function __construct(array $events, Throwable $previous = null) {
		$this->events = array_values($events);
		parent::__construct(
			__('Physical product stock was changed by the current order-mutation request.', 'wc-order-splitter'),
			0,
			$previous
		);
	}

	public function get_events() {
		return $this->events;
	}
}

/**
 * Request-local observer for WooCommerce stock writes.
 *
 * A concurrent checkout runs in another request/process and therefore does not
 * dirty this guard. Stock writes caused by hooks executing inside the mutation
 * request do dirty it, including parent-managed variation stock writes because
 * WooCommerce emits the set-stock hook for the actual stock-owning product.
 */
final class WCOS_Stock_Side_Effect_Guard {
	private static $bootstrapped = false;
	private static $scopes = array();
	private static $stack = array();

	public static function bootstrap() {
		if (self::$bootstrapped) {
			return;
		}
		self::$bootstrapped = true;
		add_action('woocommerce_product_set_stock', array(__CLASS__, 'record_product_stock_write'), PHP_INT_MAX, 1);
		add_action('woocommerce_variation_set_stock', array(__CLASS__, 'record_product_stock_write'), PHP_INT_MAX, 1);
	}

	public static function begin($operation_id) {
		$operation_id = sanitize_key((string) $operation_id);
		if ('' === $operation_id) {
			throw new InvalidArgumentException(__('A stock-observer operation ID is required.', 'wc-order-splitter'));
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
		$token = self::current_token();
		return null !== $token && !empty(self::$scopes[$token]['events']);
	}

	public static function current_events() {
		$token = self::current_token();
		return null === $token ? array() : self::events($token);
	}

	public static function events($token) {
		$token = (string) $token;
		return isset(self::$scopes[$token]['events']) ? array_values(self::$scopes[$token]['events']) : array();
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

	public static function record_product_stock_write($product) {
		if (empty(self::$scopes) || !$product instanceof WC_Product) {
			return;
		}
		$managed_id = method_exists($product, 'get_stock_managed_by_id')
			? absint($product->get_stock_managed_by_id())
			: absint($product->get_id());
		$quantity = $product->get_stock_quantity();
		$event = array(
			'product_id' => absint($product->get_id()),
			'stock_owner_id' => $managed_id,
			'product_type' => sanitize_key((string) $product->get_type()),
			'stock_quantity' => null === $quantity ? null : WCOS_Decimal::normalize($quantity, 6),
		);
		foreach (array_keys(self::$scopes) as $token) {
			self::$scopes[$token]['events'][] = $event;
		}
	}

	private static function current_token() {
		if (empty(self::$stack)) {
			return null;
		}
		$token = end(self::$stack);
		reset(self::$stack);
		return isset(self::$scopes[$token]) ? $token : null;
	}
}
