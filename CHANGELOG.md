# Changelog

## 2.2.1 – 2026-11-01

### Behoben
- b83-Übersicht: Plugin-Icons erschienen als defekte Bilder
- Icons von Pro-Versionen fehlten im b83-Menü (b83-Menü Version 3)

## 2.2.0 – 2026-11-01

### Neu
- b83-Menü (Version 2): Übersicht und Menüpunkte zeigen das Icon jedes b83-Plugins

## 2.1.0 – 2026-11-01

### Neu
- Eigenes Icon und Banner im b83-Stil: erscheinen in der Update-Liste und unter „Details ansehen“
- „Details ansehen“ zeigt jetzt eine Beschreibung des Plugins

## 2.0.0 – 2026-10-16

Neuaufbau nach dem b83-Plugin-Standard. Bestehende Shortcodes, Namen und Daten bleiben unverändert nutzbar.

### Sicherheit
- Verwalten nur noch mit `manage_options` **und** `unfiltered_html` – bisher konnten z. B. Website-Administratoren auf Multisite oder bei `DISALLOW_UNFILTERED_HTML` JavaScript einschleusen
- Ein Shortcode, der sich selbst aufruft, führt nicht mehr zu einem kritischen Fehler (Endlosschleife) der ganzen Seite
- Namen von WordPress-/Plugin-Shortcodes (z. B. `gallery`, `caption`, `embed`) können nicht mehr versehentlich überschrieben werden; bestehende Kollisionen werden markiert
- Größenlimits für Code-Felder und Anzahl, alle Aktionen über `admin-post.php` mit eigener Nonce je Eintrag
- Einträge ohne gespeicherte ID (aus älteren Versionen) bekamen bei jedem Aufruf eine neue ID und ließen sich nicht bearbeiten oder löschen – wird jetzt einmalig dauerhaft korrigiert

### Neu
- Zweites Code-Feld „Am Seitenende“: Scripts nur auf Seiten mit dem Shortcode, höchstens einmal pro Seite vor `</body>`
- Platzhalter im Page-Builder-Editor (Elementor, Beaver Builder, Divi, Bricks, Oxygen) statt Script-Ausführung
- Shortcodes aktivieren/deaktivieren, duplizieren, vorherige Fassung wiederherstellen
- Interne Notiz je Shortcode
- „Verwendet in“: Seiten, Beiträge und Elementor-Vorlagen mit dem Shortcode
- Export/Import als JSON
- Code-Editor mit Syntaxhervorhebung, Kopieren-Knopf, Vorschau des Shortcode-Namens, Warnung bei ungespeicherten Änderungen
- Seiten-Cache wird nach dem Speichern geleert (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround)
- Updates über GitHub-Releases, automatische Updates möglich
- Hooks für Erweiterungen (siehe README)
- Option, beim Löschen des Plugins alle Daten zu entfernen (standardmäßig aus)

### Geändert
- Die Verwaltung liegt jetzt unter *b83 → Shortcodes* im gemeinsamen b83-Menü; die alte Adresse unter *Einstellungen* leitet weiter
- Fehlermeldung statt stiller Umbenennung, wenn ein selbst eingegebener Name schon vergeben ist

## 1.2.0

Letzte Version vor der Migration (ursprünglich mit einer anderen KI erstellt).
