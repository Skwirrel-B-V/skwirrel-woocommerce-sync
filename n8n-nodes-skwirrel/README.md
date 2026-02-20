# n8n-nodes-skwirrel

n8n community node voor het **Skwirrel ERP/PIM systeem**. Volledige toegang tot de Skwirrel JSON-RPC 2.0 API direct vanuit je n8n workflows.

## Installatie

### In n8n (community node)

1. Ga naar **Settings → Community Nodes**
2. Klik op **Install a community node**
3. Voer in: `n8n-nodes-skwirrel`
4. Klik op **Install**

### Handmatig (development)

```bash
cd n8n-nodes-skwirrel
npm install
npm run build

# Link naar n8n
cd ~/.n8n/custom
npm link /pad/naar/n8n-nodes-skwirrel
```

## Credentials

| Veld | Beschrijving |
|------|-------------|
| **JSON-RPC Endpoint URL** | `https://xxx.skwirrel.eu/jsonrpc` |
| **Authenticatie methode** | Bearer Token of Static Token (X-Skwirrel-Api-Token) |
| **API Token** | Je Skwirrel API token |
| **Timeout** | HTTP timeout in seconden (standaard: 30) |

## Resources & Operaties

### Product

| Operatie | API methode | Beschrijving |
|----------|-------------|-------------|
| **Ophalen** | `getProducts` | Alle producten ophalen (gepagineerd) |
| **Ophalen (filter)** | `getProductsByFilter` | Producten gewijzigd na een datum (delta sync) |

**Include opties** — per request aan/uit te zetten:

| Optie | API parameter | Data |
|-------|--------------|------|
| Productstatus | `include_product_status` | `_product_status.product_status_description` |
| Vertalingen | `include_product_translations` | `_product_translations[]` met `product_model`, `product_description`, `product_long_description`, `product_marketing_text`, `product_web_text` |
| Bijlagen | `include_attachments` | `_attachments[]` — afbeeldingen (IMG, PPI, PHI, LOG, SCH, PRT, OTV) en documenten (MAN, DAT, CER, WAR) met `source_url`, `product_attachment_order`, vertalingen |
| Handelsartikelen | `include_trade_items` | `_trade_items[]` |
| Prijzen | `include_trade_item_prices` | `_trade_item_prices[]` met `net_price`, `price_on_request` |
| Categorieën | `include_categories` | `_categories[]` met `category_id`, `category_name`, `parent_category_id`, `_category_translations[]`, `_parent_category` |
| Productgroepen | `include_product_groups` | `_product_groups[]` met `product_group_id`, `product_group_name`, geneste `_etim` |
| Gegroepeerde producten | `include_grouped_products` | Grouped product referenties |
| ETIM kenmerken | `include_etim` | `_etim[]._etim_features[]` met types A(lphanumeriek), L(ogisch), N(umeriek), R(ange), C(lass), M(odelling) |
| ETIM vertalingen | `include_etim_translations` | Feature en waarde vertalingen per taal |
| Talen | `include_languages` | Comma-separated: `nl-NL,nl,en,de` |
| Contexten | `include_contexts` | Comma-separated context IDs |

**Filter opties** (bij Ophalen (filter)):

| Veld | Beschrijving |
|------|-------------|
| Gewijzigd sinds | ISO 8601 datum/tijd |
| Operator | `>=`, `>`, `<=`, `<`, `==` |

### Grouped Product

| Operatie | API methode | Beschrijving |
|----------|-------------|-------------|
| **Ophalen** | `getGroupedProducts` | Gegroepeerde/variabele producten met ETIM variatie-assen |

**Include opties:**

| Optie | Beschrijving |
|-------|-------------|
| Producten in groep | `_products[]` met `product_id`, `internal_product_code`, `order` |
| ETIM features | `_etim_features[]` per groep voor variatie-attributen |

### Verbinding

| Operatie | Beschrijving |
|----------|-------------|
| **Testen** | Test of de Skwirrel API bereikbaar en geauthenticeerd is |

### Custom API Call

| Operatie | Beschrijving |
|----------|-------------|
| **JSON-RPC Call** | Voer een willekeurige JSON-RPC methode uit met custom parameters |

Gebruik dit voor API methoden die (nog) niet als resource beschikbaar zijn. Vul de methode naam en een JSON parameters object in.

## Gemeenschappelijke opties

| Optie | Beschrijving |
|-------|-------------|
| **Alle pagina's ophalen** | Automatische paginatie — haalt alle resultaten op |
| **Collectie IDs** | Comma-separated filter (leeg = alles) |
| **Output modus** | *Afzonderlijke items* (1 item per product) of *Volledige API response* (inclusief paginatie-info) |

## Voorbeelden

### Alle producten exporteren naar spreadsheet

```
[Schedule Trigger] → [Skwirrel: Product → Ophalen]
                      returnAll = true
                      includes: categorieën, ETIM, prijzen
                   → [Spreadsheet File]
```

### Delta sync — alleen recente wijzigingen

```
[Schedule Trigger] → [Skwirrel: Product → Ophalen (filter)]
                      updatedSince = {{$now.minus(1, 'day').toISO()}}
                   → [IF] → [verder verwerken]
```

### Grouped products voor variabele producten

```
[Manual Trigger] → [Skwirrel: Grouped Product → Ophalen]
                    returnAll = true
                    includes: producten, ETIM features
                 → [Split In Batches]
                 → [Function: verwerk variaties]
```

### Custom API call

```
[Manual Trigger] → [Skwirrel: Custom API Call]
                    methode = "getProducts"
                    params = {"page": 1, "limit": 5, "include_attachments": true}
                 → [Set: verwerk response]
```

## API Protocol

Alle communicatie gaat via **JSON-RPC 2.0**.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "getProducts",
  "params": { "page": 1, "limit": 100, ... },
  "id": 1
}
```

**Headers:**
- `Content-Type: application/json`
- `X-Skwirrel-Api-Version: 2`
- `Authorization: Bearer {token}` of `X-Skwirrel-Api-Token: {token}`

**Response:**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "products": [...],
    "page": { "current_page": 1, "number_of_pages": 5 }
  },
  "id": 1
}
```

## Publiceren

```bash
cd n8n-nodes-skwirrel
npm publish
```

Vereisten voor npm publish:
- npm account
- `npm login` uitgevoerd
- Package naam `n8n-nodes-skwirrel` beschikbaar op npm

Na publicatie verschijnt de node automatisch in de n8n community nodes directory.

## Licentie

GPL-2.0-or-later
