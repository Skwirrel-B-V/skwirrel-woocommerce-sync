# CLAUDE.md — Skwirrel WooCommerce Sync

## Project Overview

WordPress plugin that synchronises products from the Skwirrel ERP/PIM system into WooCommerce via a JSON-RPC 2.0 API. Written in PHP 8.1+, targeting WordPress 6.x and WooCommerce 8+.

The plugin is Dutch-facing (UI strings in Dutch, text domain `skwirrel-wc-sync`).

## Architecture

Singleton-based class architecture without Composer autoloading — all classes are loaded via `require_once` in the main plugin file.

### Key Classes & Responsibilities

| Class | File | Role |
|-------|------|------|
| `Skwirrel_WC_Sync_Plugin` | `skwirrel-woocommerce-sync.php` | Bootstrap, dependency loading, hook registration |
| `Skwirrel_WC_Sync_Admin_Settings` | `includes/class-admin-settings.php` | Admin UI, settings persistence, manual sync trigger |
| `Skwirrel_WC_Sync_Service` | `includes/class-sync-service.php` | Core sync orchestrator — fetches, maps, upserts products |
| `Skwirrel_WC_Sync_Product_Mapper` | `includes/class-product-mapper.php` | Translates Skwirrel API data to WooCommerce field values |
| `Skwirrel_WC_Sync_JsonRpc_Client` | `includes/class-jsonrpc-client.php` | HTTP client for Skwirrel JSON-RPC API |
| `Skwirrel_WC_Sync_Media_Importer` | `includes/class-media-importer.php` | Downloads images/files into WP media library |
| `Skwirrel_WC_Sync_Action_Scheduler` | `includes/class-action-scheduler.php` | Cron/Action Scheduler job management |
| `Skwirrel_WC_Sync_Logger` | `includes/class-logger.php` | Logging wrapper around WC_Logger |
| `Skwirrel_WC_Sync_Product_Documents` | `includes/class-product-documents.php` | Frontend documents tab + admin meta box |
| `Skwirrel_WC_Sync_Variation_Attributes_Fix` | `includes/class-variation-attributes-fix.php` | Patches WooCommerce variation attribute bugs |

### Dependency Flow

```
Admin_Settings
  ├── JsonRpc_Client → Logger
  ├── Action_Scheduler → Sync_Service
  └── Sync_Service
        ├── Logger
        ├── Product_Mapper → Media_Importer → Logger
        └── JsonRpc_Client

Product_Documents (standalone)
Variation_Attributes_Fix (static, standalone)
```

### External API

All calls go to a configured JSON-RPC endpoint (e.g. `https://xxx.skwirrel.eu/jsonrpc`):

- `getProducts` — full paginated product list
- `getProductsByFilter` — delta sync (filter by `updated_on >= last_sync`)
- `getGroupedProducts` — variable product groups with ETIM variation axes

Authentication: Bearer token or `X-Skwirrel-Api-Token` header.

## Conventions

- **PHP version**: 8.1+ with `declare(strict_types=1)` in the main file
- **Naming**: `Skwirrel_WC_Sync_` prefix for all classes; files named `class-{slug}.php`
- **Singletons**: Most classes use `::instance()` pattern with private constructors
- **No autoloader**: All includes are manual `require_once` in the bootstrap
- **Settings storage**: Main settings in `skwirrel_wc_sync_settings` option; auth token stored separately in `skwirrel_wc_sync_auth_token`
- **Logging**: Always use `Skwirrel_WC_Sync_Logger` (wraps `wc_get_logger()`, source `skwirrel-wc-sync`)
- **WooCommerce hooks**: Use standard WC filter/action naming conventions
- **Templates**: Follow WooCommerce template override pattern (`templates/` dir, overridable in theme)
- **Text domain**: `skwirrel-wc-sync`
- **Language**: UI text and comments are in Dutch

## Important Post Meta Keys

| Key | Purpose |
|-----|---------|
| `_skwirrel_external_id` | Skwirrel external product ID (primary upsert key) |
| `_skwirrel_product_id` | Skwirrel internal product ID |
| `_skwirrel_synced_at` | Last sync timestamp for this product |
| `_skwirrel_source_url` | Original CDN URL for media attachments |
| `_skwirrel_url_hash` | SHA-256 hash of source URL (media deduplication) |
| `_skwirrel_document_attachments` | Serialized array of document metadata |

## WP Options

| Option | Purpose |
|--------|---------|
| `skwirrel_wc_sync_settings` | Main plugin settings array |
| `skwirrel_wc_sync_auth_token` | API auth token (stored separately, never exposed in settings export) |
| `skwirrel_wc_sync_last_sync` | ISO timestamp of last sync run |
| `skwirrel_wc_sync_last_result` | Result array of last sync (success, counts) |
| `skwirrel_wc_sync_history` | Array of last 20 sync results |

## Sync Flow

1. Admin clicks "Sync nu" → background HTTP loopback fires the sync
2. Scheduled sync fires via Action Scheduler / WP-Cron
3. `Sync_Service::run_sync($delta)`:
   - If grouped products enabled: fetch groups first, create `WC_Product_Variable` shells
   - Paginate through products via API
   - For each product: resolve unique key → find existing or create new → map fields → save
   - Products belonging to a group become `WC_Product_Variation`
   - Delta sync filters by `updated_on >= last_sync`

## Development Notes

- No build step or frontend JS — admin uses plain PHP-rendered forms
- CSS assets: `assets/admin.css` (admin settings page) and `assets/product-documents.css` (frontend documents tab)
- `debug-variations.php` is a standalone debug/diagnostic script (not loaded in production)
- The `SKWIRREL_WC_SYNC_DEBUG_ETIM` constant enables detailed ETIM debug logging to the uploads directory
- The `SKWIRREL_VERBOSE_SYNC` constant or `verbose_logging` setting enables verbose log output
- No test suite currently exists
- See `ASSUMPTIONS.md` for design decisions where the Skwirrel API docs were ambiguous
