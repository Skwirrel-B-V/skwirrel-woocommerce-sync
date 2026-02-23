Voeg een nieuwe instelling toe aan de Skwirrel PIM Sync plugin.

Vraag de gebruiker om:
- Instelling key (snake_case, bijv. `my_new_setting`)
- Type (checkbox/text/select/number)
- Standaardwaarde
- Nederlandse label-tekst
- Beschrijving

Wijzig dan de volgende bestanden:
1. `includes/class-admin-settings.php`:
   - Voeg sanitization toe in `sanitize_settings()` (gebruik passend type: absint, sanitize_text_field, etc.)
   - Voeg het formulierveld toe in `render_settings_page()` op de juiste plek
2. `CLAUDE.md`: voeg de setting toe aan de Settings Keys tabel
3. `.claude/rules/admin-settings.md`: voeg de setting toe aan de All Settings Keys tabel
4. Indien relevant voor sync: pas `includes/class-sync-service.php` aan om de setting te lezen

Gebruik altijd:
- Text domain: `skwirrel-pim-wp-sync`
- Nederlandse UI tekst
- WordPress sanitization functies
