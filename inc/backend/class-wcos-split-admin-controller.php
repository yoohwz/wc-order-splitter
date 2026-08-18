<?php

defined('ABSPATH') || exit;

final class WCOS_Split_Transport_Exception extends RuntimeException {
    private $error_code;
    private $http_status;
    private $retryable;
    private $context;

    public function __construct($error_code, $message, $http_status = 400, $retryable = false, array $context = array()) {
        $this->error_code = sanitize_key((string) $error_code);
        $this->http_status = max(400, min(599, (int) $http_status));
        $this->retryable = (bool) $retryable;
        $this->context = $context;
        parent::__construct((string) $message);
    }

    public function get_error_code() { return $this->error_code; }
    public function get_http_status() { return $this->http_status; }
    public function is_retryable() { return $this->retryable; }
    public function get_context() { return $this->context; }
}

/**
 * Production admin transport for manual quantity Split.
 *
 * The controller is safe to bootstrap while the feature gate is hard-off:
 * review remains read-only, execute reaches the mandatory gateway and is
 * rejected until the final human gate enables Split.
 */
final class WCOS_Split_Admin_Controller {
    const REVIEW_ACTION = 'wcos_split_review';
    const EXECUTE_ACTION = 'wcos_split_execute';

    private $current_order = null;
    private $current_preflight = null;
    private $current_surface_supported = false;

    public function __construct() {
        add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
        add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 20, 1);
        add_action('admin_footer', array($this, 'render_dialog'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function review_request(array $request) {
        $order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
        $order = $this->authorized_order($request, $order_id);
        $raw_plan = isset($request['plan']) ? (string) $request['plan'] : '';

        try {
            $plan = WCOS_Split_Request_Parser::parse_json($raw_plan, $order);
        } catch (InvalidArgumentException $exception) {
            throw new WCOS_Split_Transport_Exception('invalid_plan', $exception->getMessage(), 422, false);
        }

        $preflight = (new WCOS_Mutation_Gateway())->split_preflight($order);
        if (empty($preflight['supported'])) {
            throw new WCOS_Split_Transport_Exception(
                'preflight_' . (isset($preflight['reason']) ? $preflight['reason'] : 'unsupported'),
                isset($preflight['message']) ? $preflight['message'] : __('This order is not supported by the current Split policy.', 'wc-order-splitter'),
                409,
                false,
                array('preflight' => $preflight)
            );
        }

        $confirmation = WCOS_Split_Confirmation_Store::create($order, $plan, $preflight, get_current_user_id());
        return array(
            'operation_id' => $confirmation['operation_id'],
            'confirmation_token' => $confirmation['confirmation_token'],
            'expires_at' => $confirmation['expires_at'],
            'preflight' => $preflight,
            'summary' => $this->plan_summary($plan),
        );
    }

    public function execute_request(array $request) {
        $order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
        $order = $this->authorized_order($request, $order_id);
        $operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
        $confirmation_token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';

        try {
            $confirmation = WCOS_Split_Confirmation_Store::verify(
                $order,
                $operation_id,
                $confirmation_token,
                get_current_user_id()
            );
        } catch (WCOS_Split_Confirmation_Exception $exception) {
            $http_statuses = array(
                'invalid_identity' => 400,
                'invalid_token' => 403,
                'owner_mismatch' => 403,
                'expired' => 410,
                'source_changed' => 409,
                'source_missing' => 404,
                'policy_changed' => 409,
                'precision_mismatch' => 409,
            );
            $reason = $exception->get_reason();
            throw new WCOS_Split_Transport_Exception(
                'confirmation_' . $reason,
                $exception->getMessage(),
                isset($http_statuses[$reason]) ? $http_statuses[$reason] : 403,
                false
            );
        }

        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)) {
            throw new WCOS_Split_Transport_Exception(
                'workflow_disabled',
                __('Manual quantity Split is not enabled for production use yet.', 'wc-order-splitter'),
                503,
                false
            );
        }

        try {
            $children = (new WCOS_Mutation_Gateway())->split(
                $order,
                $confirmation['plan'],
                $operation_id,
                $confirmation['price_precision']
            );
        } catch (WCOS_Split_Preflight_Exception $exception) {
            throw new WCOS_Split_Transport_Exception(
                'preflight_' . $exception->get_reason(),
                $exception->getMessage(),
                409,
                false,
                array('preflight' => $exception->get_report())
            );
        } catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
            throw new WCOS_Split_Transport_Exception(
                'manual_reconciliation_required',
                __('The Split request detected an unexpected physical-stock side effect and now requires manual reconciliation.', 'wc-order-splitter'),
                409,
                false
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (false !== strpos($message, 'Another order mutation is already in progress')) {
                throw new WCOS_Split_Transport_Exception('operation_busy', $message, 409, true);
            }
            throw new WCOS_Split_Transport_Exception('split_failed', $message, 409, true);
        }

        $result_children = array();
        foreach ($children as $child) {
            if (!$child instanceof WC_Order) {
                continue;
            }
            $result_children[] = array(
                'id' => $child->get_id(),
                'number' => (string) $child->get_order_number(),
                'status' => (string) $child->get_status(),
                'edit_url' => method_exists($child, 'get_edit_order_url') ? (string) $child->get_edit_order_url() : '',
            );
        }

        return array(
            'operation_id' => $operation_id,
            'status' => 'completed',
            'source_order_id' => $order->get_id(),
            'children' => $result_children,
        );
    }

    public function ajax_review() {
        try {
            wp_send_json_success($this->review_request(wp_unslash($_POST)));
        } catch (WCOS_Split_Transport_Exception $exception) {
            $this->send_transport_error($exception);
        } catch (Throwable $throwable) {
            $this->send_transport_error(new WCOS_Split_Transport_Exception('review_failed', __('Unable to review the Split plan.', 'wc-order-splitter'), 500, true));
        }
    }

    public function ajax_execute() {
        try {
            wp_send_json_success($this->execute_request(wp_unslash($_POST)));
        } catch (WCOS_Split_Transport_Exception $exception) {
            $this->send_transport_error($exception);
        } catch (Throwable $throwable) {
            $this->send_transport_error(new WCOS_Split_Transport_Exception('execute_failed', __('Unable to execute the Split operation.', 'wc-order-splitter'), 500, true));
        }
    }

    public function render_launcher($order) {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT) || !$order instanceof WC_Order) {
            return;
        }

        try {
            WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);
            $this->assert_status_enabled($order);
            $preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($order);
        } catch (Throwable $throwable) {
            return;
        }

        $this->current_order = $order;
        $this->current_preflight = $preflight;
        $this->current_surface_supported = !empty($preflight['supported']);
        $dialog_id = 'wcos-split-dialog-' . $order->get_id();
        $disabled = !$this->current_surface_supported;
        $description_id = 'wcos-split-launcher-description-' . $order->get_id();

        echo '<button type="button" class="button wcos-split-launcher" aria-haspopup="dialog"' . ($disabled ? '' : ' aria-controls="' . esc_attr($dialog_id) . '"') . ' aria-describedby="' . esc_attr($description_id) . '"' . disabled($disabled, true, false) . '>';
        echo esc_html__('Split order', 'wc-order-splitter');
        echo '</button>';
        echo '<span id="' . esc_attr($description_id) . '" class="description wcos-split-launcher-description">';
        echo esc_html($disabled ? $preflight['message'] : __('Review quantities and policies before creating pending split orders.', 'wc-order-splitter'));
        echo '</span>';
    }

    public function enqueue_assets() {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT) || !$this->is_order_edit_screen()) {
            return;
        }
        $plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
        wp_enqueue_style('wcos-split-admin', plugins_url('css/p2-split-admin.css', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION);
        wp_enqueue_script('wcos-split-admin', plugins_url('js/p2-split-admin.js', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION, true);
        wp_localize_script(
            'wcos-split-admin',
            'wcosSplitAdminStrings',
            array(
                'reviewing' => __('Reviewing Split plan…', 'wc-order-splitter'),
                'reviewReady' => __('The plan passed server review. Confirm the acknowledgement to execute it.', 'wc-order-splitter'),
                'executing' => __('Executing Split…', 'wc-order-splitter'),
                'completed' => __('Split completed successfully.', 'wc-order-splitter'),
                'invalidPlan' => __('Enter at least one quantity and keep a positive residual quantity on every affected source line.', 'wc-order-splitter'),
                'requestFailed' => __('The Split request could not be completed.', 'wc-order-splitter'),
                'childOrder' => __('Child order', 'wc-order-splitter'),
                'reloadOrder' => __('Reload source order', 'wc-order-splitter'),
                'reviewSummary' => __('Reviewed children / affected lines / moved quantity:', 'wc-order-splitter'),
            )
        );
    }

    public function render_dialog() {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::SPLIT)
            || !$this->current_surface_supported
            || !$this->current_order instanceof WC_Order) {
            return;
        }
        echo $this->dialog_html($this->current_order, $this->current_preflight); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function dialog_html(WC_Order $order, array $preflight = array()) {
        if (empty($preflight)) {
            $preflight = (new WCOS_Split_WooCommerce_Adapter())->preflight($order);
        }
        $dialog_id = 'wcos-split-dialog-' . $order->get_id();
        $title_id = $dialog_id . '-title';
        $description_id = $dialog_id . '-description';
        $nonce = wp_create_nonce('wcos_split_order_' . $order->get_id());
        $fractional_supported = !empty($preflight['fractional_quantity_supported']);
        $step = $fractional_supported ? '0.000001' : '1';

        ob_start();
        ?>
        <div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-split-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>" aria-describedby="<?php echo esc_attr($description_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" hidden>
            <div class="wcos-split-dialog__backdrop" aria-hidden="true"></div>
            <div class="wcos-split-dialog__panel" tabindex="-1">
                <div class="wcos-split-dialog__header">
                    <div>
                        <h2 id="<?php echo esc_attr($title_id); ?>"><?php esc_html_e('Review quantity split', 'wc-order-splitter'); ?></h2>
                        <p id="<?php echo esc_attr($description_id); ?>"><?php esc_html_e('Enter the quantity from each source line to move into each pending child order. Every affected source line must retain a positive quantity.', 'wc-order-splitter'); ?></p>
                    </div>
                    <button type="button" class="button-link wcos-split-close" aria-label="<?php esc_attr_e('Close Split dialog', 'wc-order-splitter'); ?>"><span aria-hidden="true">×</span></button>
                </div>

                <form class="wcos-split-form" novalidate>
                    <div class="wcos-split-table-wrap" tabindex="0" aria-label="<?php esc_attr_e('Order line quantities allocated to child orders', 'wc-order-splitter'); ?>">
                        <table class="widefat striped wcos-split-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Product', 'wc-order-splitter'); ?></th>
                                    <th scope="col"><?php esc_html_e('Current quantity', 'wc-order-splitter'); ?></th>
                                    <?php for ($child_index = 1; $child_index <= 10; $child_index++) : ?>
                                        <th scope="col"><?php echo esc_html(sprintf(__('Child %d', 'wc-order-splitter'), $child_index)); ?></th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order->get_items('line_item') as $item_id => $item) :
                                    $quantity = WCOS_Decimal::normalize($item->get_quantity(), 6);
                                    ?>
                                    <tr data-item-id="<?php echo esc_attr($item_id); ?>" data-source-quantity="<?php echo esc_attr($quantity); ?>">
                                        <th scope="row"><?php echo esc_html($item->get_name()); ?></th>
                                        <td><?php echo esc_html($quantity); ?></td>
                                        <?php for ($child_index = 1; $child_index <= 10; $child_index++) :
                                            $child_key = 'child-' . $child_index;
                                            $quantity_id = 'wcos-split-quantity-' . $order->get_id() . '-' . $item_id . '-' . $child_index;
                                            ?>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr($quantity_id); ?>"><?php echo esc_html(sprintf(__('Quantity of %1$s to move to Child %2$d', 'wc-order-splitter'), $item->get_name(), $child_index)); ?></label>
                                                <input id="<?php echo esc_attr($quantity_id); ?>" class="wcos-split-quantity" data-child-key="<?php echo esc_attr($child_key); ?>" type="number" min="0" max="<?php echo esc_attr($quantity); ?>" step="<?php echo esc_attr($step); ?>" inputmode="decimal" value="0" />
                                            </td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="wcos-split-policy" aria-labelledby="<?php echo esc_attr($dialog_id . '-policy-title'); ?>">
                        <h3 id="<?php echo esc_attr($dialog_id . '-policy-title'); ?>"><?php esc_html_e('Current safety policy', 'wc-order-splitter'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('Shipping and positive fees remain on the source order.', 'wc-order-splitter'); ?></li>
                            <li><?php esc_html_e('Child orders are created as Pending payment and do not inherit the payment transaction.', 'wc-order-splitter'); ?></li>
                            <li><?php esc_html_e('Historical line taxes are preserved; current catalog prices and tax rates are not recalculated.', 'wc-order-splitter'); ?></li>
                            <li><?php esc_html_e('The Split request must not write physical product stock.', 'wc-order-splitter'); ?></li>
                            <li><?php esc_html_e('Coupons, refunds, negative fees, nested splits, and unclassified private line metadata are rejected before mutation.', 'wc-order-splitter'); ?></li>
                            <li><?php esc_html_e('Extensions that change stock directly in the database instead of WooCommerce stock APIs are unsupported unless they provide an explicit compatibility adapter.', 'wc-order-splitter'); ?></li>
                            <li><?php echo esc_html($fractional_supported ? __('Fractional quantities are enabled by the active WooCommerce quantity integration.', 'wc-order-splitter') : __('The active WooCommerce quantity integration only supports integer Split quantities.', 'wc-order-splitter')); ?></li>
                        </ul>
                    </div>

                    <div class="wcos-split-review" hidden>
                        <h3><?php esc_html_e('Reviewed plan', 'wc-order-splitter'); ?></h3>
                        <p class="wcos-split-review-summary"></p>
                        <label class="wcos-split-confirm-label">
                            <input type="checkbox" class="wcos-split-confirm-checkbox" />
                            <span><?php esc_html_e('I reviewed the quantities and understand that this will create new pending orders and modify the source order.', 'wc-order-splitter'); ?></span>
                        </label>
                    </div>

                    <div class="wcos-split-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
                    <div class="wcos-split-error notice notice-error inline" role="alert" tabindex="-1" hidden></div>
                    <div class="wcos-split-result notice notice-success inline" tabindex="-1" hidden></div>

                    <div class="wcos-split-dialog__actions">
                        <button type="button" class="button wcos-split-cancel"><?php esc_html_e('Cancel', 'wc-order-splitter'); ?></button>
                        <button type="button" class="button button-secondary wcos-split-review-button"><?php esc_html_e('Review split', 'wc-order-splitter'); ?></button>
                        <button type="button" class="button button-primary wcos-split-execute-button" disabled><?php esc_html_e('Confirm and split', 'wc-order-splitter'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function authorized_order(array $request, $order_id) {
        if (!$order_id) {
            throw new WCOS_Split_Transport_Exception('invalid_order', __('A valid order ID is required.', 'wc-order-splitter'), 400, false);
        }
        $nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'wcos_split_order_' . $order_id)) {
            throw new WCOS_Split_Transport_Exception('invalid_nonce', __('The Split request failed nonce verification.', 'wc-order-splitter'), 403, false);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            throw new WCOS_Split_Transport_Exception('order_not_found', __('The source order could not be found.', 'wc-order-splitter'), 404, false);
        }
        try {
            WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::SPLIT, $order);
        } catch (Throwable $throwable) {
            throw new WCOS_Split_Transport_Exception('authorization_failed', __('You are not allowed to split this order.', 'wc-order-splitter'), 403, false);
        }
        $this->assert_status_enabled($order);
        return $order;
    }

    private function assert_status_enabled(WC_Order $order) {
        $allowed = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
        $status = 'wc-' . $order->get_status();
        if (!in_array($status, $allowed, true)) {
            throw new WCOS_Split_Transport_Exception('status_disabled', __('This order status is disabled in the Order Splitter settings.', 'wc-order-splitter'), 409, false);
        }
    }

    private function plan_summary(array $plan) {
        $affected = array();
        $moved_units = 0;
        foreach ($plan as $items) {
            foreach ($items as $item_id => $quantity) {
                $affected[absint($item_id)] = true;
                $moved_units += WCOS_Decimal::to_units($quantity, 6);
            }
        }
        return array(
            'child_count' => count($plan),
            'affected_line_count' => count($affected),
            'moved_quantity' => WCOS_Decimal::from_units($moved_units, 6),
        );
    }

    private function send_transport_error(WCOS_Split_Transport_Exception $exception) {
        wp_send_json_error(
            array(
                'code' => $exception->get_error_code(),
                'message' => $exception->getMessage(),
                'retryable' => $exception->is_retryable(),
                'context' => $exception->get_context(),
            ),
            $exception->get_http_status()
        );
    }

    private function is_order_edit_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }
        $hpos_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        $hpos_order_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        return 'shop_order' === $screen->id || ($hpos_screen === $screen->id && $hpos_order_id > 0);
    }
}
