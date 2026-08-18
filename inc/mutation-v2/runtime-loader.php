<?php
/**
 * Authoritative mutation v2 runtime loader.
 *
 * This loader intentionally excludes the superseded
 * `class-wcos-v2-operation-lock.php`. Runtime adapters must use the strict
 * lease lock, complete commercial-state preflight, and persistent journal.
 * Loading these classes does not register a mutation endpoint.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wcos-v2-amount-allocator.php';
require_once __DIR__ . '/class-wcos-v2-metadata-policy.php';
require_once __DIR__ . '/class-wcos-v2-line-identity.php';
require_once __DIR__ . '/class-wcos-v2-split-plan.php';
require_once __DIR__ . '/class-wcos-v2-lease-lock.php';
require_once __DIR__ . '/class-wcos-v2-operation-journal.php';
require_once __DIR__ . '/class-wcos-v2-order-snapshot.php';
require_once __DIR__ . '/class-wcos-v2-split-preflight.php';
require_once __DIR__ . '/class-wcos-v2-strict-preflight.php';
