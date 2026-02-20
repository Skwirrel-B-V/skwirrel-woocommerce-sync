# Changelog — Skwirrel WooCommerce Sync

Alle wijzigingen in `feature\update` (1.1.0) ten opzichte van `main` (1.0.0).

---

## [1.1.1] — 2026-02-20

### Nieuw

- **Verwijderbescherming** (`class-delete-protection.php`):
  - Waarschuwingsbanner op product-bewerkpagina (gele banner, "beheerd door Skwirrel")
  - Bevestigingsdialoog bij verwijderen van Skwirrel-producten en -categorieën in lijsten
  - Automatische force full sync na verwijdering van een Skwirrel-item in WooCommerce
  - Instelling `show_delete_warning` (standaard aan) om banners uit te schakelen

- **Purge-logica voor verwijderde producten** (`class-sync-service.php`):
  - Na volledige sync: stale producten (niet meer in Skwirrel) naar prullenbak
  - Detectie via `_skwirrel_synced_at` timestamp vergelijking
  - SQL veiligheid: `REGEXP '^[0-9]+$'` validatie voor corrupte meta-waarden
  - Variable producten: parent + variaties worden samen getrashed
  - Pre-purge logging met samenvatting en eerste 20 product-IDs
  - Opt-in via instelling `purge_stale_products` (standaard uit)

- **Purge-logica voor verwijderde categorieën**:
  - Categorieën met `_skwirrel_category_id` die niet meer gezien zijn worden verwijderd
  - Veiligheidscheck: categorieën met gekoppelde producten worden niet verwijderd
  - Alleen actief wanneer `sync_categories` is ingeschakeld

- **Categorie sync** (`class-sync-service.php`, `class-product-mapper.php`):
  - `get_categories()` met gestructureerde data (id, name, parent_id, parent_name)
  - Primaire bron: `_categories[]` (via `include_categories` API flag)
  - Fallback: `_product_groups[]` (legacy)
  - Parent-child hiërarchie via `_parent_category`
  - Deduplicatie op lowercase naam
  - `assign_categories()`: match op Skwirrel-ID (term meta), dan naam, dan nieuw aanmaken
  - `find_or_create_category_term()`: parent-resolutie + `_skwirrel_category_id` term meta
  - Category tracking via `$seen_category_ids` voor purge-detectie
  - `CATEGORY_ID_META` is nu `public const`

- **Collection ID filter**:
  - Instelling `collection_ids` (comma-separated) — alleen specifieke collecties synchroniseren
  - Doorgegeven aan `getProducts` en `getGroupedProducts` API-calls
  - Purge wordt overgeslagen bij actief collectie-filter

- **Vertaalbestanden** (`languages/`):
  - POT template + 7 locales: nl_NL, nl_BE, en_US, en_GB, de_DE, fr_FR, fr_BE

- **Nieuwe admin-instellingen**:
  - `purge_stale_products` — verwijderde producten opruimen (checkbox, standaard uit)
  - `show_delete_warning` — waarschuwingsbanner tonen (checkbox, standaard aan)
  - `collection_ids` — collectie-filter (tekstveld)
  - `sync_categories` — categorieën syncen (checkbox)
  - `include_languages` — checkboxes + vrij tekstveld voor taalcodes
  - `image_language` — dropdown met "Anders..."-optie

### Gewijzigd

- **Sync resultaten uitgebreid**:
  - `run_sync()` retourneert nu ook `trashed` en `categories_removed`
  - UI: rij "Verwijderd (prullenbak)" en "Categorieën opgeruimd" in resultaten
  - Sync-geschiedenis tabel uitgebreid met kolom "Verwijderd"

- **`_skwirrel_synced_at` op variaties**:
  - `upsert_product_as_variation()` zet nu ook `_skwirrel_synced_at` meta (nodig voor stale-detectie)

- **Action Scheduler** (`class-action-scheduler.php`):
  - `run_scheduled_sync()` controleert `skwirrel_wc_sync_force_full_sync` option
  - Indien true: verwijdert optie en draait volledige sync

- **Bootstrap** (`skwirrel-woocommerce-sync.php`):
  - WooCommerce dependency check bij activatie met installatie-link
  - Verbeterde "WooCommerce ontbreekt"-notice
  - `load_plugin_textdomain()` + `Domain Path: /languages`
  - `Requires Plugins: woocommerce` header
  - Delete Protection class geladen en geregistreerd

- **Admin CSS** (`assets/admin.css`):
  - Styling voor `.skwirrel-sync-delete-warning` banner

- **Versie 1.1.1**:
  - Plugin version header en `SKWIRREL_WC_SYNC_VERSION` constant
  - `WC tested up to: 10.5` (compatibel met WooCommerce 10.5.1)
  - HPOS `declare_compatibility` naar `true` (plugin raakt geen orders)

### Developer Experience

- `.editorconfig` — consistente code-opmaak (tabs, LF, utf-8)
- `phpstan.neon.dist` — PHPStan level 6 met WP/WC stubs
- `.phpcs.xml.dist` — WordPress-Extra code style met text-domain check
- `phpunit.xml.dist` + `tests/` — Pest PHP met Unit tests voor ProductMapper
- `composer.json` — dev dependencies (pest, phpstan, phpcs, stubs), scripts
- `.claude/rules/` — progressive disclosure documentatie (sync-service, product-mapping, admin-settings, testing)
- `.claude/settings.json` — tool permissions
- `.claude/commands/` — workflow automatisering (sync-debug, add-setting, add-translation)
- `CLAUDE.md` — bijgewerkt met ontbrekende classes, settings, meta keys

---

## [1.0.0] — Initiële release

- Productsynchronisatie van Skwirrel JSON-RPC API naar WooCommerce
- Handmatige en automatische sync (WP-Cron / Action Scheduler)
- Delta sync op basis van `updated_on` timestamp
- Upsert-logica op SKU of external ID
- Afbeeldingen en documenten importeren naar WP media library
- Variable producten via `getGroupedProducts` met ETIM variatie-assen
- Product documents tab op frontend
- Variation attributes fix voor WooCommerce bugs
