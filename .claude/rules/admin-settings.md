# Admin Settings — Technical Reference

Applies to `includes/class-admin-settings.php`.

## Storage

- **Settings array**: `skwirrel_wc_sync_settings` option
- **Auth token**: `skwirrel_wc_sync_auth_token` (separate option, never exposed in settings export)
- **Sanitization**: all input via `sanitize_settings()` callback

## All Settings Keys

| Key | Type | Default | Constraints | Description |
|-----|------|---------|-------------|-------------|
| `endpoint_url` | string | `''` | `esc_url_raw()` | JSON-RPC endpoint URL |
| `auth_type` | select | `'bearer'` | bearer\|token | Bearer vs X-Skwirrel-Api-Token |
| `auth_token` | password | `''` | masked as `••••••••` | Stored separately |
| `timeout` | int | `30` | 5–120 | HTTP timeout (seconds) |
| `retries` | int | `2` | 0–5 | API retry count |
| `sync_interval` | select | `''` | from Action_Scheduler | Cron interval |
| `batch_size` | int | `100` | 10–500 | Products per API page |
| `sync_categories` | bool | `false` | — | Create/assign WC categories |
| `sync_grouped_products` | bool | `false` | — | Enable getGroupedProducts |
| `sync_images` | bool | `true` | — | Download images to media library |
| `image_language` | string | `'nl'` | dropdown + custom | Language for image labels |
| `include_languages` | array | `['nl-NL', 'nl']` | checkboxes + custom | API include_languages param |
| `collection_ids` | string | `''` | comma-separated numeric | Collection filter |
| `use_sku_field` | select | `'internal_product_code'` | two options | SKU source field |
| `verbose_logging` | bool | `false` | — | Per-product log output |
| `purge_stale_products` | bool | `false` | — | Trash removed products |
| `show_delete_warning` | bool | `true` | — | Warning banners on Skwirrel items |

## Sanitization Rules

- **Token**: if input = `••••••••` or empty → keep existing stored token; otherwise update separate option
- **Image language**: `image_language_select === '_custom'` + custom input → use custom value; else dropdown; fallback `'nl'`
- **Include languages**: merge checkboxes + comma-separated custom field → deduplicate; fallback `['nl-NL', 'nl']`
- **Collection IDs**: `preg_split('/[\s,]+/')` → keep numeric only → rejoin comma-space

## UI Conventions

- Page slug: `skwirrel-pim-wp-sync` (submenu under WooCommerce)
- Capability: `manage_woocommerce`
- Language: English source text with translations, text domain `skwirrel-pim-wp-sync`
- Tables: `.form-table` for settings, `.widefat` for results/history
- Nonce: standard `_wpnonce` via `settings_fields()`
- Background sync: fires AJAX to `wp_ajax_skwirrel_wc_sync_background`, gated by transient `skwirrel_wc_sync_bg_token`

## Sync Result Display

- Last result: table with created/updated/failed/trashed/categories_removed rows
- History: last 20 entries in `.widefat` table (timestamp, trigger, success, counts, trashed)
- Trigger column: Manual, Scheduled, or Purge
- Error row: red background for failed syncs
- Trashed row: yellow background
- Purge row: yellow background with purge details
