# Skwirrel WooCommerce Sync — Volledige Feature Roadmap

## Context

Na een grondige codebase-analyse zijn 8 bugs/risico's, 7 hoge-prioriteit features, 8 medium features en 10 nice-to-haves geidentificeerd. Alle items zijn geimplementeerd.

---

## Fase 1: Bugs & Risico's (Kritiek) - DONE

### 1.1 HPOS compatibility -> `true`
**Bestand:** `skwirrel-woocommerce-sync.php:39`
- Veranderd `false` naar `true` in `declare_compatibility('custom_order_tables', ...)`. Plugin raakt geen orders aan.

### 1.2 Fix hardcoded `'Ja'`/`'Nee'`
**Bestand:** `includes/class-product-mapper.php:661`
- Vervangen door `__('Ja', 'skwirrel-wc-sync')` / `__('Nee', 'skwirrel-wc-sync')`.
- Toegevoegd aan alle .po/.mo bestanden (en: Yes/No, fr: Oui/Non, de: Ja/Nein).

### 1.3 Fix `format_downloads()` keys
**Bestand:** `includes/class-sync-service.php`
- Veranderd `(string) $i` key naar `md5($f['file'])` (WooCommerce verwachting).

### 1.4 Fix broken template file
**Bestand:** `templates/single-product/tabs/skwirrel-documents.php`
- Fixed key mismatches: gebruikt nu `id`, `url`, `name`, `type`, `type_label` direct.
- Template geladen via `wc_get_template()` i.p.v. inline rendering.

### 1.5 Verwijder debug-variations.php
- Verwijderd uit het project.

### 1.6 Sync lock (concurrency prevention)
**Bestand:** `includes/class-sync-service.php`
- `get_transient('skwirrel_wc_sync_running')` check aan begin van `run_sync()`.
- Transient met TTL van 1 uur, try/finally wrapping.

### 1.7 Memory management
**Bestand:** `includes/class-sync-service.php`
- Na elke 50 producten: `wp_cache_flush()` + `gc_collect_cycles()`.
- Log `memory_get_usage()` in verbose mode per batch.

### 1.8 uninstall.php
**Nieuw bestand:** `uninstall.php`
- Verwijdert opties, post meta, term meta, transients, scheduled actions.
- Multisite support: cleanup per site.

### 1.8b Deactivation hook
**Bestand:** `skwirrel-woocommerce-sync.php`
- `register_deactivation_hook()` die scheduled actions verwijdert maar data behoudt.

---

## Fase 2: Prioriteit 1 — Hoog - DONE

### 2.1 Voorraad sync
**Bestanden:** `includes/class-product-mapper.php`, `includes/class-sync-service.php`, `includes/class-admin-settings.php`
- Nieuwe `get_stock_data()` methode: leest `stock_quantity`, `quantity_available`, `available_quantity`, `stock`.
- Admin setting: `stock_management` (Uit/Aan). Default: uit (backward compatible).
- In `upsert_product()` en `upsert_product_as_variation()`: `set_manage_stock()`, `set_stock_quantity()`, etc.

### 2.2 Orphan cleanup
**Bestand:** `includes/class-sync-service.php`
- Na full sync: vergelijkt synced external IDs met database.
- Admin setting: `orphan_action` -> "Niets doen" / "Naar concept" / "Naar prullenbak". Default: niets.

### 2.3 Gewicht & afmetingen
**Bestand:** `includes/class-product-mapper.php`
- Nieuwe `get_physical_data()` methode: leest weight, length, width, height, depth.
- Toegepast in `upsert_product()` en `upsert_product_as_variation()`.

### 2.4 Actieprijs (sale price)
**Bestand:** `includes/class-product-mapper.php`
- Nieuwe `get_sale_price()` methode: leest discounts en vergelijkt gross/net price.
- `get_regular_price()` updated om gross_price als regular te gebruiken bij sale.

### 2.5 Extensibility hooks
**Bestand:** `includes/class-sync-service.php`
- `do_action('skwirrel_wc_sync_before_sync', $delta)`
- `do_action('skwirrel_wc_sync_after_sync', $result)`
- `apply_filters('skwirrel_wc_sync_product_name', $name, $product)`
- `apply_filters('skwirrel_wc_sync_product_price', $price, $product)`
- `apply_filters('skwirrel_wc_sync_product_attributes', $attrs, $product)`
- `apply_filters('skwirrel_wc_sync_product_categories', $categories, $product)`
- `do_action('skwirrel_wc_sync_after_product_save', $wc_id, $product, $outcome)`
- `apply_filters('skwirrel_wc_sync_api_params', $get_params, $delta)`

### 2.6 Reduce `save()` calls
**Bestand:** `includes/class-sync-service.php`
- Alle properties gezet voor eerste `save()`.
- Tweede save alleen voor images/downloads. Was 3, nu max 2.

### 2.7 GTIN als WooCommerce native veld
**Bestand:** `includes/class-sync-service.php`
- `set_global_unique_id()` als WC 8.4+ (method_exists check).
- GTIN custom attribute behouden voor backward compatibility.

---

## Fase 3: Prioriteit 2 — Medium - DONE

### 3.1 Product tags
**Bestanden:** `includes/class-product-mapper.php`, `includes/class-sync-service.php`
- Nieuwe `get_tags()` methode: leest `_tags`, `_keywords`, `_product_tags`. Fallback: `brand_name`.
- `wp_set_object_terms($id, $tags, 'product_tag', false)` na categorien.

### 3.2 Realtime voortgangsindicator
**Bestanden:** `includes/class-sync-service.php`, `includes/class-admin-settings.php`
- `update_option('skwirrel_wc_sync_progress', ...)` in sync loop.
- AJAX endpoint `wp_ajax_skwirrel_wc_sync_progress`.
- Inline JS: pollt elke 3-5 seconden, toont progress bar met details.

### 3.3 Per-product sync status kolom
**Nieuw bestand:** `includes/class-admin-columns.php`
- "Skwirrel Sync" kolom in productlijst met `_skwirrel_synced_at` datum.
- Sorteerbaar.
- Toont lock-icoon als sync-beschermd.

### 3.4 Conflict resolution / sync protection
**Bestanden:** `includes/class-sync-service.php`, `includes/class-admin-settings.php`
- `_skwirrel_sync_protected` meta check voor upsert.
- Admin meta box met checkbox "Bescherm tegen overschrijving".

### 3.5 Tax class mapping
**Bestanden:** `includes/class-product-mapper.php`, `includes/class-admin-settings.php`, `includes/class-sync-service.php`
- Admin setting: `default_tax_class` (dropdown met WC tax classes).
- Automatische mapping: 0% -> zero-rate, <=10% -> reduced-rate.

### 3.6 Shipping class
**Bestanden:** `includes/class-admin-settings.php`, `includes/class-sync-service.php`
- Admin setting: `default_shipping_class` (dropdown met WC shipping classes).

### 3.7 Catalog visibility mapping
**Bestand:** `includes/class-sync-service.php`
- `price_on_request` producten: `set_catalog_visibility('catalog')`.

### 3.8 Merk als taxonomy
**Bestanden:** `includes/class-sync-service.php`, `includes/class-admin-settings.php`
- Check `taxonomy_exists('product_brand')` of `taxonomy_exists('pwb-brand')`.
- Admin setting: checkbox "Merk als taxonomy gebruiken".

---

## Fase 4: Prioriteit 3 — Nice-to-have - DONE

### 4.1 REST API endpoints
**Nieuw bestand:** `includes/class-rest-api.php`
- `GET /wp-json/skwirrel-wc-sync/v1/status` — laatste sync, is running, progress.
- `POST /wp-json/skwirrel-wc-sync/v1/trigger` — trigger sync (capability check).
- `GET /wp-json/skwirrel-wc-sync/v1/history` — sync geschiedenis.
- Capability: `manage_woocommerce`.

### 4.2 Webhook endpoint
**Bestand:** `includes/class-rest-api.php`
- `POST /wp-json/skwirrel-wc-sync/v1/webhook` — accepteert product update notificaties.
- Auth via webhook secret (admin setting).
- Bij ontvangst: queue delta sync via Action Scheduler.

### 4.3 SEO meta mapping
**Bestand:** `includes/class-sync-service.php`
- `apply_seo_meta()` methode: mapped `product_marketing_text` naar Yoast of Rank Math.
- Alleen als veld leeg is (niet overschrijven handmatige edits).

### 4.4 Image optimization hook
**Bestand:** `includes/class-media-importer.php`
- `do_action('skwirrel_wc_sync_after_image_import', $id, $url)` na image import.
- Externe plugins (ShortPixel, Imagify) kunnen hier aanhaken.

### 4.5 Bulk actions in productlijst
**Bestand:** `includes/class-admin-columns.php`
- "Reset Skwirrel sync data" bulk action.
- Verwijdert `_skwirrel_synced_at` voor geselecteerde producten.

### 4.6 Product menu_order
**Bestand:** `includes/class-sync-service.php`
- Zoekt `product_order`, `sort_order`, `display_order` in API data.
- `set_menu_order()` als gevonden.

### 4.7 Log export
**Bestand:** `includes/class-admin-settings.php`
- "Download logs" knop op admin pagina.
- AJAX handler die WC log file als .log download stuurt.

### 4.8 Related/upsell/cross-sell
**Bestanden:** `includes/class-product-mapper.php`, `includes/class-sync-service.php`
- `get_related_skus()` methode: leest `_related_products`, `_accessories`, `_alternatives`.
- Post-sync resolution via `resolve_product_relations()`: SKU -> WC product ID.
- `set_upsell_ids()`, `set_cross_sell_ids()`.

### 4.9 Multisite support
**Bestand:** `uninstall.php`
- `is_multisite()` check in cleanup.
- Per-site cleanup via `switch_to_blog()`.
- Per-site settings al gegarandeerd door WP options API.

### 4.10 HTTP 503 retry
**Bestand:** `includes/class-jsonrpc-client.php`
- 429, 502, 503, 504 als retryable status codes.
- Respecteert `Retry-After` header.

---

## Implementatiesamenvatting

| Stap | Fase | Status | Bestanden |
|------|------|--------|-----------|
| 1 | Bugs 1.1-1.8b | DONE | `skwirrel-woocommerce-sync.php`, `class-product-mapper.php`, `class-sync-service.php`, template, `uninstall.php` |
| 2 | Hoog 2.1-2.7 | DONE | `class-product-mapper.php`, `class-sync-service.php`, `class-admin-settings.php` |
| 3 | Medium 3.1-3.8 | DONE | `class-sync-service.php`, `class-admin-settings.php`, `class-admin-columns.php` (nieuw) |
| 4 | Nice-to-have 4.1-4.10 | DONE | `class-rest-api.php` (nieuw), `class-jsonrpc-client.php`, `class-media-importer.php` |

---

## Nieuwe bestanden

| Bestand | Doel |
|---------|------|
| `uninstall.php` | Plugin cleanup bij verwijdering |
| `includes/class-admin-columns.php` | Product list kolom + bulk actions |
| `includes/class-rest-api.php` | REST API + webhook endpoints |
| `docs/nice-to-have.md` | Dit document |

---

## Nieuwe Admin Settings

| Setting | Type | Default | Beschrijving |
|---------|------|---------|--------------|
| `stock_management` | select | `off` | Voorraad synchroniseren (Uit/Aan) |
| `orphan_action` | select | `nothing` | Actie voor verweesde producten |
| `default_tax_class` | select | `''` | Standaard belastingklasse |
| `default_shipping_class` | select | `''` | Standaard verzendklasse |
| `brand_as_taxonomy` | checkbox | `false` | Merk als taxonomy gebruiken |
| `webhook_secret` | text | `''` | Webhook authenticatie secret |

---

## Nieuwe Hooks

### Actions
- `skwirrel_wc_sync_before_sync($delta)` — Begin van sync
- `skwirrel_wc_sync_after_sync($result)` — Einde van sync
- `skwirrel_wc_sync_after_product_save($wc_id, $product, $outcome)` — Na product save
- `skwirrel_wc_sync_after_image_import($attachment_id, $url)` — Na image import

### Filters
- `skwirrel_wc_sync_api_params($params, $delta)` — API call parameters
- `skwirrel_wc_sync_product_name($name, $product)` — Product naam
- `skwirrel_wc_sync_product_price($price, $product)` — Product prijs
- `skwirrel_wc_sync_product_attributes($attrs, $product)` — Product attributen
- `skwirrel_wc_sync_product_categories($categories, $product)` — Product categorien

---

## REST API Endpoints

| Methode | Endpoint | Auth | Beschrijving |
|---------|----------|------|--------------|
| GET | `/wp-json/skwirrel-wc-sync/v1/status` | `manage_woocommerce` | Sync status & progress |
| GET | `/wp-json/skwirrel-wc-sync/v1/history` | `manage_woocommerce` | Sync geschiedenis |
| POST | `/wp-json/skwirrel-wc-sync/v1/trigger` | `manage_woocommerce` | Start sync |
| POST | `/wp-json/skwirrel-wc-sync/v1/webhook` | Webhook secret header | Ontvang product update notificaties |
