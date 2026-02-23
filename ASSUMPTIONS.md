# Assumpties Skwirrel PIM Sync

Dit document beschrijft aannames die gemaakt zijn waar de context (./context) geen duidelijke specificatie biedt.

## Schema referentie
- **Bron**: `Context/getProducts.json` (ProductExporterSchema-v12)
- **Productstructuur**: Alle veldnamen en -types komen uit dit schema. Voor ETIM: `product._etim[]` met `_etim_features[]`. Voor attachments: `product._attachments[]` met `source_url`, `file_source_url`, `_attachment_translations`.

## Authenticatie
- **Context**: Skwirrel ondersteunt Bearer token én X-Skwirrel-Api-Token (static token).
- **Implementatie**: Plugin ondersteunt beide. Dropdown: "Bearer token" | "API static token".

## API Endpoint
- **Context**: POST naar `/jsonrpc` op host `[ENVIRONMENT].skwirrel.eu`.
- **Assumptie**: Gebruiker voert volledige URL in (bijv. `https://dev01.dev.skwirrel.eu/jsonrpc`).

## Unieke sleutel voor productmatching
- **Context**: `external_product_id` (1-32 chars), `internal_product_code` (max 50), `manufacturer_product_code`.
- **Assumptie**: 
  - **Postmeta `_skwirrel_external_id`**: `external_product_id` als primair; fallback naar `internal_product_code` indien external_product_id leeg.
  - **WooCommerce SKU**: `internal_product_code` (primaire handelscode).

## Naam / Omschrijving mapping
- **Naam**: `product_erp_description` als primair; fallback naar eerste `_product_translations[].product_model` of `product_description`.
- **Korte omschrijving**: `product_description` uit translations (taal = site locale).
- **Lange omschrijving**: `product_long_description` of `product_marketing_text` of `product_web_text`.

## Prijs mapping
- **Context**: `_trade_item_prices[]` met `gross_price`, `net_price`, `suggested_price`.
- **Assumptie**: 
  - **Regular price**: `net_price` (excl. BTW) van eerste trade_item met geldige prijs.
  - **Sale price**: Niet beschikbaar in Skwirrel; eventueel via `_trade_item_discounts` indien geïmplementeerd.
  - **price_on_request** = true → product "Op aanvraag" (0 prijs, niet koopbaar).

## Afbeeldingen vs bestanden
- **Context**: `_attachments[]` met `product_attachment_type_code` (3 chars), `source_url`, `file_source_url`.
- **Assumptie**: 
  - **IMGCategory** types (IMG) → product afbeeldingen (featured + gallery).
  - **MAN** (handleidingen), **DAT** (datasheets) etc. → product attachments.
- **Motiveer keuze bestanden**: PDFs/datasheets worden als **downloadable product files** toegevoegd (WooCommerce native downloads). Dit past bij B2B/productinformatie.

## Delta sync
- **Context**: `getProductsByFilter` ondersteunt `filter.updated_on` met `datetime` en `operator` (>=, >, etc.).
- **Assumptie**: Bij delta sync: `updated_on >= last_sync_timestamp`. Anders volledige sync via `getProducts` met paginering.

## Categorieën
- **Context**: `_product_groups`, `include_categories` geeft `_categories`.
- **Assumptie**: Categorieën uit `_categories` of `_product_groups` (product_group_name) → WooCommerce product_cat. Automatisch aanmaken indien setting aan staat.

## Grouped products en varianten
- **Context**: `getGroupedProducts` met `include_products`.
- **API response**: `grouped_products[]` met `grouped_product_id`, `grouped_product_code`, `_products[]`.
- **Sync volgorde**: 1) getGroupedProducts – maak variable producten aan (leeg). 2) getProducts – voor elk product: zit het in een group? Dan voeg toe als variation. Anders: simple product.

## Merken
- **Context**: `brand_name`, `manufacturer_name`.
- **Assumptie**: `brand_name` → WooCommerce product attribute "pa_brand" of custom taxonomy als merk plugin actief. Anders postmeta `_skwirrel_brand`.
