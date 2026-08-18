<?php
/**
 * Stable mutation failure exception wrapper.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Carries a machine-readable error key without abusing Exception's integer code.
 */
final class WCOS_V2_Mutation_Failure extends RuntimeException {

	/**
	 * Stable error key.
	 *
	 * @var string
	 */
	private $error_key;

	/**
	 * Optional structured error data.
	 *
	 * @var array
	 */
	private $error_data;

	/**
	 * Create a mutation failure.
	 *
	 * @param string         $error_key Stable error key.
	 * @param string         $message   Human-readable message.
	 * @param array          $error_data Structured data.
	 * @param Throwable|null $previous   Previous throwable.
	 */
	public function __construct($error_key, $message, array $error_data = array(), Throwable $previous = null) {
		$this->error_key  = self::normalize_key($error_key);
		$this->error_data = $error_data;

		parent::__construct((string) $message, 0, $previous);
	}

	/**
	 * Build a failure from the first WP_Error entry.
	 *
	 * @param WP_Error $error WordPress error.
	 * @return self
	 */
	public static function from_wp_error(WP_Error $error) {
		$code = (string) $error->get_error_code();
		$data = $error->get_error_data($code);

		return new self(
			'' !== $code ? $code : 'wcos_unknown_error',
			$error->get_error_message($code),
			is_array($data) ? $data : array()
		);
	}

	/**
	 * Throw when a component returned WP_Error.
	 *
	 * @param mixed $value Component result.
	 * @return mixed Original value.
	 */
	public static function unwrap($value) {
		if (is_wp_error($value)) {
			throw self::from_wp_error($value);
		}

		return $value;
	}

	/**
	 * Return the stable error key.
	 *
	 * @return string
	 */
	public function get_error_key() {
		return $this->error_key;
	}

	/**
	 * Return structured error data.
	 *
	 * @return array
	 */
	public function get_error_data() {
		return $this->error_data;
	}

	/**
	 * Convert to WP_Error.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error() {
		return new WP_Error($this->error_key, $this->getMessage(), $this->error_data);
	}

	/**
	 * Normalize an error key.
	 *
	 * @param mixed $value Key.
	 * @return string
	 */
	private static function normalize_key($value) {
		$value = strtolower(trim((string) $value));
		$value = preg_replace('/[^a-z0-9_\-]/', '', $value);

		return '' === $value ? 'wcos_unknown_error' : $value;
	}
}
