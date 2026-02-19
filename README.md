# Skwirrel WooCommerce Sync

WordPress-plugin die producten synchroniseert van de Skwirrel JSON-RPC API naar WooCommerce.

## Vereisten

- WordPress 6.x
- WooCommerce 8+
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

## Hoe sync werkt

1. **Handmatig**: Klik op **Sync nu** op de instellingenpagina. De sync draait op de achtergrond via een asynchrone HTTP-request om timeouts te voorkomen. De pagina herlaadt direct; vernieuw de pagina om het resultaat te zien zodra de sync klaar is.
2. **Automatisch**: Stel een sync interval in; de plugin gebruikt WP-Cron of Action Scheduler (indien beschikbaar).
3. **Upsert-logica**: Bestaande producten (op basis van SKU of external ID) worden bijgewerkt; nieuwe producten worden aangemaakt.
4. **Delta sync**: Bij geplande sync wordt alleen gefilterd op producten die na de laatste sync zijn gewijzigd (`updated_on >= last_sync`).

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
| `_product_groups[].product_group_name` | Productcategorieën |

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

## Minimale testflow

1. Configureer endpoint en token
2. Klik **Test verbinding** → controleer of de test slaagt
3. Klik **Sync nu** → controleer de status weergave
4. Open de logs en verifieer dat er geen errors zijn
