<?php
/**
 * Complete gated quantity split adapter loader.
 *
 * Requiring this file defines adapter classes only. It does not register any
 * executable entry point and does not change WC_ORDER_SPLITTER_MUTATIONS_ENABLED.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/quantity-split-loader.php';
require_once __DIR__ . '/class-wcos-v2-execution-preflight.php';
require_once __DIR__ . '/class-wcos-v2-execution-specification.php';
require_once __DIR__ . '/class-wcos-v2-order-item-mutator.php';
require_once __DIR__ . '/class-wcos-v2-order-mutator.php';
require_once __DIR__ . '/class-wcos-v2-postcondition-verifier.php';
require_once __DIR__ . '/class-wcos-v2-notification-scope.php';
require_once __DIR__ . '/class-wcos-v2-mutation-failure.php';
require_once __DIR__ . '/class-wcos-v2-quantity-split-executor.php';
