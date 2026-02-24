# Changelog — Skwirrel PIM Sync

All notable changes to Skwirrel PIM Sync will be documented in this file.

## [1.1.0] - 2026-02-24

- No notable changes

## [1.0.2] - 2026-02-24

- Bump version to 1.2.3 in preparation for release. (0b7b730)
- chore: release 1.0.5 [skip ci] (f336e39)
- Bump version to 1.2.3 and add `phpcs:ignore` comments to address coding standard violations. (52c7502)
- chore: release 1.0.4 [skip ci] (88cfbf5)
- Bump version to 1.2.2 in preparation for release. (b68e9c8)
- Bump version to 1.2.2 in preparation for release. (5fb7ec0)
- chore: release 1.0.3 [skip ci] (c6c735a)
- Update text domain references to `skwirrel-pim-sync` in preparation for rebranding. (12f0989)
- chore: release 1.0.2 [skip ci] (0b9dc4f)
- Update text domain and constants to reflect rebranding to "Skwirrel PIM Sync", and bump version to 1.2.0. (4576f63)

## [1.0.5] - 2026-02-23

- Bump version to 1.2.3 and add `phpcs:ignore` comments to address coding standard violations. (52c7502)

## [1.2.3] - 2026-02-23

- WordPress Plugin Check compliance: fix translators comments, ordered placeholders, escape output
- WordPress Plugin Check compliance: add phpcs:ignore for direct DB queries, non-prefixed WooCommerce globals, and nonce verification
- WordPress Plugin Check compliance: use WordPress alternative functions (wp_parse_url, wp_delete_file, wp_is_writable)
- Translate readme.txt to English (WordPress.org requirement)

## [1.2.2] - 2026-02-23

- Update text domain references to `skwirrel-pim-sync` in preparation for rebranding. (12f0989)

## [1.2.1] - 2026-02-23

- Update text domain and constants to reflect rebranding to "Skwirrel PIM Sync", and bump version to 1.2.0. (4576f63)

## [1.2.0] - 2026-02-23

- Rename plugin to **Skwirrel PIM Sync** across all files and references. (58fc67f)
- Add extensive unit tests for MediaImporter, ProductMapper, and related components (9d7c985)
- Add composer files and vendor dir to .distignore (87f3be8)
- Add WordPress.org auto-deploy on main branch releases (ac8adfd)
- Add automated versioning, tagging, and release workflow (fecc155)
- Bump version to 1.1.2 (85c3240)
- Fix dubbele producten bij sync: 3-stap lookup chain + SKU conflict preventie (9bf11f7)
- Migreer test-framework van PHPUnit naar Pest PHP (270fa0d)
- Herschrijf CHANGELOG.md als versie-gebaseerde v1.1.1 release (a4b89b7)
- Update README.md voor v1.1.1 (2ef3785)
- Voeg .claude/commands/ toe voor workflow automatisering (37d293d)
- Voeg PHPUnit test-framework en eerste tests toe (9dfbbdf)
- Voeg PHP_CodeSniffer configuratie toe (6dd2405)
- Voeg PHPStan static analysis configuratie toe (7b11855)
- Voeg .claude/settings.json toe met tool permissions (b9855bf)
- Voeg .claude/rules/ toe voor progressive disclosure (cb6c263)
- Update CLAUDE.md met ontbrekende documentatie (a2b32fe)
- Voeg .editorconfig toe voor consistente code-opmaak (b238b4c)
- Versie-bump naar 1.1.1 + WooCommerce 10.5 compatibiliteit (ca9a916)
- Voeg implementatieplan toe voor v1.1.1 verbeteringen (98a0a89)
- chore: v bump (625516a)
- Voeg CHANGELOG.md toe met overzicht van alle wijzigingen t.o.v. main (bf6e718)
- Fix purge-logica: SQL veiligheid, categorie-bescherming, betere logging (038bf06)
- Implementeer data-purge sync en verwijderbescherming (Skwirrel is leidend) (851ae51)
- Add translation files for Skwirrel WooCommerce Sync (POT, de_DE, en_GB, en_US) (5b46b21)
- Add translation files for Skwirrel WooCommerce Sync (POT, de_DE, en_GB, en_US) (29c215d)
- Add category syncing and filters for more precise product synchronization (3667952)
- first commit (46f5cae)

## [1.1.2] - 2026-02-22

- Bump version to 1.1.2

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

- **Bootstrap** (`skwirrel-pim-wp-sync.php`):
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
