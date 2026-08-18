<?php

defined('ABSPATH') || exit;

/**
 * Pins WooCommerce price precision to the value captured for one mutation.
 *
 * WooCommerce exposes price decimals through the `wc_get_price_decimals` filter.
 * A request-local scope keeps historical allocation/recovery deterministic
 * without changing the persistent store setting.
 */
final class WCOS_Price_Precision_Scope {
    const MAX_SUPPORTED_PRECISION = 6;

    private static $bootstrapped = false;
    private static $scopes = array();
    private static $stack = array();

    public static function bootstrap() {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;
        add_filter('wc_get_price_decimals', array(__CLASS__, 'filter_precision'), PHP_INT_MAX, 1);
    }

    public static function validate($precision) {
        if (!is_numeric($precision)) {
            throw new InvalidArgumentException(__('Price precision must be numeric.', 'wc-order-splitter'));
        }
        $precision = (int) $precision;
        if ($precision < 0 || $precision > self::MAX_SUPPORTED_PRECISION) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %d: maximum supported price decimal places. */
                    __('This Split workflow supports price precision from 0 to %d decimal places.', 'wc-order-splitter'),
                    self::MAX_SUPPORTED_PRECISION
                )
            );
        }
        return $precision;
    }

    public static function store_precision() {
        return self::validate(wc_get_price_decimals());
    }

    public static function for_operation(WC_Order $source, $operation_id = '') {
        $operation_id = sanitize_key((string) $operation_id);
        if ($operation_id !== '' && $source->get_id() && class_exists('WCOS_Operation_Journal')) {
            $record = WCOS_Operation_Journal::get($source, $operation_id);
            if (is_array($record)
                && isset($record['context'])
                && is_array($record['context'])
                && array_key_exists('price_precision', $record['context'])) {
                return self::validate($record['context']['price_precision']);
            }
        }
        return self::store_precision();
    }

    public static function begin($precision) {
        $precision = self::validate($precision);
        self::bootstrap();

        $token = hash('sha256', $precision . '|' . wp_generate_uuid4() . '|' . microtime(true));
        self::$scopes[$token] = $precision;
        self::$stack[] = $token;
        return $token;
    }

    public static function end($token) {
        $token = (string) $token;
        if (!isset(self::$scopes[$token])) {
            return false;
        }
        unset(self::$scopes[$token]);
        self::$stack = array_values(
            array_filter(
                self::$stack,
                static function($candidate) use ($token) {
                    return $candidate !== $token;
                }
            )
        );
        return true;
    }

    public static function has_active_scope() {
        return null !== self::current_precision();
    }

    public static function current_precision() {
        if (empty(self::$stack)) {
            return null;
        }
        $stack = self::$stack;
        $token = end($stack);
        return isset(self::$scopes[$token]) ? (int) self::$scopes[$token] : null;
    }

    public static function current_or_store_precision() {
        $precision = self::current_precision();
        return null === $precision ? self::store_precision() : $precision;
    }

    public static function filter_precision($precision) {
        $current = self::current_precision();
        return null === $current ? $precision : $current;
    }
}
