<?php

declare(strict_types=1);

// ------------------------------------------------------------------
// get_unique_key()
// ------------------------------------------------------------------

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_unique_key prefers external_product_id', function () {
	$product = [
		'product_id' => 42,
		'external_product_id' => 'EXT-123',
		'internal_product_code' => 'SKU-456',
	];

	expect($this->mapper->get_unique_key($product))->toBe('ext:EXT-123');
});

test('get_unique_key falls back to internal_product_code', function () {
	$product = [
		'product_id' => 42,
		'internal_product_code' => 'SKU-456',
	];

	expect($this->mapper->get_unique_key($product))->toBe('sku:SKU-456');
});

test('get_unique_key falls back to manufacturer_product_code', function () {
	$product = [
		'product_id' => 42,
		'manufacturer_product_code' => 'MFG-789',
	];

	expect($this->mapper->get_unique_key($product))->toBe('sku:MFG-789');
});

test('get_unique_key falls back to product_id', function () {
	$product = [
		'product_id' => 42,
	];

	expect($this->mapper->get_unique_key($product))->toBe('id:42');
});

test('get_unique_key returns null when no identifiers', function () {
	$product = [];

	expect($this->mapper->get_unique_key($product))->toBeNull();
});

test('get_unique_key ignores empty string external_product_id', function () {
	$product = [
		'external_product_id' => '',
		'internal_product_code' => 'SKU-456',
	];

	expect($this->mapper->get_unique_key($product))->toBe('sku:SKU-456');
});

// ------------------------------------------------------------------
// get_status()
// ------------------------------------------------------------------

test('get_status returns publish by default', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->get_status($product))->toBe('publish');
});

test('get_status returns trash when product_trashed_on set', function () {
	$product = [
		'product_id' => 1,
		'product_trashed_on' => '2024-01-15T10:00:00Z',
	];

	expect($this->mapper->get_status($product))->toBe('trash');
});

test('get_status returns draft when status description contains draft', function () {
	$product = [
		'product_id' => 1,
		'_product_status' => [
			'product_status_description' => 'Draft - not published',
		],
	];

	expect($this->mapper->get_status($product))->toBe('draft');
});

test('get_status prefers trash over draft', function () {
	$product = [
		'product_id' => 1,
		'product_trashed_on' => '2024-01-15T10:00:00Z',
		'_product_status' => [
			'product_status_description' => 'Draft',
		],
	];

	expect($this->mapper->get_status($product))->toBe('trash');
});

// ------------------------------------------------------------------
// get_price() / get_regular_price()
// ------------------------------------------------------------------

test('get_price extracts net_price from first trade item', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['net_price' => 12.50],
				],
			],
		],
	];

	expect($this->mapper->get_price($product))->toBe(12.50);
});

test('get_price returns null when no trade items', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->get_price($product))->toBeNull();
});

test('get_price returns null for price_on_request', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['net_price' => 100.00, 'price_on_request' => true],
				],
			],
		],
	];

	expect($this->mapper->get_price($product))->toBeNull();
});

test('get_price handles zero price', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['net_price' => 0],
				],
			],
		],
	];

	expect($this->mapper->get_price($product))->toBe(0.0);
});

test('get_regular_price delegates to get_price', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['net_price' => 25.99],
				],
			],
		],
	];

	expect($this->mapper->get_regular_price($product))->toBe(25.99);
});

// ------------------------------------------------------------------
// is_price_on_request()
// ------------------------------------------------------------------

test('is_price_on_request returns true when flag set', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['price_on_request' => true],
				],
			],
		],
	];

	expect($this->mapper->is_price_on_request($product))->toBeTrue();
});

test('is_price_on_request returns false when no flag', function () {
	$product = [
		'_trade_items' => [
			[
				'_trade_item_prices' => [
					['net_price' => 10.00],
				],
			],
		],
	];

	expect($this->mapper->is_price_on_request($product))->toBeFalse();
});

test('is_price_on_request returns false when no trade items', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->is_price_on_request($product))->toBeFalse();
});

// ------------------------------------------------------------------
// Meta key accessors
// ------------------------------------------------------------------

test('get_external_id_meta_key returns correct key', function () {
	expect($this->mapper->get_external_id_meta_key())->toBe('_skwirrel_external_id');
});

test('get_product_id_meta_key returns correct key', function () {
	expect($this->mapper->get_product_id_meta_key())->toBe('_skwirrel_product_id');
});

test('get_synced_at_meta_key returns correct key', function () {
	expect($this->mapper->get_synced_at_meta_key())->toBe('_skwirrel_synced_at');
});

test('CATEGORY_ID_META constant is accessible', function () {
	expect(Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META)->toBe('_skwirrel_category_id');
});
