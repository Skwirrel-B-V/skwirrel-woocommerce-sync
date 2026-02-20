# Skwirrel WooCommerce Sync

**Versie 1.1.1** — WordPress-plugin die producten synchroniseert van de Skwirrel JSON-RPC API naar WooCommerce.

## Vereisten

- WordPress 6.x
- WooCommerce 8+ (getest t/m 10.5)
- PHP 8.1+

## Installatie

1. Kopieer de map `skwirrel-woocommerce-sync` naar `wp-content/plugins/`
2. Activeer de plugin in **Plugins** → **Geïnstalleerde plugins**
3. Ga naar **WooCommerce** → **Skwirrel Sync** en configureer de instellingen

## Instellingen

| Veld | Beschrijving |
|------|--------------|
| **JSON-RPC Endpoint URL** | Volledige URL naar het Skwirrel endpoint (bijv. `https://xxx.skwirrel.eu/jsonrpc`) |
| **Authenticatie type** | Bearer token of API static token (X-Skwirrel-Api-Token) |
| **Token** | Het authenticatietoken. Na opslaan wordt dit gemaskeerd weergegeven. |
| **Timeout** | Request timeout in seconden (5–120) |
| **Aantal retries** | Aantal herhaalde pogingen bij fout (0–5) |
| **Sync interval** | Uitgeschakeld, elk uur, twee keer per dag, dagelijks of wekelijks |
| **Batch size** | Producten per API-pagina (10–500) |
| **Categorieën syncen** | Productcategorieën uit Skwirrel aanmaken en koppelen |
| **Afbeeldingen importeren** | Ja (naar media library) of Nee (overslaan). Kies "Nee" als upload faalt door een security plugin. |
| **SKU veld** | `internal_product_code` of `manufacturer_product_code` |
| **Collectie-IDs** | Comma-separated collectie-IDs om alleen specifieke collecties te synchroniseren. Leeg = alles. |
| **Verwijderde producten opruimen** | Na een volledige sync: producten die niet meer in Skwirrel staan naar de prullenbak verplaatsen. Standaard **uit**. |
| **Verwijderwaarschuwing tonen** | Toon een waarschuwingsbanner bij het verwijderen van Skwirrel-beheerde items in WooCommerce. Standaard **aan**. |
| **Talen** | Welke taalcodes meegestuurd worden in de API-call + voorkeurstaal voor afbeeldingstitels. |

## Hoe sync werkt

1. **Handmatig**: Klik op **Sync nu** op de instellingenpagina. De sync draait op de achtergrond via een asynchrone HTTP-request om timeouts te voorkomen. De pagina herlaadt direct; vernieuw de pagina om het resultaat te zien zodra de sync klaar is.
2. **Automatisch**: Stel een sync interval in; de plugin gebruikt WP-Cron of Action Scheduler (indien beschikbaar).
3. **Upsert-logica**: Bestaande producten (op basis van SKU of external ID) worden bijgewerkt; nieuwe producten worden aangemaakt.
4. **Delta sync**: Bij geplande sync wordt alleen gefilterd op producten die na de laatste sync zijn gewijzigd (`updated_on >= last_sync`).
5. **Purge**: Na een volledige sync (indien ingeschakeld) worden producten en categorieën die niet meer in Skwirrel voorkomen automatisch naar de prullenbak verplaatst.

## Verwijderbescherming

Skwirrel is leidend: producten die via Skwirrel worden beheerd worden bij de volgende sync opnieuw aangemaakt als ze in WooCommerce zijn verwijderd.

- **Waarschuwingsbanner**: op de product-bewerkpagina wordt een gele banner getoond met de tekst dat het product door Skwirrel wordt beheerd.
- **Bevestigingsdialoog**: bij het verwijderen van een Skwirrel-product of -categorie in de lijst verschijnt een JavaScript-bevestiging.
- **Automatische volledige sync**: wanneer een Skwirrel-item in WooCommerce wordt verwijderd, wordt de eerstvolgende geplande sync automatisch een volledige sync.

De waarschuwing is uit te schakelen via de instelling "Verwijderwaarschuwing tonen".

## Gemapte velden

| Skwirrel | WooCommerce |
|----------|-------------|
| `internal_product_code` / `manufacturer_product_code` | SKU |
| `external_product_id` | Postmeta `_skwirrel_external_id` |
| `product_erp_description` | Productnaam |
| `_product_translations[].product_description` | Korte omschrijving |
| `_product_translations[].product_long_description` | Lange omschrijving |
| `_trade_item_prices[].net_price` | Regular price (eerste trade item) |
| `getGroupedProducts` (optioneel) | Variable producten; producten in _products worden variations |
| `_attachments` (type IMG) | Featured image + galerij |
| `_attachments` (type MAN, DAT, etc.) | Downloadbare bestanden |
| `brand_name`, `manufacturer_name` | Productattributen |
| `_categories[]` / `_product_groups[]` | Productcategorieën (met parent-child hiërarchie) |

## Troubleshooting

### Verbinding test mislukt
- Controleer of de endpoint-URL correct is (inclusief `/jsonrpc`)
- Controleer of het token geldig is en niet verlopen
- Controleer of de server uitgaande HTTPS-verbindingen toestaat

### Sync loopt vast of timeout
- De sync draait op de achtergrond; er zou geen timeout meer moeten zijn op de pagina.
- Verlaag de batch size (bijv. 50) als de achtergrond-sync nog steeds problemen geeft.
- Verhoog de timeout (bijv. 60 seconden) in de instellingen.

### Sync start niet op de achtergrond
- Sommige hosts blokkeren HTTP-requests van de server naar zichzelf. Vraag dan aan je host of "loopback requests" zijn toegestaan.

### Geen producten gesynchroniseerd
- Controleer of `include_product_translations`, `include_attachments`, `include_trade_items` en `include_trade_item_prices` in de API-call worden meegestuurd (de plugin doet dit automatisch)
- Controleer de logs via **Bekijk logs** of WooCommerce → Status → Logs

### Duplicaten
- De plugin gebruikt `external_product_id` of `internal_product_code` als unieke sleutel. Zorg dat deze velden in Skwirrel correct zijn ingevuld.

## Logging

De plugin gebruikt de WooCommerce logger (`wc_get_logger`). Logs zijn te vinden in:
- **WooCommerce** → **Status** → **Logs** → bron: `skwirrel-wc-sync`

## Vertalingen

De plugin bevat vertalingen voor de volgende talen:

| Taal | Bestand |
|------|---------|
| Nederlands (Nederland) | `nl_NL` |
| Nederlands (België) | `nl_BE` |
| English (US) | `en_US` |
| English (GB) | `en_GB` |
| Deutsch | `de_DE` |
| Français (France) | `fr_FR` |
| Français (Belgique) | `fr_BE` |

## Ontwikkeling

### Vereisten

```bash
composer install
```

### Tests draaien

```bash
vendor/bin/phpunit
```

### Static analysis

```bash
vendor/bin/phpstan analyse
```

### Code style

```bash
vendor/bin/phpcs        # controleren
vendor/bin/phpcbf       # automatisch fixen
```

## Minimale testflow

1. Configureer endpoint en token
2. Klik **Test verbinding** → controleer of de test slaagt
3. Klik **Sync nu** → controleer de status weergave
4. Open de logs en verifieer dat er geen errors zijn
