<?php

defined('ABSPATH') || exit;

/**
 * Strict parser for the manual quantity-Split admin transport.
 *
 * JSON quantities must be decimal strings so binary floating-point values never
 * enter the idempotency or allocation contract.
 */
final class WCOS_Split_Request_Parser {
    const MAX_PAYLOAD_BYTES = 65536;
    const MAX_CHILDREN = 10;
    const MAX_LINE_ASSIGNMENTS = 500;

    public static function parse_json($raw_plan, WC_Order $source, array $quantity_authority) {
        if (!is_string($raw_plan)) {
            throw new InvalidArgumentException(__('The Split plan must be a JSON string.', 'wc-order-splitter'));
        }
        $raw_plan = trim($raw_plan);
        if ('' === $raw_plan || strlen($raw_plan) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(__('The Split plan is empty or exceeds the supported request size.', 'wc-order-splitter'));
        }

        $decoded = json_decode($raw_plan, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            throw new InvalidArgumentException(__('The Split plan contains invalid JSON.', 'wc-order-splitter'));
        }
        return self::parse_array($decoded, $source, $quantity_authority);
    }

    public static function parse_array(array $plan, WC_Order $source, array $quantity_authority) {
        if (empty($plan) || count($plan) > self::MAX_CHILDREN) {
            throw new InvalidArgumentException(__('A Split plan must contain between one and ten child orders.', 'wc-order-splitter'));
        }

        try {
            $quantity_authority = WCOS_Manual_Split_Quantity_Authority::assert_valid($quantity_authority);
        } catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
        if ($source->get_id() !== (int) $quantity_authority['source_order_id']
            || !hash_equals(
                (string) $quantity_authority['source_signature'],
                WCOS_Order_Contract_Snapshot::source_signature($source)
            )) {
            throw new InvalidArgumentException(__('The source order no longer matches the reviewed Manual Split quantity authority.', 'wc-order-splitter'));
        }

        $strict = array();
        $assignments = 0;
        foreach ($plan as $raw_child_key => $items) {
            $child_key = (string) $raw_child_key;
            if (!preg_match('/^child-(?:[1-9]|10)$/D', $child_key)) {
                throw new InvalidArgumentException(__('Split child keys must use the server-supported child-1 through child-10 format.', 'wc-order-splitter'));
            }
            if (!is_array($items) || empty($items)) {
                throw new InvalidArgumentException(__('Every Split child must contain at least one line quantity.', 'wc-order-splitter'));
            }

            $strict[$child_key] = array();
            foreach ($items as $raw_item_id => $quantity) {
                $item_key = (string) $raw_item_id;
                if (!preg_match('/^[1-9][0-9]*$/D', $item_key)) {
                    throw new InvalidArgumentException(__('Split item IDs must be positive decimal integers.', 'wc-order-splitter'));
                }
                if (!is_string($quantity) || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $quantity)) {
                    throw new InvalidArgumentException(__('Split quantities must be decimal strings with at most six decimal places.', 'wc-order-splitter'));
                }

                $item_id = absint($item_key);
                try {
                    $quantity_units = WCOS_Decimal::to_units($quantity, 6);
                } catch (OverflowException $exception) {
                    throw new InvalidArgumentException(__('A Split quantity exceeds the supported numeric range.', 'wc-order-splitter'), 0, $exception);
                }
                if (!$item_id || $quantity_units <= 0) {
                    throw new InvalidArgumentException(__('Split item IDs and quantities must be positive.', 'wc-order-splitter'));
                }
                if (!isset($quantity_authority['lines'][$item_id])) {
                    throw new InvalidArgumentException(__('The Split plan references an item outside the source order.', 'wc-order-splitter'));
                }
                if (isset($strict[$child_key][$item_id])) {
                    throw new InvalidArgumentException(__('The Split plan contains a duplicate item assignment for one child.', 'wc-order-splitter'));
                }

                $strict[$child_key][$item_id] = WCOS_Decimal::from_units($quantity_units, 6);
                $assignments++;
                if ($assignments > self::MAX_LINE_ASSIGNMENTS) {
                    throw new InvalidArgumentException(__('The Split plan contains too many line assignments.', 'wc-order-splitter'));
                }
            }
        }

        try {
            return WCOS_Manual_Split_Quantity_Authority::assert_plan($strict, $quantity_authority);
        } catch (WCOS_Manual_Split_Quantity_Authority_Exception $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        } catch (OverflowException $exception) {
            throw new InvalidArgumentException(__('The Split plan exceeds the supported numeric range.', 'wc-order-splitter'), 0, $exception);
        }
    }
}
