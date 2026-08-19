<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Script {
	private $version;

	public function __construct() {
		$this->version = WC_ORDER_SPLITTER_VERSION;

		add_action('admin_init', array($this, 'record_version'));

		$this->includes();
	}

	public function record_version() {
		if (get_option('wc_order_splitter_version') !== $this->version) {
			update_option('wc_order_splitter_version', $this->version);
		}
	}

	public function includes() {
		$root = plugin_dir_path(__FILE__) . '../';

		include_once $root . 'domain/class-wcos-decimal.php';
		include_once $root . 'domain/class-wcos-amount-allocator.php';
		include_once $root . 'domain/class-wcos-line-identity.php';
		include_once $root . 'domain/class-wcos-mutation-fingerprint.php';
		include_once $root . 'domain/class-wcos-price-precision-scope.php';
		include_once $root . 'domain/class-wcos-stock-side-effect-guard.php';
		include_once $root . 'domain/class-wcos-mutation-contract.php';
		include_once $root . 'domain/class-wcos-operation-lock.php';
		include_once $root . 'domain/class-wcos-feature-gates.php';
		include_once $root . 'domain/class-wcos-split-strategy-gates.php';
		include_once $root . 'domain/class-wcos-order-mutation-authorizer.php';
		include_once $root . 'domain/class-wcos-order-item-meta-policy.php';
		include_once $root . 'domain/class-wcos-order-item-cloner.php';
		include_once $root . 'domain/class-wcos-split-execution-policy.php';
		include_once $root . 'domain/class-wcos-split-plan.php';
		include_once $root . 'domain/class-wcos-order-totals-rebuilder.php';
		include_once $root . 'domain/class-wcos-tax-item-synchronizer.php';
		include_once $root . 'domain/class-wcos-order-contract-snapshot.php';
		include_once $root . 'domain/class-wcos-order-copy-context.php';
		include_once $root . 'domain/class-wcos-order-mutation-snapshot.php';
		include_once $root . 'domain/class-wcos-operation-journal.php';
		include_once $root . 'domain/class-wcos-manual-reconciliation-blocker.php';
		include_once $root . 'domain/class-wcos-operation-journal-retention.php';
		WCOS_Operation_Journal_Retention::bootstrap();
		include_once $root . 'domain/class-wcos-order-relation-repository.php';
		WCOS_Order_Relation_Repository::bootstrap();
		include_once $root . 'domain/class-wcos-mutation-commit-guard.php';
		WCOS_Mutation_Commit_Guard::bootstrap();
		include_once $root . 'domain/class-wcos-duplicate-order-service.php';
		include_once $root . 'domain/class-wcos-split-order-service.php';
		include_once $root . 'domain/class-wcos-split-compensator.php';
		include_once $root . 'domain/class-wcos-mutation-recovery-coordinator.php';
		WCOS_Mutation_Recovery_Coordinator::bootstrap();
		include_once $root . 'domain/class-wcos-split-preflight.php';
		include_once $root . 'domain/class-wcos-split-woocommerce-adapter.php';
		include_once $root . 'domain/class-wcos-category-split-planner.php';
		include_once $root . 'domain/class-wcos-stock-status-split-planner.php';
		include_once $root . 'domain/class-wcos-duplicate-preflight.php';
		include_once $root . 'domain/class-wcos-duplicate-woocommerce-adapter.php';
		include_once $root . 'domain/class-wcos-mutation-gateway.php';

		include_once $root . 'backend/class-wcos-split-request-parser.php';
		include_once $root . 'backend/class-wcos-split-confirmation-store.php';
		include_once $root . 'backend/class-wcos-split-admin-controller.php';
		new WCOS_Split_Admin_Controller();

		include_once $root . 'backend/class-wcos-duplicate-confirmation-store.php';
		include_once $root . 'backend/class-wcos-duplicate-admin-controller.php';
		new WCOS_Duplicate_Admin_Controller();

		include_once $root . 'backend/settings.php';
		include_once $root . 'backend/orders.php';
		include_once $root . 'backend/yoohw-woo-settings-tabs-reorder.php';
		include_once plugin_dir_path(__FILE__) . 'safety.php';

		/*
		 * Legacy mutation handlers are deliberately never loaded here. Hardened
		 * production transports must enter through WCOS_Mutation_Gateway. Category
		 * and Stock-status classes loaded above are read-only planners only; their
		 * strategy gates remain hard-off and no production transport is registered.
		 */
	}
}

new WC_Order_Splitter_Script();
