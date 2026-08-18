<?php
/**
 * Exact commercial line identity for merge and return planning.
 *
 * @package WC_Order_Splitter
 */

defined('ABSPATH') || (PHP_SAPI === 'cli') || exit;

/**
 * Prevents different variations or configured product lines from collapsing.
 */
final class WCOS_V2_Line_Identity {

	/**
	 * Build an immutable line identity payload and signature.
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $tax_class    Historical tax class.
	 * @param array  $metadata     Metadata records containing key and value.
	 * @return array
	 */
	public static function build($product_id, $variation_id, $tax_class, array $metadata) {
		$payload = array(
			'product_id'   => (int) $product_id,
			'variation_id' => (int) $variation_id,
			'tax_class'    => (string) $tax_class,
			'metadata'     => WCOS_V2_Metadata_Policy::normalize_records($metadata, true),
		);
		$json    = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		if (false === $json) {
			throw new LogicException('Unable to encode the commercial line identity.');
		}

		return array(
			'payload'   => $payload,
			'signature' => hash('sha256', $json),
		);
	}

	/**
	 * Compare two line snapshots by exact commercial identity.
	 *
	 * @param array $left  First line snapshot.
	 * @param array $right Second line snapshot.
	 * @return bool
	 */
	public static function equals(array $left, array $right) {
		$left_identity = self::from_snapshot($left);
		$right_identity = self::from_snapshot($right);

		return hash_equals($left_identity['signature'], $right_identity['signature']);
	}

	/**
	 * Build identity from a normalized snapshot.
	 *
	 * @param array $snapshot Line snapshot.
	 * @return array
	 */
	public static function from_snapshot(array $snapshot) {
		return self::build(
			isset($snapshot['product_id']) ? $snapshot['product_id'] : 0,
			isset($snapshot['variation_id']) ? $snapshot['variation_id'] : 0,
			isset($snapshot['tax_class']) ? $snapshot['tax_class'] : '',
			isset($snapshot['metadata']) && is_array($snapshot['metadata']) ? $snapshot['metadata'] : array()
		);
	}
}
