# Sync Service — Technical Reference

Applies to `includes/class-sync-service.php`.

## run_sync() Flow

1. **Init**: `set_time_limit(0)`, reset `$seen_category_ids`, build client from settings
2. **Grouped products** (if `sync_grouped_products` enabled): call `sync_grouped_products_first()` → builds `$product_to_group_map` (product_id → wc_variable_id + ETIM codes)
3. **API call**: `getProducts` (full) or `getProductsByFilter` (delta) — paginated by `batch_size`
4. **Product loop** per page:
   - Skip VIRTUAL products (unless they belong to a group)
   - Check `product_to_group_map` → upsert as variation **or** upsert as simple product
   - `assign_categories()` if `sync_categories` enabled
   - Extract & save attributes
5. **Purge** (full sync only, no collection filter): trash stale products & remove stale categories
6. **History**: update `skwirrel_wc_sync_last_sync`, store result in `skwirrel_wc_sync_last_result` and append to `skwirrel_wc_sync_history` (max 20)
7. **Return**: `[success, created, updated, failed, trashed, categories_removed]`

## Delta vs Full Sync

- `run_sync(true)` = delta: calls `getProductsByFilter` with `['updated_on' => ['datetime' => $last_sync, 'operator' => '>=']]`
- `run_sync(false)` = full: calls `getProducts` directly
- Last sync stored as ISO: `Y-m-d\TH:i:s\Z` in option `skwirrel_wc_sync_last_sync`
- Force full sync: option `skwirrel_wc_sync_force_full_sync` (set by Delete_Protection when a Skwirrel product is trashed in WC)

## Collection Filter

- Setting `collection_ids`: comma-separated string → parsed into numeric array by `get_collection_ids()`
- Passed to both `getProducts` and `getGroupedProducts` API calls
- **Important**: purge is **skipped** when collection filter is active (you'd trash products from other collections)

## Purge Logic

### Stale Products
- Only runs after **full sync** without collection filter, and only if `purge_stale_products` enabled
- Detection: products with `_skwirrel_external_id` meta where `_skwirrel_synced_at < $sync_started_at`
- SQL safety: `REGEXP '^[0-9]+$'` validation before `CAST(... AS UNSIGNED)` — corrupt meta values are skipped
- Action: move to trash (not permanent delete)
- Variable products: trashing parent automatically trashes all variations
- Pre-purge: logs summary with count and first 20 product IDs

### Stale Categories
- Categories with `_skwirrel_category_id` term meta not seen during sync
- **Safety**: categories with attached (non-trashed) products are **never** deleted — warning logged instead
- Tracked via `$seen_category_ids` array, populated by `find_or_create_category_term()`

## Category Sync

- `assign_categories($product, $wc_product)`: matches categories by Skwirrel ID (term meta), then name, then creates new
- `find_or_create_category_term()`: supports parent-child hierarchy, stores `_skwirrel_category_id` in term meta
- Both methods populate `$seen_category_ids` for purge detection

## Key Meta Keys Written

| Meta | Set by | Purpose |
|------|--------|---------|
| `_skwirrel_external_id` | upsert methods | Primary upsert key |
| `_skwirrel_product_id` | upsert methods | Skwirrel internal ID |
| `_skwirrel_synced_at` | upsert methods + variation upsert | Unix timestamp for stale detection |
| `_skwirrel_grouped_product_id` | sync_grouped_products_first | Links variable → grouped product |
| `_skwirrel_virtual_product_id` | upsert_product_as_variation | Virtual product ID for image assignment |
