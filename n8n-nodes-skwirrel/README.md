# n8n-nodes-skwirrel-wc-sync

n8n community node voor **Skwirrel WooCommerce Sync** — synchroniseer producten van het Skwirrel ERP/PIM systeem naar WooCommerce, direct vanuit je n8n workflows.

## Installatie

### In n8n (community node)

1. Ga naar **Settings → Community Nodes**
2. Klik op **Install a community node**
3. Voer in: `n8n-nodes-skwirrel-wc-sync`
4. Klik op **Install**

### Handmatig

```bash
cd ~/.n8n/custom
npm install /pad/naar/n8n-nodes-skwirrel
```

## Vereisten

- WordPress site met de [Skwirrel WooCommerce Sync](https://github.com/Skwirrel-B-V/skwirrel-woocommerce-sync) plugin (v1.2+)
- REST API key gegenereerd in **WooCommerce → Skwirrel Sync → REST API** sectie

## Configuratie

### Credentials aanmaken in n8n

1. Ga naar **Credentials → New**
2. Zoek op **Skwirrel WC Sync API**
3. Vul in:
   - **WordPress Site URL**: `https://jouw-webshop.nl`
   - **Authenticatie methode**: REST API Key (aanbevolen) of WordPress Application Password
   - **REST API Key**: De key uit de plugin instellingen

### WordPress REST API Key genereren

1. Ga in WordPress naar **WooCommerce → Skwirrel Sync**
2. Scroll naar de sectie **REST API (n8n / externe integraties)**
3. Klik op **API key genereren**
4. Kopieer de key (wordt slechts éénmaal getoond)

## Beschikbare operaties

### Sync

| Operatie | Beschrijving |
|----------|-------------|
| **Starten** | Start een volledige of delta synchronisatie |
| **Status** | Controleer of er een sync actief is |
| **Laatste resultaat** | Haal het resultaat van de laatste sync op |
| **Geschiedenis** | Bekijk de sync geschiedenis (max 20 items) |

### Verbinding

| Operatie | Beschrijving |
|----------|-------------|
| **Testen** | Test de verbinding met de Skwirrel API |

### Producten

| Operatie | Beschrijving |
|----------|-------------|
| **Ophalen** | Haal gesynchroniseerde producten op (paginatie) |

### Instellingen

| Operatie | Beschrijving |
|----------|-------------|
| **Ophalen** | Haal de huidige plugin instellingen op |

## Voorbeelden

### Dagelijks sync rapport via e-mail

1. **Schedule Trigger** → elke dag om 08:00
2. **Skwirrel WC Sync** → Sync: Starten (Volledig)
3. **IF** → Check `success === true`
4. **Gmail** → Stuur rapport met created/updated/failed aantallen

### Sync na webhook van Skwirrel

1. **Webhook** → Ontvang notificatie van Skwirrel
2. **Skwirrel WC Sync** → Sync: Starten (Delta)
3. **Skwirrel WC Sync** → Sync: Laatste resultaat
4. **Slack** → Post resultaat in #webshop kanaal

## Ontwikkeling

```bash
cd n8n-nodes-skwirrel
npm install
npm run build
```

Kopieer of link de `dist/` directory naar `~/.n8n/custom/node_modules/n8n-nodes-skwirrel-wc-sync/`.

## REST API Endpoints

De WordPress plugin registreert de volgende REST API endpoints:

| Methode | Endpoint | Beschrijving |
|---------|----------|-------------|
| `POST` | `/wp-json/skwirrel-wc-sync/v1/sync` | Start sync (body: `{mode: "full"\|"delta"}`) |
| `GET` | `/wp-json/skwirrel-wc-sync/v1/sync/status` | Sync status |
| `GET` | `/wp-json/skwirrel-wc-sync/v1/sync/last-result` | Laatste resultaat |
| `GET` | `/wp-json/skwirrel-wc-sync/v1/sync/history` | Geschiedenis |
| `POST` | `/wp-json/skwirrel-wc-sync/v1/connection/test` | Test verbinding |
| `GET` | `/wp-json/skwirrel-wc-sync/v1/settings` | Instellingen |
| `GET` | `/wp-json/skwirrel-wc-sync/v1/products` | Producten |

Authenticatie via `X-Skwirrel-Rest-Key` header of WordPress Basic Auth.

## Licentie

GPL-2.0-or-later
