<?php
/**
 * Mutation v2 domain loader.
 *
 * This file loads only side-effect-free planners and read-only adapters. It does
 * not register a mutation endpoint or enable the legacy mutation handlers.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-wcos-v2-amount-allocator.php';
require_once __DIR__ . '/class-wcos-v2-metadata-policy.php';
require_once __DIR__ . '/class-wcos-v2-line-identity.php';
require_once __DIR__ . '/class-wcos-v2-split-plan.php';
require_once __DIR__ . '/class-wcos-v2-operation-lock.php';
require_once __DIR__ . '/class-wcos-v2-operation-journal.php';
require_once __DIR__ . '/class-wcos-v2-order-snapshot.php';
require_once __DIR__ . '/class-wcos-v2-split-preflight.php';
