<?php
/**
 * Operation-scoped child transactional email suppression.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

/**
 * Suppresses only the target order being assembled by one mutation operation.
 */
final class WCOS_V2_Notification_Scope {

	/**
	 * WooCommerce email IDs that may be triggered by order creation/status work.
	 *
	 * @var string[]
	 */
	private const EMAIL_IDS = array(
		'new_order',
		'cancelled_order',
		'failed_order',
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_refunded_order',
		'customer_invoice',
		'customer_note',
	);

	/**
	 * Operation ID.
	 *
	 * @var string
	 */
	private $operation_id;

	/**
	 * Whether filters are registered.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Create and activate a scope.
	 *
	 * @param string $operation_id Operation ID.
	 */
	public function __construct($operation_id) {
		$this->operation_id = self::identifier($operation_id);

		if ('' === $this->operation_id) {
			throw new InvalidArgumentException('A valid operation ID is required for notification scoping.');
		}

		$this->open();
	}

	/**
	 * Register scoped recipient filters.
	 *
	 * @return void
	 */
	public function open() {
		if ($this->active) {
			return;
		}

		foreach (self::EMAIL_IDS as $email_id) {
			add_filter('woocommerce_email_recipient_' . $email_id, array($this, 'filter_recipient'), PHP_INT_MAX, 3);
		}

		$this->active = true;
	}

	/**
	 * Remove every filter registered by this scope.
	 *
	 * @return void
	 */
	public function close() {
		if (!$this->active) {
			return;
		}

		foreach (self::EMAIL_IDS as $email_id) {
			remove_filter('woocommerce_email_recipient_' . $email_id, array($this, 'filter_recipient'), PHP_INT_MAX);
		}

		$this->active = false;
	}

	/**
	 * Return an empty recipient only for this operation's child order.
	 *
	 * @param string $recipient Current recipient.
	 * @param mixed  $object    Email object, usually WC_Order.
	 * @param mixed  $email     WC_Email instance.
	 * @return string
	 */
	public function filter_recipient($recipient, $object = null, $email = null) {
		if (!$object instanceof WC_Order) {
			return $recipient;
		}

		$operation_id = self::identifier($object->get_meta('_wcos_v2_operation_id', true));
		$created_via  = (string) $object->get_created_via();

		if (hash_equals($this->operation_id, $operation_id) && 'wc-order-splitter-v2' === $created_via) {
			return '';
		}

		return $recipient;
	}

	/**
	 * Ensure filters cannot leak beyond object lifetime.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Normalize an operation ID.
	 *
	 * @param mixed $value ID.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}
}
