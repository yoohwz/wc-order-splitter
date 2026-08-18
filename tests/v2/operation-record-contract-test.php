<?php
/**
 * Pure-PHP operation state-machine contracts.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/mutation-v2/class-wcos-v2-operation-record.php';

/**
 * Assert a strict value.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure context.
 * @return void
 */
function wcos_v2_record_assert_same($expected, $actual, $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message);
	}
}

/**
 * Assert an exception type.
 *
 * @param string   $class    Exception class.
 * @param callable $callback Callback.
 * @param string   $message  Failure context.
 * @return void
 */
function wcos_v2_record_assert_throws($class, callable $callback, $message): void {
	try {
		$callback();
	} catch (Throwable $throwable) {
		if ($throwable instanceof $class) {
			return;
		}

		throw new RuntimeException($message . ': unexpected ' . get_class($throwable));
	}

	throw new RuntimeException($message . ': no exception was thrown.');
}

$fingerprint = hash('sha256', 'order-state-a');
$record      = WCOS_V2_Operation_Record::begin('op:split:100', $fingerprint, 'quantity_split', 1000);

wcos_v2_record_assert_same('preparing', $record['status'], 'A new operation must start in preparing state.');
wcos_v2_record_assert_same(array(), $record['target_ids'], 'A preparing operation cannot have target orders.');
wcos_v2_record_assert_same('', $record['error_code'], 'A preparing operation cannot have an error code.');
wcos_v2_record_assert_same($record, WCOS_V2_Operation_Record::resume($record, 'op:split:100', $fingerprint, 'quantity_split'), 'An identical request must resume its record.');

$committed = WCOS_V2_Operation_Record::commit($record, array(301, 300, 301), 1010);
wcos_v2_record_assert_same('committed', $committed['status'], 'The preparing operation did not commit.');
wcos_v2_record_assert_same(array(300, 301), $committed['target_ids'], 'Committed target IDs must be unique and deterministic.');
wcos_v2_record_assert_same('', $committed['error_code'], 'A committed operation cannot retain an error code.');
wcos_v2_record_assert_same($committed, WCOS_V2_Operation_Record::commit($committed, array(301, 300), 1020), 'An identical second commit must be idempotent.');

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($committed): void {
		WCOS_V2_Operation_Record::commit($committed, array(999), 1030);
	},
	'A committed operation must not change target orders.'
);

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($committed): void {
		WCOS_V2_Operation_Record::fail($committed, 'late_failure', 1030);
	},
	'A committed operation must not transition to failed.'
);

$failed = WCOS_V2_Operation_Record::fail($record, 'target_create_failed', 1011);
wcos_v2_record_assert_same('failed', $failed['status'], 'The preparing operation did not enter failed state.');
wcos_v2_record_assert_same('target_create_failed', $failed['error_code'], 'The failed operation error code is incorrect.');
wcos_v2_record_assert_same(array(), $failed['target_ids'], 'A failed operation cannot retain target IDs.');
wcos_v2_record_assert_same($failed, WCOS_V2_Operation_Record::fail($failed, 'target_create_failed', 1020), 'An identical second failure must be idempotent.');

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($failed): void {
		WCOS_V2_Operation_Record::fail($failed, 'different_error', 1020);
	},
	'A failed operation must not change its terminal error.'
);

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($failed): void {
		WCOS_V2_Operation_Record::commit($failed, array(400), 1020);
	},
	'A failed operation must not transition to committed.'
);

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($record): void {
		WCOS_V2_Operation_Record::resume($record, 'op:split:100', hash('sha256', 'changed-order-state'), 'quantity_split');
	},
	'Reusing an operation ID with a different fingerprint must fail.'
);

wcos_v2_record_assert_throws(
	LogicException::class,
	static function () use ($record, $fingerprint): void {
		WCOS_V2_Operation_Record::resume($record, 'op:split:100', $fingerprint, 'merge');
	},
	'Reusing an operation ID for another operation type must fail.'
);

wcos_v2_record_assert_throws(
	InvalidArgumentException::class,
	static function () use ($record): void {
		WCOS_V2_Operation_Record::commit($record, array(), 1020);
	},
	'A committed operation without a target order must be rejected.'
);

$malformed = $record;
$malformed['status'] = 'committed';
wcos_v2_record_assert_throws(
	InvalidArgumentException::class,
	static function () use ($malformed): void {
		WCOS_V2_Operation_Record::normalize($malformed);
	},
	'A malformed committed record must be rejected.'
);

echo "WCOS v2 operation-record contract tests passed.\n";
