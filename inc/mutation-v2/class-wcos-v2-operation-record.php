<?php
/**
 * Strict mutation operation state machine.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Builds and transitions immutable operation journal records.
 */
final class WCOS_V2_Operation_Record {

	/**
	 * Create a preparing operation record.
	 *
	 * @param string $operation_id Stable idempotency ID.
	 * @param string $fingerprint  Complete commercial-state fingerprint.
	 * @param string $type         Operation type.
	 * @param int    $timestamp    Unix timestamp.
	 * @return array
	 */
	public static function begin($operation_id, $fingerprint, $type, $timestamp = null) {
		$operation_id = self::identifier($operation_id);
		$fingerprint  = self::fingerprint($fingerprint);
		$type         = self::identifier($type);
		$timestamp    = null === $timestamp ? time() : (int) $timestamp;

		if ('' === $operation_id || '' === $fingerprint || '' === $type || $timestamp <= 0) {
			throw new InvalidArgumentException('The operation record identity is invalid.');
		}

		return array(
			'schema_version' => 1,
			'operation_id'   => $operation_id,
			'type'           => $type,
			'fingerprint'    => $fingerprint,
			'status'         => 'preparing',
			'target_ids'     => array(),
			'error_code'     => '',
			'created_at'     => $timestamp,
			'updated_at'     => $timestamp,
		);
	}

	/**
	 * Validate an existing record against an incoming idempotent request.
	 *
	 * @param array  $record       Existing record.
	 * @param string $operation_id Operation ID.
	 * @param string $fingerprint  Request fingerprint.
	 * @param string $type         Operation type.
	 * @return array Normalized record.
	 */
	public static function resume(array $record, $operation_id, $fingerprint, $type) {
		$record = self::normalize($record);

		if (!hash_equals($record['operation_id'], self::identifier($operation_id))) {
			throw new LogicException('The operation ID does not match the journal record.');
		}

		if (!hash_equals($record['fingerprint'], self::fingerprint($fingerprint))) {
			throw new LogicException('The operation ID was reused with different order data.');
		}

		if (!hash_equals($record['type'], self::identifier($type))) {
			throw new LogicException('The operation ID was reused for a different operation type.');
		}

		return $record;
	}

	/**
	 * Commit a preparing operation.
	 *
	 * An identical second commit is idempotent. A committed record can never be
	 * changed to another target set.
	 *
	 * @param array $record     Existing record.
	 * @param int[] $target_ids Created target order IDs.
	 * @param int   $timestamp  Unix timestamp.
	 * @return array
	 */
	public static function commit(array $record, array $target_ids, $timestamp = null) {
		$record     = self::normalize($record);
		$target_ids = self::target_ids($target_ids);
		$timestamp  = null === $timestamp ? time() : (int) $timestamp;

		if (empty($target_ids)) {
			throw new InvalidArgumentException('A committed mutation must reference at least one target order.');
		}

		if ('committed' === $record['status']) {
			if ($record['target_ids'] === $target_ids) {
				return $record;
			}

			throw new LogicException('A committed operation cannot change its target orders.');
		}

		if ('preparing' !== $record['status']) {
			throw new LogicException('Only a preparing operation can be committed.');
		}

		$record['status']     = 'committed';
		$record['target_ids'] = $target_ids;
		$record['error_code'] = '';
		$record['updated_at'] = max($record['updated_at'], $timestamp);

		return $record;
	}

	/**
	 * Mark a preparing operation as failed.
	 *
	 * An identical second failure transition is idempotent. Failed and committed
	 * records are terminal; a retry after failure requires a new operation ID.
	 *
	 * @param array  $record     Existing record.
	 * @param string $error_code Stable error code.
	 * @param int    $timestamp  Unix timestamp.
	 * @return array
	 */
	public static function fail(array $record, $error_code, $timestamp = null) {
		$record     = self::normalize($record);
		$error_code = self::identifier($error_code);
		$timestamp  = null === $timestamp ? time() : (int) $timestamp;

		if ('' === $error_code) {
			throw new InvalidArgumentException('A failed mutation must have a stable error code.');
		}

		if ('failed' === $record['status']) {
			if (hash_equals($record['error_code'], $error_code)) {
				return $record;
			}

			throw new LogicException('A failed operation cannot change its terminal error code.');
		}

		if ('preparing' !== $record['status']) {
			throw new LogicException('Only a preparing operation can be marked failed.');
		}

		$record['status']     = 'failed';
		$record['target_ids'] = array();
		$record['error_code'] = $error_code;
		$record['updated_at'] = max($record['updated_at'], $timestamp);

		return $record;
	}

	/**
	 * Validate and normalize a persisted operation record.
	 *
	 * @param array $record Persisted record.
	 * @return array
	 */
	public static function normalize(array $record) {
		$required = array(
			'schema_version',
			'operation_id',
			'type',
			'fingerprint',
			'status',
			'target_ids',
			'error_code',
			'created_at',
			'updated_at',
		);

		foreach ($required as $field) {
			if (!array_key_exists($field, $record)) {
				throw new InvalidArgumentException('The operation journal record is incomplete.');
			}
		}

		$status = self::identifier($record['status']);

		if (!in_array($status, array('preparing', 'committed', 'failed'), true)) {
			throw new InvalidArgumentException('The operation journal status is invalid.');
		}

		$normalized = array(
			'schema_version' => (int) $record['schema_version'],
			'operation_id'   => self::identifier($record['operation_id']),
			'type'           => self::identifier($record['type']),
			'fingerprint'    => self::fingerprint($record['fingerprint']),
			'status'         => $status,
			'target_ids'     => self::target_ids((array) $record['target_ids']),
			'error_code'     => self::identifier($record['error_code']),
			'created_at'     => (int) $record['created_at'],
			'updated_at'     => (int) $record['updated_at'],
		);

		if (1 !== $normalized['schema_version']) {
			throw new InvalidArgumentException('The operation journal schema version is unsupported.');
		}

		if ('' === $normalized['operation_id'] || '' === $normalized['type'] || '' === $normalized['fingerprint']) {
			throw new InvalidArgumentException('The operation journal identity is invalid.');
		}

		if ($normalized['created_at'] <= 0 || $normalized['updated_at'] < $normalized['created_at']) {
			throw new InvalidArgumentException('The operation journal timestamps are invalid.');
		}

		if ('preparing' === $status && (!empty($normalized['target_ids']) || '' !== $normalized['error_code'])) {
			throw new InvalidArgumentException('A preparing operation cannot have terminal data.');
		}

		if ('committed' === $status && (empty($normalized['target_ids']) || '' !== $normalized['error_code'])) {
			throw new InvalidArgumentException('A committed operation must have target orders and no error code.');
		}

		if ('failed' === $status && (!empty($normalized['target_ids']) || '' === $normalized['error_code'])) {
			throw new InvalidArgumentException('A failed operation must have an error code and no target orders.');
		}

		return $normalized;
	}

	/**
	 * Normalize an identifier.
	 *
	 * @param mixed $value Identifier.
	 * @return string
	 */
	private static function identifier($value) {
		$value = strtolower(trim((string) $value));

		return preg_replace('/[^a-z0-9._:-]/', '', $value);
	}

	/**
	 * Normalize a SHA-256 request fingerprint.
	 *
	 * @param mixed $value Fingerprint.
	 * @return string
	 */
	private static function fingerprint($value) {
		$value = strtolower(trim((string) $value));

		return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
	}

	/**
	 * Normalize target order IDs deterministically.
	 *
	 * @param array $target_ids Target IDs.
	 * @return int[]
	 */
	private static function target_ids(array $target_ids) {
		$normalized = array_values(array_unique(array_filter(array_map('intval', $target_ids), static function ($value) {
			return $value > 0;
		})));
		sort($normalized, SORT_NUMERIC);

		return $normalized;
	}
}
