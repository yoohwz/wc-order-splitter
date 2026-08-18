<?php
/**
 * Complete safe quantity split dependency loader.
 *
 * Loading this file still does not register an HTTP, AJAX, CLI, cron, or admin
 * mutation entry point. The write adapter remains separately gated.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/engine-loader.php';
require_once __DIR__ . '/class-wcos-v2-quantity-split-specification.php';
require_once __DIR__ . '/class-wcos-v2-recovery-context.php';
require_once __DIR__ . '/class-wcos-v2-relation-repository.php';
