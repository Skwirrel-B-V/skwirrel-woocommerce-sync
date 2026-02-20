# n8n-nodes-skwirrel

n8n community node voor het **Skwirrel ERP/PIM systeem**. Haal producten, gegroepeerde producten en categorieën op via de Skwirrel JSON-RPC 2.0 API, direct vanuit je n8n workflows.

## Installatie

### In n8n (community node)

1. Ga naar **Settings → Community Nodes**
2. Klik op **Install a community node**
3. Voer in: `n8n-nodes-skwirrel`
4. Klik op **Install**

### Handmatig (development)

```bash
cd ~/.n8n/custom
npm install /pad/naar/n8n-nodes-skwirrel
```

## Vereisten

- Toegang tot een Skwirrel JSON-RPC endpoint (bijv. `https://xxx.skwirrel.eu/jsonrpc`)
- Een API token (Bearer of Static)

## Configuratie

### Credentials aanmaken in n8n

1. Ga naar **Credentials → New**
2. Zoek op **Skwirrel API**
3. Vul in:
   - **JSON-RPC Endpoint URL**: `https://xxx.skwirrel.eu/jsonrpc`
   - **Authenticatie methode**: Bearer Token of Static Token
   - **API Token**: je Skwirrel API token
   - **Timeout**: 30 seconden (standaard)

## Beschikbare resources en operaties

### Product

| Operatie | API methode | Beschrijving |
|----------|-------------|-------------|
| **Ophalen** | `getProducts` | Haal alle producten op (gepagineerd) |
| **Ophalen (filter)** | `getProductsByFilter` | Haal producten op gewijzigd na een datum |

Opties per request:
- Productstatus, vertalingen, bijlagen, handelsartikelen, prijzen
- Categorieën, productgroepen, gegroepeerde producten
- ETIM kenmerken en vertalingen
- Taalcodes filter (bijv. `nl-NL,nl,en`)
- Collectie IDs filter
- **Alle pagina's ophalen**: automatische paginatie

### Grouped Product

| Operatie | API methode | Beschrijving |
|----------|-------------|-------------|
| **Ophalen** | `getGroupedProducts` | Haal gegroepeerde/variabele producten op met ETIM features |

Opties:
- Producten meenemen
- ETIM features meenemen
- Collectie IDs filter
- Automatische paginatie

### Verbinding

| Operatie | Beschrijving |
|----------|-------------|
| **Testen** | Test of de Skwirrel API bereikbaar en geauthenticeerd is |

## Voorbeelden

### Producten ophalen en verwerken

```
[Schedule Trigger] → [Skwirrel: Product → Ophalen] → [Set] → [Spreadsheet File]
```

### Delta sync — alleen gewijzigde producten

```
[Schedule Trigger] → [Skwirrel: Product → Ophalen (filter)]
                      updatedSince = {{$now.minus(1, 'day').toISO()}}
                   → [IF: product heeft afbeeldingen?]
                   → [HTTP Request: download afbeeldingen]
```

### Grouped products ophalen voor variabele producten

```
[Manual Trigger] → [Skwirrel: Grouped Product → Ophalen]
                    returnAll = true
                 → [Split In Batches]
                 → [Function: verwerk ETIM features]
```

## API Details

### Skwirrel JSON-RPC 2.0

Alle communicatie gaat via JSON-RPC 2.0 naar het geconfigureerde endpoint.

**Headers:**
- `Content-Type: application/json`
- `X-Skwirrel-Api-Version: 2`
- `Authorization: Bearer {token}` (bearer auth) of `X-Skwirrel-Api-Token: {token}` (static auth)

**Methoden:**

| Methode | Beschrijving |
|---------|-------------|
| `getProducts` | Alle producten ophalen (gepagineerd, met include-opties) |
| `getProductsByFilter` | Producten filteren (bijv. `updated_on >= datum`) |
| `getGroupedProducts` | Gegroepeerde producten met ETIM variatie-assen |

**Response structuur:**
```json
{
  "products": [...],
  "page": {
    "current_page": 1,
    "number_of_pages": 5
  }
}
```

## Ontwikkeling

```bash
cd n8n-nodes-skwirrel
npm install
npm run build
```

Link voor lokaal testen:
```bash
cd ~/.n8n/custom
npm link /pad/naar/n8n-nodes-skwirrel
```

## Licentie

GPL-2.0-or-later
