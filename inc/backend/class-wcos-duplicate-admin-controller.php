<?php

defined('ABSPATH') || exit;

final class WCOS_Duplicate_Transport_Exception extends RuntimeException {
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
 * Production admin transport for hardened single-order Duplicate.
 * Safe to bootstrap while DUPLICATE remains hard-off.
 */
final class WCOS_Duplicate_Admin_Controller {
    const REVIEW_ACTION = 'wcos_duplicate_review';
    const EXECUTE_ACTION = 'wcos_duplicate_execute';

    private $current_order = null;
    private $current_preflight = null;
    private $current_surface_supported = false;

    public function __construct() {
        add_action('wp_ajax_' . self::REVIEW_ACTION, array($this, 'ajax_review'));
        add_action('wp_ajax_' . self::EXECUTE_ACTION, array($this, 'ajax_execute'));
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'render_launcher'), 21, 1);
        add_action('admin_footer', array($this, 'render_dialog'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function review_request(array $request) {
        $order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
        $order = $this->authorized_order($request, $order_id);
        $preflight = (new WCOS_Mutation_Gateway())->duplicate_preflight($order);
        if (empty($preflight['supported'])) {
            throw new WCOS_Duplicate_Transport_Exception(
                'preflight_' . (isset($preflight['reason']) ? $preflight['reason'] : 'unsupported'),
                isset($preflight['message']) ? $preflight['message'] : __('This order is not supported by the current Duplicate policy.', 'wc-order-splitter'),
                409,
                false,
                array('preflight' => $preflight)
            );
        }

        try {
            $confirmation = WCOS_Duplicate_Confirmation_Store::create($order, $preflight, get_current_user_id());
        } catch (WCOS_Duplicate_Confirmation_Exception $exception) {
            if ('source_changed' === $exception->get_reason()) {
                throw new WCOS_Duplicate_Transport_Exception('review_source_changed', $exception->getMessage(), 409, true);
            }
            throw $exception;
        }

        return array(
            'operation_id' => $confirmation['operation_id'],
            'confirmation_token' => $confirmation['confirmation_token'],
            'expires_at' => $confirmation['expires_at'],
            'preflight' => $preflight,
            'summary' => array(
                'line_count' => (int) $preflight['line_count'],
                'shipping_count' => (int) $preflight['shipping_count'],
                'fee_count' => (int) $preflight['fee_count'],
                'coupon_count' => (int) $preflight['coupon_count'],
                'currency' => (string) $preflight['currency'],
            ),
        );
    }

    public function execute_request(array $request) {
        $order_id = isset($request['order_id']) ? absint($request['order_id']) : 0;
        $order = $this->authorized_order($request, $order_id);
        $operation_id = isset($request['operation_id']) ? sanitize_key((string) $request['operation_id']) : '';
        $confirmation_token = isset($request['confirmation_token']) ? (string) $request['confirmation_token'] : '';

        try {
            $confirmation = WCOS_Duplicate_Confirmation_Store::verify(
                $order,
                $operation_id,
                $confirmation_token,
                get_current_user_id()
            );
        } catch (WCOS_Duplicate_Confirmation_Exception $exception) {
            $http_statuses = array(
                'invalid_identity' => 400,
                'invalid_token' => 403,
                'owner_mismatch' => 403,
                'expired' => 410,
                'source_changed' => 409,
                'source_missing' => 404,
                'policy_changed' => 409,
                'precision_mismatch' => 409,
                'journal_mismatch' => 409,
                'manual_reconciliation' => 409,
                'operation_closed' => 409,
                'journal_incomplete' => 409,
            );
            $reason = $exception->get_reason();
            throw new WCOS_Duplicate_Transport_Exception(
                'confirmation_' . $reason,
                $exception->getMessage(),
                isset($http_statuses[$reason]) ? $http_statuses[$reason] : 403,
                false
            );
        }

        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE)) {
            throw new WCOS_Duplicate_Transport_Exception(
                'workflow_disabled',
                __('Hardened Duplicate is not enabled for production use yet.', 'wc-order-splitter'),
                503,
                false
            );
        }

        try {
            $target = (new WCOS_Mutation_Gateway())->duplicate(
                $order,
                $operation_id,
                $confirmation['price_precision']
            );
        } catch (WCOS_Duplicate_Preflight_Exception $exception) {
            throw new WCOS_Duplicate_Transport_Exception(
                'preflight_' . $exception->get_reason(),
                $exception->getMessage(),
                409,
                false,
                array('preflight' => $exception->get_report())
            );
        } catch (WCOS_Unexpected_Stock_Mutation_Exception $exception) {
            throw new WCOS_Duplicate_Transport_Exception(
                'manual_reconciliation_required',
                __('Duplicate detected an unexpected physical-stock side effect and now requires manual reconciliation.', 'wc-order-splitter'),
                409,
                false
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (false !== strpos($message, 'Another order mutation is already in progress')) {
                throw new WCOS_Duplicate_Transport_Exception('operation_busy', $message, 409, true);
            }
            if (false !== strpos($message, 'different mutation request')) {
                throw new WCOS_Duplicate_Transport_Exception('operation_conflict', $message, 409, false);
            }
            throw new WCOS_Duplicate_Transport_Exception('duplicate_failed', $message, 409, true);
        }

        return array(
            'operation_id' => $operation_id,
            'status' => 'completed',
            'source_order_id' => $order->get_id(),
            'target' => array(
                'id' => $target->get_id(),
                'number' => (string) $target->get_order_number(),
                'status' => (string) $target->get_status(),
                'edit_url' => method_exists($target, 'get_edit_order_url') ? esc_url_raw((string) $target->get_edit_order_url()) : '',
            ),
        );
    }

    public function ajax_review() {
        try {
            wp_send_json_success($this->review_request(wp_unslash($_POST)));
        } catch (WCOS_Duplicate_Transport_Exception $exception) {
            $this->send_transport_error($exception);
        } catch (Throwable $throwable) {
            $this->send_transport_error(new WCOS_Duplicate_Transport_Exception('review_failed', __('Unable to review Duplicate.', 'wc-order-splitter'), 500, true));
        }
    }

    public function ajax_execute() {
        try {
            wp_send_json_success($this->execute_request(wp_unslash($_POST)));
        } catch (WCOS_Duplicate_Transport_Exception $exception) {
            $this->send_transport_error($exception);
        } catch (Throwable $throwable) {
            $this->send_transport_error(new WCOS_Duplicate_Transport_Exception('execute_failed', __('Unable to execute Duplicate.', 'wc-order-splitter'), 500, true));
        }
    }

    public function render_launcher($order) {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE) || !$order instanceof WC_Order) {
            return;
        }

        try {
            WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $order);
            $this->assert_status_enabled($order);
            $preflight = (new WCOS_Duplicate_WooCommerce_Adapter())->preflight($order);
        } catch (Throwable $throwable) {
            return;
        }

        $this->current_order = $order;
        $this->current_preflight = $preflight;
        $this->current_surface_supported = !empty($preflight['supported']);
        $dialog_id = 'wcos-duplicate-dialog-' . $order->get_id();
        $description_id = 'wcos-duplicate-launcher-description-' . $order->get_id();
        $disabled = !$this->current_surface_supported;

        echo '<button type="button" class="button wcos-duplicate-launcher" aria-haspopup="dialog"' . ($disabled ? '' : ' aria-controls="' . esc_attr($dialog_id) . '"') . ' aria-describedby="' . esc_attr($description_id) . '"' . disabled($disabled, true, false) . '>';
        echo esc_html__('Duplicate order', 'wc-order-splitter');
        echo '</button>';
        echo '<span id="' . esc_attr($description_id) . '" class="description wcos-duplicate-launcher-description">';
        echo esc_html($disabled ? $preflight['message'] : __('Review the Duplicate safety policy before creating a new pending order.', 'wc-order-splitter'));
        echo '</span>';
    }

    public function enqueue_assets() {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE) || !$this->is_order_edit_screen()) {
            return;
        }
        $plugin_file = dirname(__DIR__, 2) . '/wc-order-splitter.php';
        wp_enqueue_style('wcos-duplicate-admin', plugins_url('css/p2-duplicate-admin.css', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION);
        wp_enqueue_script('wcos-duplicate-admin', plugins_url('js/p2-duplicate-admin.js', $plugin_file), array(), WC_ORDER_SPLITTER_VERSION, true);
        wp_localize_script(
            'wcos-duplicate-admin',
            'wcosDuplicateAdminStrings',
            array(
                'reviewing' => __('Reviewing Duplicate…', 'wc-order-splitter'),
                'reviewReady' => __('The order passed server review. Confirm the acknowledgement to duplicate it.', 'wc-order-splitter'),
                'executing' => __('Duplicating order…', 'wc-order-splitter'),
                'completed' => __('Order duplicated successfully.', 'wc-order-splitter'),
                'requestFailed' => __('The Duplicate request could not be completed.', 'wc-order-splitter'),
                'targetOrder' => __('Duplicated order', 'wc-order-splitter'),
                'reviewSummary' => __('Reviewed lines / shipping / fees / coupons:', 'wc-order-splitter'),
            )
        );
    }

    public function render_dialog() {
        if (!WCOS_Feature_Gates::enabled(WCOS_Feature_Gates::DUPLICATE)
            || !$this->current_surface_supported
            || !$this->current_order instanceof WC_Order) {
            return;
        }
        echo $this->dialog_html($this->current_order, $this->current_preflight); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function dialog_html(WC_Order $order, array $preflight = array()) {
        if (empty($preflight)) {
            $preflight = (new WCOS_Duplicate_WooCommerce_Adapter())->preflight($order);
        }
        $dialog_id = 'wcos-duplicate-dialog-' . $order->get_id();
        $title_id = $dialog_id . '-title';
        $description_id = $dialog_id . '-description';
        $nonce = wp_create_nonce('wcos_duplicate_order_' . $order->get_id());

        ob_start();
        ?>
        <div id="<?php echo esc_attr($dialog_id); ?>" class="wcos-duplicate-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($title_id); ?>" aria-describedby="<?php echo esc_attr($description_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" hidden>
            <div class="wcos-duplicate-dialog__backdrop" aria-hidden="true"></div>
            <div class="wcos-duplicate-dialog__panel" tabindex="-1">
                <div class="wcos-duplicate-dialog__header">
                    <div>
                        <h2 id="<?php echo esc_attr($title_id); ?>"><?php esc_html_e('Review order duplicate', 'wc-order-splitter'); ?></h2>
                        <p id="<?php echo esc_attr($description_id); ?>"><?php esc_html_e('Duplicate creates one new Pending payment order from the reviewed historical order state.', 'wc-order-splitter'); ?></p>
                    </div>
                    <button type="button" class="button-link wcos-duplicate-close" aria-label="<?php esc_attr_e('Close Duplicate dialog', 'wc-order-splitter'); ?>"><span aria-hidden="true">×</span></button>
                </div>

                <div class="wcos-duplicate-policy" aria-labelledby="<?php echo esc_attr($dialog_id . '-policy-title'); ?>">
                    <h3 id="<?php echo esc_attr($dialog_id . '-policy-title'); ?>"><?php esc_html_e('Current safety policy', 'wc-order-splitter'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('The target is created as Pending payment.', 'wc-order-splitter'); ?></li>
                        <li><?php esc_html_e('Historical line, shipping, fee, coupon, and tax rows are copied exactly; current catalog prices and tax rates are not recalculated.', 'wc-order-splitter'); ?></li>
                        <li><?php esc_html_e('Payment method context is copied, but the source transaction ID, paid state, and stock-reduction state are not copied.', 'wc-order-splitter'); ?></li>
                        <li><?php esc_html_e('The Duplicate request must not write physical product stock.', 'wc-order-splitter'); ?></li>
                        <li><?php esc_html_e('Refunded orders and unclassified private order-item metadata are rejected before mutation.', 'wc-order-splitter'); ?></li>
                        <li><?php esc_html_e('Custom order-level metadata outside the copied WooCommerce core fields is not copied by the first hardened Duplicate workflow.', 'wc-order-splitter'); ?></li>
                    </ul>
                </div>

                <div class="wcos-duplicate-review" hidden>
                    <h3><?php esc_html_e('Reviewed order', 'wc-order-splitter'); ?></h3>
                    <p class="wcos-duplicate-review-summary"></p>
                    <label class="wcos-duplicate-confirm-label">
                        <input type="checkbox" class="wcos-duplicate-confirm-checkbox" />
                        <span><?php esc_html_e('I reviewed the policy and understand that this will create a new pending order without copying payment transaction or stock-reduction state.', 'wc-order-splitter'); ?></span>
                    </label>
                </div>

                <div class="wcos-duplicate-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
                <div class="wcos-duplicate-error notice notice-error inline" role="alert" tabindex="-1" hidden></div>
                <div class="wcos-duplicate-result notice notice-success inline" tabindex="-1" hidden></div>

                <div class="wcos-duplicate-dialog__actions">
                    <button type="button" class="button wcos-duplicate-cancel"><?php esc_html_e('Cancel', 'wc-order-splitter'); ?></button>
                    <button type="button" class="button button-secondary wcos-duplicate-review-button"><?php esc_html_e('Review duplicate', 'wc-order-splitter'); ?></button>
                    <button type="button" class="button button-primary wcos-duplicate-execute-button" disabled><?php esc_html_e('Confirm and duplicate', 'wc-order-splitter'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function authorized_order(array $request, $order_id) {
        if (!$order_id) {
            throw new WCOS_Duplicate_Transport_Exception('invalid_order', __('A valid order ID is required.', 'wc-order-splitter'), 400, false);
        }
        $nonce = isset($request['nonce']) ? sanitize_text_field((string) $request['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'wcos_duplicate_order_' . $order_id)) {
            throw new WCOS_Duplicate_Transport_Exception('invalid_nonce', __('The Duplicate request failed nonce verification.', 'wc-order-splitter'), 403, false);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            throw new WCOS_Duplicate_Transport_Exception('order_not_found', __('The source order could not be found.', 'wc-order-splitter'), 404, false);
        }
        try {
            WCOS_Order_Mutation_Authorizer::assert_workflow(WCOS_Feature_Gates::DUPLICATE, $order);
        } catch (Throwable $throwable) {
            throw new WCOS_Duplicate_Transport_Exception('authorization_failed', __('You are not allowed to duplicate this order.', 'wc-order-splitter'), 403, false);
        }
        $this->assert_status_enabled($order);
        return $order;
    }

    private function assert_status_enabled(WC_Order $order) {
        $allowed = (array) get_option('order_splitter_status_allowed', array('wc-processing'));
        $status = 'wc-' . $order->get_status();
        if (!in_array($status, $allowed, true)) {
            throw new WCOS_Duplicate_Transport_Exception('status_disabled', __('This order status is disabled in the Order Splitter settings.', 'wc-order-splitter'), 409, false);
        }
    }

    private function send_transport_error(WCOS_Duplicate_Transport_Exception $exception) {
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
