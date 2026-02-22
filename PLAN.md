# Implementatieplan — Verbeteringen v1.1.1

Elke stap = 1 commit. Volgorde is belangrijk (sommige stappen bouwen voort op eerdere).

---

## Commit 1: Versie-bump naar 1.1.1 + WooCommerce 10.5 compatibiliteit

**Bestanden:**
- `skwirrel-woocommerce-sync.php`:
  - Plugin header `Version: 1.1.1`
  - Constant `SKWIRREL_WC_SYNC_VERSION` → `'1.1.1'`
  - `WC tested up to: 8.5` → `WC tested up to: 10.5`
  - HPOS `declare_compatibility('custom_order_tables', __FILE__, false)` → `true` (plugin raakt geen orders)
- `composer.json`:
  - `"php-stubs/woocommerce-stubs": "^8.0"` → `"^10.0"`

---

## Commit 2: Voeg .editorconfig toe

**Nieuw bestand:** `.editorconfig`
- indent_style: tab, indent_size: 4 (WordPress standaard)
- end_of_line: lf, charset: utf-8
- trim_trailing_whitespace, insert_final_newline

---

## Commit 3: Update CLAUDE.md met ontbrekende documentatie

**Bestand:** `CLAUDE.md`

Toevoegen aan class-tabel:
- `Skwirrel_WC_Sync_Delete_Protection` | `includes/class-delete-protection.php` | Verwijderwaarschuwing + force full sync

Toevoegen aan dependency flow:
- `Delete_Protection (standalone)`

Toevoegen aan Post Meta Keys tabel:
- `_skwirrel_category_id` | Skwirrel category ID op WC term (term meta)

Toevoegen aan WP Options tabel:
- `skwirrel_wc_sync_force_full_sync` | Flag om volgende sync als volledige sync te draaien

Toevoegen aan Settings beschrijving (nieuwe subsectie):
- `purge_stale_products` — Verwijderde producten opruimen
- `sync_categories` — Categorieën syncen
- `collection_ids` — Collectie-filter
- `show_delete_warning` — Verwijderwaarschuwing

Sync Flow uitbreiden met purge-stap en category sync.

Development Notes: verwijder "No test suite currently exists", vervang door verwijzing naar `tests/`.

---

## Commit 4: Voeg .claude/rules/ toe (progressive disclosure)

**Nieuwe bestanden:**
- `.claude/rules/sync-service.md` — Sync flow details, purge-logica, delta vs full, collection filter
- `.claude/rules/product-mapping.md` — Meta keys, field mapping, ETIM, categorie-mapping
- `.claude/rules/admin-settings.md` — Alle instellingen, UI conventions, sanitization
- `.claude/rules/testing.md` — Hoe tests te draaien, PHPUnit setup, wat te testen

---

## Commit 5: Voeg .claude/settings.json toe (permissions)

**Nieuw bestand:** `.claude/settings.json`
- Fine-grained permissions met wildcards
- Bash permissions voor php, composer, phpunit, phpstan, phpcs

---

## Commit 6: Voeg PHPStan static analysis configuratie toe

**Bestanden:**
- `phpstan.neon.dist` — Level 6, met WP/WC stubs, scanDirs, excludePaths
- `composer.json` — Voeg `phpstan/phpstan` en `szepeviktor/phpstan-wordpress` toe aan require-dev
- `.gitignore` — Voeg `phpstan.neon` (lokale override) toe

---

## Commit 7: Voeg PHP_CodeSniffer configuratie toe

**Bestanden:**
- `.phpcs.xml.dist` — WordPress-Extra ruleset, text-domain check, minimum WP version
- `composer.json` — Voeg `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcsstandards/phpcsutils` toe aan require-dev

---

## Commit 8: Voeg PHPUnit test-framework en eerste tests toe

**Bestanden:**
- `phpunit.xml.dist` — PHPUnit 10 config, testsuite definitie
- `tests/bootstrap.php` — WP test bootstrap (of standalone met stubs)
- `tests/Unit/ProductMapperCategoryTest.php` — Tests voor `get_categories()`, `get_category_names()`, `pick_category_translation()`
- `tests/Unit/SyncServicePurgeTest.php` — Tests voor purge stale-detectie logica
- `composer.json` — Voeg `phpunit/phpunit` en `yoast/phpunit-polyfills` toe aan require-dev, voeg scripts sectie toe

---

## Commit 9: Voeg .claude/commands/ toe (workflow automatisering)

**Nieuwe bestanden:**
- `.claude/commands/sync-debug.md` — Prompt: analyseer laatste sync log
- `.claude/commands/add-setting.md` — Prompt: voeg nieuwe instelling toe aan admin-settings
- `.claude/commands/add-translation.md` — Prompt: voeg vertaalstring toe aan alle .po bestanden

---

## Commit 10: Update README.md

**Bestand:** `README.md`

Toevoegen/wijzigen:
- Versienummer 1.1.1
- Nieuwe instellingen tabel (purge, delete warning, collection filter, sync categories)
- Sectie "Verwijderbescherming" met uitleg
- Sectie "Ontwikkeling" met PHPUnit, PHPStan, PHPCS commando's
- Vertaalde talen tabel

---

## Commit 11: Herschrijf CHANGELOG.md als v1.1.1 release

**Bestand:** `CHANGELOG.md`

Herstructureer als versie-gebaseerde changelog:
- `## [1.1.1] — 2026-02-20` met alle bestaande wijzigingen + de tooling/DX verbeteringen
- `## [1.0.0]` — initiële release (kort)
