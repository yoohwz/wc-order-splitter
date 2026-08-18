<?php
/**
 * Authoritative request-bound quantity split service loader.
 *
 * This file defines the service boundary but still registers no runtime entry
 * point. Production use remains blocked by WC_ORDER_SPLITTER_MUTATIONS_ENABLED.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/adapter-loader.php';
require_once __DIR__ . '/class-wcos-v2-quantity-split-service.php';
