<?php

use PHPUnit\Framework\TestCase;

final class MutationSupportTest extends TestCase {
	public function test_scalar_allocation_conserves_amount_with_residual_rounding() {
		$parts = WC_Order_Splitter_Mutation_Support::allocate_scalar(10.00, array('a' => 1, 'b' => 1, 'c' => 1));

		$this->assertSame(10.00, round(array_sum($parts), 2));
		$this->assertCount(3, $parts);
	}

	public function test_one_cent_allocation_never_creates_or_loses_money() {
		$parts = WC_Order_Splitter_Mutation_Support::allocate_scalar(0.01, array('a' => 1, 'b' => 1, 'c' => 1));

		$this->assertSame(0.01, round(array_sum($parts), 2));
	}

	public function test_tax_allocation_conserves_each_rate_and_bucket() {
		$taxes = array(
			'subtotal' => array(1 => 1.01, 2 => 2.02),
			'total' => array(1 => 0.99, 2 => 1.98),
		);
		$parts = WC_Order_Splitter_Mutation_Support::allocate_tax_array($taxes, array('original' => 2, 'child' => 1));

		foreach (array('subtotal', 'total') as $bucket) {
			foreach ($taxes[$bucket] as $rate_id => $expected) {
				$actual = $parts['original'][$bucket][$rate_id] + $parts['child'][$bucket][$rate_id];
				$this->assertSame(round($expected, 2), round($actual, 2));
			}
		}
	}

	public function test_line_identity_distinguishes_variations() {
		$red = new WC_Order_Item_Product(10, 101, 'standard', 'T-Shirt', array());
		$blue = new WC_Order_Item_Product(10, 102, 'standard', 'T-Shirt', array());

		$this->assertNotSame(
			WC_Order_Splitter_Mutation_Support::line_identity($red),
			WC_Order_Splitter_Mutation_Support::line_identity($blue)
		);
	}

	public function test_line_identity_ignores_reduced_stock_but_keeps_business_metadata() {
		$first = new WC_Order_Item_Product(10, 101, 'standard', 'T-Shirt', array(
			new WC_Order_Splitter_Test_Meta('engraving', 'A'),
			new WC_Order_Splitter_Test_Meta('_reduced_stock', 2),
		));
		$second = new WC_Order_Item_Product(10, 101, 'standard', 'T-Shirt', array(
			new WC_Order_Splitter_Test_Meta('_reduced_stock', 7),
			new WC_Order_Splitter_Test_Meta('engraving', 'A'),
		));
		$different = new WC_Order_Item_Product(10, 101, 'standard', 'T-Shirt', array(
			new WC_Order_Splitter_Test_Meta('engraving', 'B'),
		));

		$this->assertSame(
			WC_Order_Splitter_Mutation_Support::line_identity($first),
			WC_Order_Splitter_Mutation_Support::line_identity($second)
		);
		$this->assertNotSame(
			WC_Order_Splitter_Mutation_Support::line_identity($first),
			WC_Order_Splitter_Mutation_Support::line_identity($different)
		);
	}

	public function test_amount_maps_preserve_all_known_total_fields() {
		$left = array('total' => 10.11, 'shipping_total' => 1.01, 'total_tax' => 0.91);
		$right = array('total' => 20.22, 'shipping_total' => 2.02, 'total_tax' => 1.82);
		$result = WC_Order_Splitter_Mutation_Support::add_amount_maps($left, $right);

		$this->assertSame(30.33, $result['total']);
		$this->assertSame(3.03, $result['shipping_total']);
		$this->assertSame(2.73, $result['total_tax']);
	}
}
