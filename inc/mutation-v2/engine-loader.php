<?php
/**
 * Authoritative safe mutation v2 engine loader.
 *
 * This loader contains no action or filter registration. Runtime mutation
 * adapters must require this file and remain separately gated until their full
 * WooCommerce integration matrix is green.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wcos-v2-amount-allocator.php';
require_once __DIR__ . '/class-wcos-v2-metadata-policy.php';
require_once __DIR__ . '/class-wcos-v2-line-identity.php';
require_once __DIR__ . '/class-wcos-v2-split-plan.php';
require_once __DIR__ . '/class-wcos-v2-lease-lock.php';
require_once __DIR__ . '/class-wcos-v2-operation-record.php';
require_once __DIR__ . '/class-wcos-v2-operation-ledger.php';
require_once __DIR__ . '/class-wcos-v2-order-snapshot.php';
require_once __DIR__ . '/class-wcos-v2-split-preflight.php';
require_once __DIR__ . '/class-wcos-v2-strict-preflight.php';
