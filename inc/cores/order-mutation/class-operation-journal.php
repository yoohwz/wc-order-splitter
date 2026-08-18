<?php

defined('ABSPATH') || exit;

final class WC_Order_Splitter_Operation_Journal {
	const META_PREFIX = '_wc_order_splitter_operation_';

	public function start($source_order, $type, $snapshot, $context = array()) {
		$operation_id = wp_generate_uuid4();
		$record = array(
			'id'            => $operation_id,
			'type'          => sanitize_key($type),
			'status'        => 'running',
			'source_order'  => (int) $source_order->get_id(),
			'target_orders' => array(),
			'user_id'       => (int) get_current_user_id(),
			'created_at'    => gmdate('c'),
			'updated_at'    => gmdate('c'),
			'snapshot_hash' => isset($snapshot['hash']) ? $snapshot['hash'] : '',
			'before_totals' => isset($snapshot['totals']) ? $snapshot['totals'] : array(),
			'context'       => $context,
			'error'         => null,
		);
		$this->write($source_order, $record);
		return $record;
	}

	public function update($source_order, $record, $changes) {
		$record = array_merge($record, (array) $changes);
		$record['updated_at'] = gmdate('c');
		$this->write($source_order, $record);
		return $record;
	}

	public function complete($source_order, $record, $target_order_ids, $context = array()) {
		return $this->update($source_order, $record, array(
			'status'        => 'completed',
			'target_orders' => array_values(array_map('absint', (array) $target_order_ids)),
			'context'       => array_merge(isset($record['context']) ? (array) $record['context'] : array(), $context),
			'error'         => null,
		));
	}

	public function fail($source_order, $record, Throwable $error, $context = array()) {
		$error_data = array(
			'class'   => get_class($error),
			'message' => $error->getMessage(),
		);
		if ($error instanceof WC_Order_Splitter_Mutation_Exception) {
			$error_data['context'] = $error->get_context();
		}

		return $this->update($source_order, $record, array(
			'status'  => 'failed',
			'context' => array_merge(isset($record['context']) ? (array) $record['context'] : array(), $context),
			'error'   => $error_data,
		));
	}

	public function get($source_order, $operation_id) {
		$record = $source_order->get_meta(self::META_PREFIX . sanitize_key($operation_id), true);
		return is_array($record) ? $record : array();
	}

	public function get_for_child($child_order) {
		$operation_id = (string) $child_order->get_meta(WC_Order_Splitter_Mutation_Support::META_OPERATION_ID, true);
		$original_id = absint($child_order->get_meta(WC_Order_Splitter_Mutation_Support::META_ORIGINAL_ID, true));
		if (!$operation_id || !$original_id) {
			return array(null, array());
		}
		$source_order = wc_get_order($original_id);
		if (!$source_order) {
			return array(null, array());
		}
		return array($source_order, $this->get($source_order, $operation_id));
	}

	private function write($source_order, $record) {
		$operation_id = sanitize_key($record['id']);
		$source_order->update_meta_data(self::META_PREFIX . $operation_id, $record);

		$ids = (array) $source_order->get_meta(WC_Order_Splitter_Mutation_Support::META_OPERATION_IDS, true);
		if (!in_array($operation_id, $ids, true)) {
			$ids[] = $operation_id;
			$source_order->update_meta_data(WC_Order_Splitter_Mutation_Support::META_OPERATION_IDS, array_values($ids));
		}
		$source_order->save_meta_data();
	}
}
