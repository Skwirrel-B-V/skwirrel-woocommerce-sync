# Product Mapper — Technical Reference

Applies to `includes/class-product-mapper.php`.

## Constants

```php
EXTERNAL_ID_META  = '_skwirrel_external_id'
PRODUCT_ID_META   = '_skwirrel_product_id'
SYNCED_AT_META    = '_skwirrel_synced_at'
CATEGORY_ID_META  = '_skwirrel_category_id'  // public const
```

## Field Mapping

| Method | Skwirrel Source | WC Target | Notes |
|--------|----------------|-----------|-------|
| `get_unique_key()` | `external_product_id` > `internal_product_code` > `manufacturer_product_code` > `product_id` | upsert key (meta) | Returns prefixed: `ext:`, `sku:`, `id:` |
| `get_sku()` | `internal_product_code` or `manufacturer_product_code` (via `use_sku_field` setting) | `_sku` | Fallback: `SKW-{product_id}` |
| `get_name()` | `product_erp_description` > translations.`product_model`/`product_description` | `post_title` | Prefers ERP description |
| `get_short_description()` | translations.`product_description` | `post_excerpt` | — |
| `get_long_description()` | translations.`product_long_description` > `product_marketing_text` > `product_web_text` | `post_content` | Fallback chain |
| `get_status()` | `product_trashed_on` / `_product_status.product_status_description` | `post_status` | trash/draft/publish |
| `get_regular_price()` | `_trade_items[0]._trade_item_prices[0].net_price` | `_regular_price` | null if price_on_request |

## Category Mapping — get_categories()

**Primary source**: `$product['_categories']` array (via `include_categories` API flag):
```
{ category_id, category_name, _category_translations[], parent_category_id?, _parent_category? }
```

**Fallback source**: `$product['_product_groups'][n].product_group_name` (legacy, no ID)

**Returns**: `[{ id, name, parent_id, parent_name }, ...]`

**Deduplication**: by lowercase name (last occurrence wins)

**Translation**: `pick_category_translation()` → matches `image_language` setting against `_category_translations[].language`

## ETIM Attributes

- `collect_etim_items()` — finds all ETIM sources (product._etim, product._product_groups[n]._etim, recursive fallback)
- `get_etim_attributes()` — normalizes features, respects language setting, skips `not_applicable=true`
- `get_etim_feature_values_for_codes()` — for variation attributes: filters by specific ETIM codes from group definition
- Feature types: A=alphanumeric, L=logical (Ja/Nee), N=numeric, R=range, C=class, M=modelling

## Image & Document Handling

- `get_image_attachment_ids()` — filters attachments by image type, sorted by `product_attachment_order`
- `get_downloadable_files()` — non-image attachments → `[{name, file: guid}]`
- `get_document_attachments()` — all non-image attachments → `[{id, url, name, type, type_label}]`
- Language selection for titles: uses `image_language` setting
