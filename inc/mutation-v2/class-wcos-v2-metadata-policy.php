<?php
/**
 * Order-item metadata copy and identity policy.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Separates business metadata from mutation-owned technical metadata.
 */
final class WCOS_V2_Metadata_Policy {

	/**
	 * Metadata that is owned by stock or order-mutation lifecycle code.
	 *
	 * @var string[]
	 */
	private const TECHNICAL_KEYS = array(
		'_reduced_stock',
		'_wcos_operation_id',
		'_wcos_original_order_id',
		'_wcos_split_children',
		'_wcos_v2_operation_journal',
		'yoos_original_order',
		'yoos_splitted_order',
	);

	/**
	 * Determine whether metadata may be copied to a newly constructed item.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	public static function should_copy($key) {
		$key = (string) $key;

		if (in_array($key, self::TECHNICAL_KEYS, true)) {
			return false;
		}

		return 0 !== strpos($key, '_wcos_v2_');
	}

	/**
	 * Determine whether metadata participates in commercial line identity.
	 *
	 * Add-on, bundle, vendor, personalization and other protected metadata is
	 * intentionally retained. A leading underscore alone does not make a key
	 * non-commercial.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	public static function participates_in_identity($key) {
		return self::should_copy($key);
	}

	/**
	 * Normalize a list of metadata records without losing duplicate keys.
	 *
	 * @param array $records Metadata records containing key and value.
	 * @param bool  $identity_only Whether to keep only identity metadata.
	 * @return array
	 */
	public static function normalize_records(array $records, $identity_only = false) {
		$normalized = array();

		foreach ($records as $record) {
			if (is_object($record) && isset($record->key)) {
				$key   = (string) $record->key;
				$value = isset($record->value) ? $record->value : null;
			} elseif (is_array($record) && array_key_exists('key', $record)) {
				$key   = (string) $record['key'];
				$value = array_key_exists('value', $record) ? $record['value'] : null;
			} else {
				continue;
			}

			$allowed = $identity_only ? self::participates_in_identity($key) : self::should_copy($key);

			if (!$allowed) {
				continue;
			}

			$normalized[] = array(
				'key'   => $key,
				'value' => self::normalize_value($value),
			);
		}

		usort(
			$normalized,
			static function (array $left, array $right) {
				$left_key  = $left['key'] . "\0" . self::encode_value($left['value']);
				$right_key = $right['key'] . "\0" . self::encode_value($right['value']);

				return strcmp($left_key, $right_key);
			}
		);

		return $normalized;
	}

	/**
	 * Recursively normalize metadata values for stable identity signatures.
	 *
	 * @param mixed $value Metadata value.
	 * @return mixed
	 */
	private static function normalize_value($value) {
		if (is_object($value)) {
			$value = get_object_vars($value);
		}

		if (!is_array($value)) {
			return $value;
		}

		$normalized = array();

		foreach ($value as $key => $nested_value) {
			$normalized[$key] = self::normalize_value($nested_value);
		}

		if (self::is_associative($normalized)) {
			ksort($normalized, SORT_STRING);
		}

		return $normalized;
	}

	/**
	 * Encode a normalized value for sorting.
	 *
	 * @param mixed $value Normalized value.
	 * @return string
	 */
	private static function encode_value($value) {
		$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		return false === $encoded ? serialize($value) : $encoded;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_associative(array $value) {
		if (array() === $value) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}
}
