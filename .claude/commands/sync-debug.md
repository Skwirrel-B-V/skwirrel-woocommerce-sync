Analyseer de laatste sync log van de Skwirrel PIM Sync plugin.

Stappen:
1. Zoek het meest recente logbestand met bron `skwirrel-pim-wp-sync` in `wp-content/uploads/wc-logs/` of `wp-content/wc-logs/`
2. Lees de laatste 200 regels van het logbestand
3. Identificeer:
   - Of de sync succesvol was (zoek naar "Sync voltooid" of "Sync aborted")
   - Hoeveel producten zijn aangemaakt, bijgewerkt en mislukt
   - Of er purge-acties zijn uitgevoerd
   - Eventuele ERROR of WARNING regels
4. Geef een samenvatting in het Nederlands met:
   - Status (geslaagd/mislukt)
   - Aantallen (created/updated/failed/trashed)
   - Top 5 fouten als die er zijn
   - Aanbevelingen als er problemen zijn gevonden
