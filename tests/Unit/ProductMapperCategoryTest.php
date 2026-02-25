<?php

declare(strict_types=1);

// ------------------------------------------------------------------
// get_categories()
// ------------------------------------------------------------------

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_categories extracts from _categories array', function () {
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

	expect($result)->toHaveCount(2);
	expect($result[0]['id'])->toBe(10);
	expect($result[0]['name'])->toBe('Bevestigingsmaterialen');
	expect($result[0]['parent_id'])->toBeNull();
	expect($result[1]['id'])->toBe(20);
	expect($result[1]['name'])->toBe('Schroeven');
	expect($result[1]['parent_id'])->toBe(10);
	expect($result[1]['parent_name'])->toBe('Bevestigingsmaterialen');
});

test('get_categories falls back to product groups', function () {
	$product = [
		'product_id' => 2,
		'_categories' => [],
		'_product_groups' => [
			['product_group_name' => 'Gereedschap', 'product_group_id' => 5],
			['product_group_name' => 'Handgereedschap', 'product_group_id' => 6],
		],
	];

	$result = $this->mapper->get_categories($product);

	expect($result)->toHaveCount(2);
	expect($result[0]['id'])->toBe(5);
	expect($result[0]['name'])->toBe('Gereedschap');
	expect($result[0]['parent_id'])->toBeNull();
});

test('get_categories deduplicates by name (case insensitive)', function () {
	$product = [
		'product_id' => 3,
		'_categories' => [
			['category_id' => 10, 'category_name' => 'Schroeven'],
			['category_id' => 20, 'category_name' => 'schroeven'],
		],
	];

	$result = $this->mapper->get_categories($product);

	expect($result)->toHaveCount(1);
	expect($result[0]['id'])->toBe(10); // first occurrence wins
});

test('get_categories skips empty names', function () {
	$product = [
		'product_id' => 4,
		'_categories' => [
			['category_id' => 10, 'category_name' => ''],
			['category_id' => 20, 'category_name' => 'Moeren'],
		],
	];

	$result = $this->mapper->get_categories($product);

	expect($result)->toHaveCount(1);
	expect($result[0]['name'])->toBe('Moeren');
});

test('get_categories returns empty when no data', function () {
	$product = ['product_id' => 5];

	$result = $this->mapper->get_categories($product);

	expect($result)->toBe([]);
});

test('get_categories handles alternative id keys', function () {
	$product = [
		'product_id' => 6,
		'_categories' => [
			['product_category_id' => 99, 'product_category_name' => 'Elektronica'],
		],
	];

	$result = $this->mapper->get_categories($product);

	expect($result)->toHaveCount(1);
	expect($result[0]['id'])->toBe(99);
	expect($result[0]['name'])->toBe('Elektronica');
});

test('get_categories resolves parent from nested object', function () {
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

	// Parent is extracted as a separate entry (ancestor chain unwound)
	expect($result)->toHaveCount(2);
	expect($result[0]['id'])->toBe(20);
	expect($result[0]['name'])->toBe('Verlichting');
	expect($result[0]['parent_id'])->toBeNull();
	expect($result[1]['id'])->toBe(30);
	expect($result[1]['name'])->toBe('LED Lampen');
	expect($result[1]['parent_id'])->toBe(20);
	expect($result[1]['parent_name'])->toBe('Verlichting');
});

test('get_categories extracts deep category tree from nested _parent_category', function () {
	$product = [
		'product_id' => 9,
		'_categories' => [
			[
				'category_id' => 30,
				'category_name' => 'LED Lampen',
				'parent_category_id' => 20,
				'_parent_category' => [
					'category_id' => 20,
					'category_name' => 'Verlichting',
					'parent_category_id' => 10,
					'_parent_category' => [
						'category_id' => 10,
						'category_name' => 'Bouwmaterialen',
					],
				],
			],
		],
	];

	$result = $this->mapper->get_categories($product);

	// Full tree: root → middle → leaf
	expect($result)->toHaveCount(3);
	expect($result[0]['id'])->toBe(10);
	expect($result[0]['name'])->toBe('Bouwmaterialen');
	expect($result[0]['parent_id'])->toBeNull();

	expect($result[1]['id'])->toBe(20);
	expect($result[1]['name'])->toBe('Verlichting');
	expect($result[1]['parent_id'])->toBe(10);
	expect($result[1]['parent_name'])->toBe('Bouwmaterialen');

	expect($result[2]['id'])->toBe(30);
	expect($result[2]['name'])->toBe('LED Lampen');
	expect($result[2]['parent_id'])->toBe(20);
	expect($result[2]['parent_name'])->toBe('Verlichting');
});

test('get_categories deduplicates ancestors shared by multiple categories', function () {
	$product = [
		'product_id' => 10,
		'_categories' => [
			[
				'category_id' => 30,
				'category_name' => 'LED Lampen',
				'parent_category_id' => 10,
				'_parent_category' => [
					'category_id' => 10,
					'category_name' => 'Verlichting',
				],
			],
			[
				'category_id' => 40,
				'category_name' => 'TL Buizen',
				'parent_category_id' => 10,
				'_parent_category' => [
					'category_id' => 10,
					'category_name' => 'Verlichting',
				],
			],
		],
	];

	$result = $this->mapper->get_categories($product);

	// Verlichting (id=10) should appear only once despite two children referencing it
	expect($result)->toHaveCount(3);
	expect($result[0]['id'])->toBe(10);
	expect($result[0]['name'])->toBe('Verlichting');
	expect($result[1]['id'])->toBe(30);
	expect($result[1]['name'])->toBe('LED Lampen');
	expect($result[2]['id'])->toBe(40);
	expect($result[2]['name'])->toBe('TL Buizen');
});

test('get_categories handles 4-level deep tree', function () {
	$product = [
		'product_id' => 11,
		'_categories' => [
			[
				'category_id' => 400,
				'category_name' => 'Philips LED Spot',
				'parent_category_id' => 300,
				'_parent_category' => [
					'category_id' => 300,
					'category_name' => 'LED Lampen',
					'parent_category_id' => 200,
					'_parent_category' => [
						'category_id' => 200,
						'category_name' => 'Verlichting',
						'parent_category_id' => 100,
						'_parent_category' => [
							'category_id' => 100,
							'category_name' => 'Elektra',
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_categories($product);

	expect($result)->toHaveCount(4);
	expect($result[0]['id'])->toBe(100);
	expect($result[0]['name'])->toBe('Elektra');
	expect($result[0]['parent_id'])->toBeNull();

	expect($result[1]['id'])->toBe(200);
	expect($result[1]['name'])->toBe('Verlichting');
	expect($result[1]['parent_id'])->toBe(100);

	expect($result[2]['id'])->toBe(300);
	expect($result[2]['name'])->toBe('LED Lampen');
	expect($result[2]['parent_id'])->toBe(200);

	expect($result[3]['id'])->toBe(400);
	expect($result[3]['name'])->toBe('Philips LED Spot');
	expect($result[3]['parent_id'])->toBe(300);
});

// ------------------------------------------------------------------
// get_category_names()
// ------------------------------------------------------------------

test('get_category_names returns unique names', function () {
	$product = [
		'product_id' => 8,
		'_categories' => [
			['category_id' => 10, 'category_name' => 'Schroeven'],
			['category_id' => 20, 'category_name' => 'Bouten'],
		],
	];

	$names = $this->mapper->get_category_names($product);

	expect($names)->toBe(['Schroeven', 'Bouten']);
});

// ------------------------------------------------------------------
// CATEGORY_ID_META constant
// ------------------------------------------------------------------

test('CATEGORY_ID_META constant is accessible', function () {
	expect(Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META)->toBe('_skwirrel_category_id');
});
