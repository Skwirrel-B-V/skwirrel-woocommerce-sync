# Testing — Guidelines

## Running Tests

```bash
# Install dependencies first
composer install

# Run all tests
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/ProductMapperCategoryTest.php

# Run with coverage (requires Xdebug or PCOV)
vendor/bin/pest --coverage
```

## Test Structure

- `tests/Unit/` — Unit tests (no WP/WC dependency, fast)
- `tests/Pest.php` — Pest configuration (base test case binding)
- `phpunit.xml.dist` — PHPUnit/Pest configuration (Pest uses PHPUnit's config)
- `tests/bootstrap.php` — Minimal bootstrap with WP/WC stubs

## Writing Tests

- Use Pest's `test()` function syntax (not class-based PHPUnit)
- Use `beforeEach()` for shared setup
- Use `expect()` API for assertions (not `$this->assert*()`)
- File naming: `{ClassName}Test.php` (e.g., `ProductMapperCategoryTest.php`)
- Test naming: `test('descriptive name', function () { ... })`
- Use `dataset()` / `with()` for multiple input variations

### Example

```php
beforeEach(function () {
    $this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_categories extracts from _categories array', function () {
    $product = [
        'product_id' => 1,
        '_categories' => [
            ['category_id' => 10, 'category_name' => 'Schroeven'],
        ],
    ];

    $result = $this->mapper->get_categories($product);

    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('Schroeven');
});
```

## What to Test

Priority areas:
1. **Product Mapper** — field extraction, fallback chains, ETIM parsing, category mapping
2. **Sync Service** — purge stale detection logic, collection filter parsing
3. **JsonRpc Client** — request building, response parsing, error handling
4. **Delete Protection** — force full sync flag setting

## Static Analysis & Code Style

```bash
# PHPStan (level 6)
vendor/bin/phpstan analyse

# PHP_CodeSniffer
vendor/bin/phpcs

# Auto-fix code style
vendor/bin/phpcbf
```
