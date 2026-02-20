# Testing — Guidelines

## Running Tests

```bash
# Install dependencies first
composer install

# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/Unit/ProductMapperCategoryTest.php

# Run with coverage (requires Xdebug or PCOV)
vendor/bin/phpunit --coverage-text
```

## Test Structure

- `tests/Unit/` — Unit tests (no WP/WC dependency, fast)
- `phpunit.xml.dist` — PHPUnit 10 configuration
- `tests/bootstrap.php` — Minimal bootstrap with WP/WC stubs

## Writing Tests

- Test classes extend `PHPUnit\Framework\TestCase`
- File naming: `{ClassName}Test.php` (e.g., `ProductMapperCategoryTest.php`)
- Method naming: `test_descriptive_name()` (snake_case)
- Use data providers for multiple input variations

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
