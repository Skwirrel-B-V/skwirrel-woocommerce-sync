Voeg een vertaalstring toe aan alle .po bestanden van de Skwirrel PIM Sync plugin.

Vraag de gebruiker om:
- De originele string (msgid, Nederlands)
- Context (optioneel, voor msgctxt)

Voeg de string dan toe aan:
1. `languages/skwirrel-pim-wp-sync.pot` (template)
2. Alle .po bestanden in `languages/`:
   - `nl_NL.po` en `nl_BE.po` — Nederlandse vertaling (vaak identiek aan msgid)
   - `en_US.po` en `en_GB.po` — Engelse vertaling
   - `de_DE.po` — Duitse vertaling
   - `fr_FR.po` en `fr_BE.po` — Franse vertaling

Formaat per entry:
```
#: bestand.php:regelnummer
msgid "Originele string"
msgstr "Vertaling"
```

Na het wijzigen van de .po bestanden: genereer nieuwe .mo bestanden met `msgfmt` als dat beschikbaar is.
