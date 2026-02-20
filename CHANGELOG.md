# Changelog — Skwirrel WooCommerce Sync

Alle wijzigingen in `feature\update` (1.1.0) ten opzichte van `main` (1.0.0).

---

## Nieuw bestand: `includes/class-delete-protection.php`

Verwijderbescherming voor Skwirrel-beheerde producten en categorieën.

### Waarschuwingsbanners
- **Product-bewerkpagina**: toont een gele waarschuwing met de tekst dat het product door Skwirrel wordt beheerd, inclusief datum van laatste synchronisatie.
- **Productlijst**: voegt een CSS-class toe aan de "Prullenbak"-link van Skwirrel-producten, met een JavaScript `confirm()`-dialoog die waarschuwt dat het product bij de volgende sync terugkomt.
- **Categorielijst**: zelfde bescherming voor categorieën met `_skwirrel_category_id` — bevestigingsdialoog bij verwijderen.

### Force full sync na verwijdering
- Wanneer een Skwirrel-product of -categorie in WooCommerce naar de prullenbak gaat of permanent wordt verwijderd, wordt de option `skwirrel_wc_sync_force_full_sync` op `true` gezet.
- De eerstvolgende geplande sync wordt daardoor automatisch een **volledige sync** (geen delta), zodat het verwijderde item opnieuw wordt aangemaakt.

### Instelling
- De banner is **standaard ingeschakeld** bij nieuwe installaties.
- Uit te schakelen via de checkbox "Verwijderwaarschuwing tonen" in de plugin-instellingen — bedoeld voor kleine bedrijven die de waarschuwing niet nodig hebben.

---

## Gewijzigd bestand: `includes/class-sync-service.php`

### Purge-logica voor verwijderde producten
- Na een **volledige sync** (niet delta, zonder collectie-filter) worden producten die niet meer in Skwirrel voorkomen automatisch naar de prullenbak verplaatst.
- Detectie op basis van `_skwirrel_synced_at` timestamp: producten waarvan de timestamp ouder is dan het starttijdstip van de sync zijn "stale".
- **SQL veiligheid**: `REGEXP '^[0-9]+$'` validatie vóór `CAST(... AS UNSIGNED)` om corrupte meta-waarden niet onterecht als stale te markeren.
- **Variable producten**: wanneer een variable product (grouped product) wordt getrashed, worden alle bijbehorende variaties automatisch mee-getrashed.
- **Pre-purge logging**: vóór het trashen wordt een samenvatting gelogd met het aantal en de eerste 20 product-IDs.
- Opt-in via instelling `purge_stale_products` (standaard **uit**).

### Purge-logica voor verwijderde categorieën
- Categorieën met `_skwirrel_category_id` die tijdens de sync niet meer zijn gezien, worden verwijderd.
- **Veiligheidscheck**: categorieën met nog gekoppelde (niet-getrasht) producten worden **niet** verwijderd. In plaats daarvan wordt een warning gelogd.
- Alleen actief wanneer `sync_categories` is ingeschakeld.

### Category tracking
- Nieuw class property `$seen_category_ids`: tijdens de sync worden alle Skwirrel-categorie-IDs bijgehouden via `find_or_create_category_term()`.

### `_skwirrel_synced_at` op variaties
- De `upsert_product_as_variation()` methode zet nu ook `_skwirrel_synced_at` meta op variaties (voorheen alleen op simple en variable producten). Dit is nodig voor correcte stale-detectie.

### Purge-skip logging
- Bij **delta sync**: verbose log "Purge overgeslagen: delta sync".
- Bij **collectie-filter actief**: warning log met de actieve collectie-IDs, zodat beheerders weten waarom purge niet draait.

### Sync resultaten uitgebreid
- `update_last_result()` accepteert nu ook `$trashed` en `$categories_removed` parameters.
- `run_sync()` retourneert nu ook `trashed` en `categories_removed` in het result-array.

### Categorieën: volledige sync-ondersteuning
- `get_categories()` vervangt `get_category_names()` met gestructureerde data (id, name, parent_id, parent_name).
- Primaire bron: `$product['_categories']` (via `include_categories` API flag).
- Fallback: `$product['_product_groups']` (legacy).
- Parent-child hiërarchie wordt ondersteund via `_parent_category`.
- Deduplicatie op basis van lowercase naam.
- `CATEGORY_ID_META` (`_skwirrel_category_id`) is nu een `public const` op de mapper.

### Category sync in sync service
- `assign_categories()`: matcht categorieën eerst op Skwirrel-ID (term meta), dan op naam, of maakt nieuwe termen aan.
- `find_or_create_category_term()`: ondersteunt parent-resolutie en slaat `_skwirrel_category_id` op in term meta.

### Collection ID filter
- Nieuw: `collection_ids` instelling (comma-separated) — alleen producten uit deze collecties worden gesynchroniseerd.
- Leeg = alles synchroniseren.
- Doorgegeven aan zowel `getProducts` als `getGroupedProducts` API-calls.

---

## Gewijzigd bestand: `includes/class-admin-settings.php`

### Nieuwe instellingen
| Instelling | Type | Standaard | Beschrijving |
|------------|------|-----------|--------------|
| `purge_stale_products` | checkbox | uit | Producten/categorieën die niet meer in Skwirrel staan naar prullenbak bij volledige sync |
| `show_delete_warning` | checkbox | aan | Waarschuwingsbanner tonen bij verwijderen van Skwirrel-items in WC |
| `collection_ids` | text | leeg | Comma-separated collectie-IDs om sync te filteren |
| `sync_categories` | checkbox | aan | Categorieën uit product_groups/categories aanmaken en koppelen |

### Sync resultaten UI
- Nieuwe rij **"Verwijderd (prullenbak)"** met gele achtergrond in de sync-resultaten tabel.
- Sub-rij **"Categorieën opgeruimd"** wanneer categorieën zijn verwijderd.
- Sync-geschiedenis tabel uitgebreid met kolom **"Verwijderd"**.

### Overige UI-verbeteringen
- `include_languages`: checkboxes voor bekende taalcodes + vrij tekstveld voor extra codes.
- `image_language`: dropdown met bekende talen + "Anders..."-optie.

---

## Gewijzigd bestand: `includes/class-action-scheduler.php`

### Force full sync
- `run_scheduled_sync()` controleert nu de option `skwirrel_wc_sync_force_full_sync`.
- Indien `true`: de optie wordt verwijderd en de sync draait als **volledige sync** (niet delta).
- Dit zorgt ervoor dat na het verwijderen van een Skwirrel-product in WC, het bij de eerstvolgende geplande sync automatisch wordt hersteld.

---

## Gewijzigd bestand: `skwirrel-woocommerce-sync.php`

### Delete Protection
- `class-delete-protection.php` wordt geladen en `Skwirrel_WC_Sync_Delete_Protection::instance()` wordt geregistreerd in hooks.

### WooCommerce dependency check
- Activatiecheck: als WooCommerce niet actief is bij plugin-activatie, wordt de plugin gedeactiveerd met een foutmelding inclusief installatie-link.
- Verbeterde "WooCommerce ontbreekt"-notice met links naar installatie- en activeringspagina.

### i18n
- `load_plugin_textdomain()` aangeroepen met `Domain Path: /languages`.
- `Requires Plugins: woocommerce` header toegevoegd.

---

## Gewijzigd bestand: `includes/class-product-mapper.php`

### `get_categories()` (nieuw)
- Vervangt de simpele `get_category_names()` met een gestructureerde methode die volledige categorie-data retourneert.
- Ondersteunt `_categories` (primair) en `_product_groups` (fallback).
- Ondersteunt parent-child hiërarchie en vertalingen.
- `CATEGORY_ID_META` is nu `public const`.

---

## Gewijzigd bestand: `assets/admin.css`

- Styling voor `.skwirrel-sync-delete-warning` banner (gele border, spacing, muted tekst voor timestamps).

---

## Nieuw: `languages/`

Vertaalbestanden voor 7 locales:

| Bestand | Taal |
|---------|------|
| `skwirrel-wc-sync.pot` | Template (bron) |
| `skwirrel-wc-sync-nl_NL.po/mo` | Nederlands (Nederland) |
| `skwirrel-wc-sync-nl_BE.po/mo` | Nederlands (België) |
| `skwirrel-wc-sync-en_US.po/mo` | English (US) |
| `skwirrel-wc-sync-en_GB.po/mo` | English (GB) |
| `skwirrel-wc-sync-de_DE.po/mo` | Deutsch (Deutschland) |
| `skwirrel-wc-sync-fr_FR.po/mo` | Français (France) |
| `skwirrel-wc-sync-fr_BE.po/mo` | Français (Belgique) |

---

## Overige bestanden

| Bestand | Wijziging |
|---------|-----------|
| `.gitignore` | Nieuw: standaard ignores voor WordPress-ontwikkeling |
| `composer.json` | Nieuw: project metadata en PHP 8.1+ requirement |
