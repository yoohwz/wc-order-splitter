<?php
/**
 * Authoritative stock-gated quantity split service loader.
 *
 * Loading this file defines the complete service boundary but registers no
 * AJAX, REST, CLI, cron, or admin mutation entry point.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/service-loader.php';
require_once __DIR__ . '/class-wcos-v2-stock-safety-scope.php';
require_once __DIR__ . '/class-wcos-v2-safe-quantity-split-service.php';
