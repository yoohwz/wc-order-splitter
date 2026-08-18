<?php

if (!defined('ABSPATH') && 'cli' !== PHP_SAPI) {
	exit;
}

/**
 * Creates a stable fingerprint for the immutable semantics of a mutation
 * request. Reusing an operation ID with a different fingerprint is rejected.
 */
final class WCOS_Mutation_Fingerprint {

	public static function create($type, $source_order_id, array $payload = array()) {
		$document = array(
			'type' => (string) $type,
			'source_order_id' => (int) $source_order_id,
			'payload' => self::canonicalize($payload),
		);

		$json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (false === $json) {
			throw new InvalidArgumentException('Mutation fingerprint payload could not be encoded.');
		}

		return hash('sha256', $json);
	}

	private static function canonicalize($value) {
		if (is_array($value)) {
			if (self::is_list($value)) {
				$result = array();
				foreach ($value as $item) {
					$result[] = self::canonicalize($item);
				}
				return $result;
			}

			ksort($value, SORT_STRING);
			foreach ($value as $key => $item) {
				$value[$key] = self::canonicalize($item);
			}
			return $value;
		}

		if (is_object($value) || is_resource($value)) {
			throw new InvalidArgumentException('Mutation fingerprint payload must contain only scalar values and arrays.');
		}

		return $value;
	}

	private static function is_list(array $value) {
		$expected = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expected++) {
				return false;
			}
		}
		return true;
	}
}
