<?php

declare(strict_types=1);

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

// ------------------------------------------------------------------
// get_sku() — default use_sku_field = internal_product_code
// ------------------------------------------------------------------

test('get_sku returns internal_product_code by default', function () {
	$product = [
		'product_id' => 1,
		'internal_product_code' => 'INT-001',
		'manufacturer_product_code' => 'MFG-001',
	];

	expect($this->mapper->get_sku($product))->toBe('INT-001');
});

test('get_sku falls back to manufacturer_product_code when internal empty', function () {
	$product = [
		'product_id' => 1,
		'manufacturer_product_code' => 'MFG-001',
	];

	expect($this->mapper->get_sku($product))->toBe('MFG-001');
});

test('get_sku generates SKW-{id} fallback when both codes empty', function () {
	$product = [
		'product_id' => 42,
	];

	expect($this->mapper->get_sku($product))->toBe('SKW-42');
});

test('get_sku returns empty string when no codes and no product_id', function () {
	$product = [];

	expect($this->mapper->get_sku($product))->toBe('');
});
