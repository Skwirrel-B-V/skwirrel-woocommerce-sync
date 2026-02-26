<?php

declare(strict_types=1);

beforeEach(function () {
	$this->resolver = new Skwirrel_WC_Sync_Slug_Resolver();
});

afterEach(function () {
	unset($GLOBALS['_test_options']);
});

/**
 * Helper: set permalink settings for a single test.
 */
function set_permalink_options(array $opts): void {
	$GLOBALS['_test_options']['skwirrel_wc_sync_permalinks'] = array_merge([
		'slug_source_field'     => 'product_name',
		'slug_suffix_field'     => '',
		'update_slug_on_resync' => false,
	], $opts);
}

// ------------------------------------------------------------------
// resolve() — source field = product_name (default)
// ------------------------------------------------------------------

test('resolve returns null when source is product_name', function () {
	set_permalink_options(['slug_source_field' => 'product_name']);

	$product = [
		'product_id' => 1,
		'internal_product_code' => 'ABC-123',
	];

	expect($this->resolver->resolve($product))->toBeNull();
});

// ------------------------------------------------------------------
// resolve() — source field = internal_product_code
// ------------------------------------------------------------------

test('resolve returns sanitized SKU when source is internal_product_code', function () {
	set_permalink_options(['slug_source_field' => 'internal_product_code']);

	$product = [
		'product_id' => 1,
		'internal_product_code' => 'ABC 123',
		'manufacturer_product_code' => 'MFG-001',
	];

	expect($this->resolver->resolve($product))->toBe('abc-123');
});

test('resolve returns sanitized manufacturer code', function () {
	set_permalink_options(['slug_source_field' => 'manufacturer_product_code']);

	$product = [
		'product_id' => 1,
		'internal_product_code' => 'INT-001',
		'manufacturer_product_code' => 'MFG 999',
	];

	expect($this->resolver->resolve($product))->toBe('mfg-999');
});

test('resolve returns external_product_id as slug', function () {
	set_permalink_options(['slug_source_field' => 'external_product_id']);

	$product = [
		'product_id' => 1,
		'external_product_id' => 'EXT-42',
	];

	expect($this->resolver->resolve($product))->toBe('ext-42');
});

test('resolve returns product_id as slug', function () {
	set_permalink_options(['slug_source_field' => 'product_id']);

	$product = [
		'product_id' => 42,
	];

	expect($this->resolver->resolve($product))->toBe('42');
});

// ------------------------------------------------------------------
// resolve() — empty / missing field returns null
// ------------------------------------------------------------------

test('resolve returns null when source field is empty', function () {
	set_permalink_options(['slug_source_field' => 'internal_product_code']);

	$product = [
		'product_id' => 1,
		'internal_product_code' => '',
	];

	expect($this->resolver->resolve($product))->toBeNull();
});

test('resolve returns null when source field is missing', function () {
	set_permalink_options(['slug_source_field' => 'internal_product_code']);

	$product = [
		'product_id' => 1,
	];

	expect($this->resolver->resolve($product))->toBeNull();
});

// ------------------------------------------------------------------
// resolve_for_group() — grouped product field mapping
// ------------------------------------------------------------------

test('resolve_for_group returns null when source is product_name', function () {
	set_permalink_options(['slug_source_field' => 'product_name']);

	$group = [
		'grouped_product_id' => 10,
		'grouped_product_code' => 'GRP-001',
	];

	expect($this->resolver->resolve_for_group($group))->toBeNull();
});

test('resolve_for_group uses grouped_product_code for internal_product_code', function () {
	set_permalink_options(['slug_source_field' => 'internal_product_code']);

	$group = [
		'grouped_product_id' => 10,
		'grouped_product_code' => 'GRP 001',
	];

	expect($this->resolver->resolve_for_group($group))->toBe('grp-001');
});

test('resolve_for_group uses grouped_product_id for product_id', function () {
	set_permalink_options(['slug_source_field' => 'product_id']);

	$group = [
		'grouped_product_id' => 99,
		'grouped_product_code' => 'GRP-001',
	];

	expect($this->resolver->resolve_for_group($group))->toBe('99');
});

test('resolve_for_group falls back to internal_product_code when grouped_product_code missing', function () {
	set_permalink_options(['slug_source_field' => 'internal_product_code']);

	$group = [
		'grouped_product_id' => 10,
		'internal_product_code' => 'INT 555',
	];

	expect($this->resolver->resolve_for_group($group))->toBe('int-555');
});

// ------------------------------------------------------------------
// should_update_on_resync()
// ------------------------------------------------------------------

test('should_update_on_resync returns false by default', function () {
	set_permalink_options(['update_slug_on_resync' => false]);

	expect($this->resolver->should_update_on_resync())->toBeFalse();
});

test('should_update_on_resync returns true when enabled', function () {
	set_permalink_options(['update_slug_on_resync' => true]);

	expect($this->resolver->should_update_on_resync())->toBeTrue();
});

// ------------------------------------------------------------------
// Permalink_Settings::get_options() — defaults & backward compat
// ------------------------------------------------------------------

test('get_options returns defaults when no option exists', function () {
	$opts = Skwirrel_WC_Sync_Permalink_Settings::get_options();

	expect($opts)->toBeArray();
	expect($opts['slug_source_field'])->toBe('product_name');
	expect($opts['slug_suffix_field'])->toBe('');
	expect($opts['update_slug_on_resync'])->toBeFalse();
});

test('get_options falls back to main settings for backward compatibility', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'slug_source_field' => 'internal_product_code',
		'slug_suffix_field' => 'manufacturer_product_code',
	];

	$opts = Skwirrel_WC_Sync_Permalink_Settings::get_options();

	expect($opts['slug_source_field'])->toBe('internal_product_code');
	expect($opts['slug_suffix_field'])->toBe('manufacturer_product_code');
	expect($opts['update_slug_on_resync'])->toBeFalse();
});

test('get_options prefers dedicated option over main settings', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_permalinks'] = [
		'slug_source_field'     => 'external_product_id',
		'slug_suffix_field'     => 'product_id',
		'update_slug_on_resync' => true,
	];
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'slug_source_field' => 'internal_product_code',
	];

	$opts = Skwirrel_WC_Sync_Permalink_Settings::get_options();

	expect($opts['slug_source_field'])->toBe('external_product_id');
	expect($opts['slug_suffix_field'])->toBe('product_id');
	expect($opts['update_slug_on_resync'])->toBeTrue();
});
