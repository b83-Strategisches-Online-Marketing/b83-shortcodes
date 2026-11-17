---
description: Code-Review nach b83-Standards (Sicherheit zuerst)
---

Mach ein Review von: $ARGUMENTS (wenn leer: alle Änderungen seit dem letzten Commit, `git diff HEAD`).

Prüfe in dieser Reihenfolge:
1. Sicherheit: Nonces, Capabilities, Sanitizing, Escaping, `$wpdb->prepare()`, Secrets im Code.
2. Fatal-Error-Risiken: fehlende Guards, PHP-8.1-Kompatibilität, Abhängigkeit von Elementor/SEOPress ohne Prüfung.
3. Abwärtskompatibilität: geänderte Shortcodes, Optionen, Hooks.
4. b83-Standards aus `CLAUDE.md`: Menü unter „b83“, Text-Domain, Präfixe, Assets nur bei Bedarf.
5. Lesbarkeit und Struktur.

Ausgabe gegliedert nach **kritisch**, **sollte**, **wäre schön**, jeweils mit Datei und Zeile. Nichts ändern, bevor ich es freigebe.
