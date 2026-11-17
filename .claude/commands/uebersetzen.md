---
description: Übersetzbarkeit prüfen und Sprachdateien aktualisieren
---

1. Suche alle Texte in der Oberfläche, die nicht durch `__()`, `_e()`, `esc_html__()` usw. laufen, und liste sie auf.
2. Prüfe, dass überall die Text-Domain `{{PLUGIN_SLUG}}` verwendet wird.
3. Nach meiner Freigabe: Texte korrigieren und `.pot` neu erzeugen (`wp i18n make-pot . languages/{{PLUGIN_SLUG}}.pot`).
4. Deutsche Übersetzung (`de_DE`) ergänzen bzw. aktualisieren. Natürliches Deutsch, österreichische Begriffe sind ok (z. B. „Jänner“ nur, wenn ausdrücklich gewünscht).

Hinweise: $ARGUMENTS
