<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Skwirrel_WC_Sync_Product_Mapper category-related methods.
 */
class ProductMapperCategoryTest extends TestCase {

	private Skwirrel_WC_Sync_Product_Mapper $mapper;

	protected function setUp(): void {
		$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
	}

	// ------------------------------------------------------------------
	// get_categories()
	// ------------------------------------------------------------------

	public function test_get_categories_from_categories_array(): void {
		$product = [
			'product_id' => 1,
			'_categories' => [
				[
					'category_id' => 10,
					'category_name' => 'Bevestigingsmaterialen',
				],
				[
					'category_id' => 20,
					'category_name' => 'Schroeven',
					'parent_category_id' => 10,
					'parent_category_name' => 'Bevestigingsmaterialen',
				],
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(2, $result);
		$this->assertSame(10, $result[0]['id']);
		$this->assertSame('Bevestigingsmaterialen', $result[0]['name']);
		$this->assertNull($result[0]['parent_id']);
		$this->assertSame(20, $result[1]['id']);
		$this->assertSame('Schroeven', $result[1]['name']);
		$this->assertSame(10, $result[1]['parent_id']);
		$this->assertSame('Bevestigingsmaterialen', $result[1]['parent_name']);
	}

	public function test_get_categories_falls_back_to_product_groups(): void {
		$product = [
			'product_id' => 2,
			'_categories' => [],
			'_product_groups' => [
				['product_group_name' => 'Gereedschap', 'product_group_id' => 5],
				['product_group_name' => 'Handgereedschap', 'product_group_id' => 6],
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(2, $result);
		$this->assertSame(5, $result[0]['id']);
		$this->assertSame('Gereedschap', $result[0]['name']);
		$this->assertNull($result[0]['parent_id']);
	}

	public function test_get_categories_deduplicates_by_name(): void {
		$product = [
			'product_id' => 3,
			'_categories' => [
				['category_id' => 10, 'category_name' => 'Schroeven'],
				['category_id' => 20, 'category_name' => 'schroeven'], // duplicate (case insensitive)
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(1, $result);
		$this->assertSame(10, $result[0]['id']); // first occurrence wins
	}

	public function test_get_categories_skips_empty_names(): void {
		$product = [
			'product_id' => 4,
			'_categories' => [
				['category_id' => 10, 'category_name' => ''],
				['category_id' => 20, 'category_name' => 'Moeren'],
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(1, $result);
		$this->assertSame('Moeren', $result[0]['name']);
	}

	public function test_get_categories_returns_empty_when_no_data(): void {
		$product = ['product_id' => 5];

		$result = $this->mapper->get_categories($product);

		$this->assertSame([], $result);
	}

	public function test_get_categories_handles_alternative_id_keys(): void {
		$product = [
			'product_id' => 6,
			'_categories' => [
				['product_category_id' => 99, 'product_category_name' => 'Elektronica'],
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(1, $result);
		$this->assertSame(99, $result[0]['id']);
		$this->assertSame('Elektronica', $result[0]['name']);
	}

	public function test_get_categories_resolves_parent_from_nested_object(): void {
		$product = [
			'product_id' => 7,
			'_categories' => [
				[
					'category_id' => 30,
					'category_name' => 'LED Lampen',
					'parent_category_id' => 20,
					'_parent_category' => [
						'category_id' => 20,
						'category_name' => 'Verlichting',
					],
				],
			],
		];

		$result = $this->mapper->get_categories($product);

		$this->assertCount(1, $result);
		$this->assertSame(20, $result[0]['parent_id']);
		$this->assertSame('Verlichting', $result[0]['parent_name']);
	}

	// ------------------------------------------------------------------
	// get_category_names()
	// ------------------------------------------------------------------

	public function test_get_category_names_returns_unique_names(): void {
		$product = [
			'product_id' => 8,
			'_categories' => [
				['category_id' => 10, 'category_name' => 'Schroeven'],
				['category_id' => 20, 'category_name' => 'Bouten'],
			],
		];

		$names = $this->mapper->get_category_names($product);

		$this->assertSame(['Schroeven', 'Bouten'], $names);
	}

	// ------------------------------------------------------------------
	// CATEGORY_ID_META constant
	// ------------------------------------------------------------------

	public function test_category_id_meta_constant_is_accessible(): void {
		$this->assertSame('_skwirrel_category_id', Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META);
	}
}
