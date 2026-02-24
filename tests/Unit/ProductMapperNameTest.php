<?php

declare(strict_types=1);

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

// ------------------------------------------------------------------
// get_name()
// ------------------------------------------------------------------

test('get_name prefers product_erp_description', function () {
	$product = [
		'product_erp_description' => 'ERP Product Name',
		'_product_translations' => [
			['language' => 'nl', 'product_model' => 'Model Name'],
		],
	];

	expect($this->mapper->get_name($product))->toBe('ERP Product Name');
});

test('get_name falls back to translated product_model', function () {
	$product = [
		'_product_translations' => [
			['language' => 'nl', 'product_model' => 'Boormachine Pro'],
		],
	];

	expect($this->mapper->get_name($product))->toBe('Boormachine Pro');
});

test('get_name falls back to translated product_description', function () {
	$product = [
		'_product_translations' => [
			['language' => 'nl', 'product_description' => 'Krachtige boormachine'],
		],
	];

	expect($this->mapper->get_name($product))->toBe('Krachtige boormachine');
});

test('get_name returns empty string when no data', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->get_name($product))->toBe('');
});

test('get_name ignores empty erp_description', function () {
	$product = [
		'product_erp_description' => '',
		'_product_translations' => [
			['language' => 'nl', 'product_model' => 'Fallback Name'],
		],
	];

	expect($this->mapper->get_name($product))->toBe('Fallback Name');
});

// ------------------------------------------------------------------
// get_short_description()
// ------------------------------------------------------------------

test('get_short_description extracts translated product_description', function () {
	$product = [
		'_product_translations' => [
			['language' => 'nl', 'product_description' => 'Korte omschrijving'],
		],
	];

	expect($this->mapper->get_short_description($product))->toBe('Korte omschrijving');
});

test('get_short_description returns empty when no translations', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->get_short_description($product))->toBe('');
});

// ------------------------------------------------------------------
// get_long_description()
// ------------------------------------------------------------------

test('get_long_description prefers product_long_description', function () {
	$product = [
		'_product_translations' => [
			[
				'language' => 'nl',
				'product_long_description' => 'Uitgebreide beschrijving',
				'product_marketing_text' => 'Marketing tekst',
				'product_web_text' => 'Web tekst',
			],
		],
	];

	expect($this->mapper->get_long_description($product))->toBe('Uitgebreide beschrijving');
});

test('get_long_description falls back to product_marketing_text', function () {
	$product = [
		'_product_translations' => [
			[
				'language' => 'nl',
				'product_marketing_text' => 'Marketing tekst',
				'product_web_text' => 'Web tekst',
			],
		],
	];

	expect($this->mapper->get_long_description($product))->toBe('Marketing tekst');
});

test('get_long_description falls back to product_web_text', function () {
	$product = [
		'_product_translations' => [
			[
				'language' => 'nl',
				'product_web_text' => 'Web tekst',
			],
		],
	];

	expect($this->mapper->get_long_description($product))->toBe('Web tekst');
});

test('get_long_description returns empty when no translations', function () {
	$product = ['product_id' => 1];

	expect($this->mapper->get_long_description($product))->toBe('');
});

test('get_long_description returns empty when all fields missing', function () {
	$product = [
		'_product_translations' => [
			['language' => 'nl'],
		],
	];

	expect($this->mapper->get_long_description($product))->toBe('');
});
