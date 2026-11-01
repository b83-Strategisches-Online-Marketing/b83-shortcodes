![](assets/img/banner-772x250.png)

# b83 Shortcodes

WordPress-Plugin, um eigene Shortcodes mit HTML, CSS und JavaScript zu verwalten – zum Einbinden von Scripts, Widgets (Wetter, Buchung, Chat, Bewertungen …) und Bausteinen in Elementor, im Block-Editor oder in Widgets. PHP-Code wird nie ausgeführt.

Entwickelt von [b83 – Strategisches Online Marketing](https://b83.at), Wolfgang Grabs-Schrempf, office@b83.at.

- **Version:** 2.2.1
- **Voraussetzungen:** WordPress 6.2+, PHP 8.1+
- **Lizenz:** GPL-2.0-or-later

## Funktionen

- Beliebig viele Shortcodes mit Titel, interner Notiz und frei wählbarem Namen
- **Zwei Code-Felder je Shortcode**
  - *An der Stelle des Shortcodes:* HTML, `<style>`, `<script>` – bei jedem Vorkommen
  - *Am Seitenende:* wird nur auf Seiten mit dem Shortcode und **höchstens einmal pro Seite** vor `</body>` ausgegeben – ideal für Scripts, die einen Container weiter oben brauchen
- **Page-Builder-Platzhalter:** Auf Wunsch zeigt der Elementor-Editor (auch Beaver Builder, Divi, Bricks, Oxygen) nur einen Platzhalter statt das Script auszuführen. Besucher sehen immer die echte Ausgabe.
- **Aktiv/inaktiv:** Shortcodes abschalten, ohne sie von den Seiten zu entfernen (es erscheint dann auch kein Shortcode-Text)
- **Verwendet in:** zeigt, auf welchen Seiten, Beiträgen und Elementor-Vorlagen ein Shortcode steht
- **Vorherige Fassung** des Codes wiederherstellen
- **Duplizieren**, Kopieren-Knopf, Code-Editor mit Syntaxhervorhebung (WordPress-Standard)
- **Export/Import als JSON**, um Shortcodes auf andere Websites zu übertragen
- **Cache:** Nach dem Speichern wird der Cache der betroffenen Seiten geleert (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround); steht der Shortcode in einer Vorlage (z. B. Elementor-Header/-Footer), der gesamte Cache

## Einbinden

| Wo | Wie |
| --- | --- |
| Elementor | Widget **„Shortcode“** (nicht „HTML“), Shortcode einfügen |
| Elementor Theme Builder | Shortcode-Widget in Header-/Footer-Vorlage → auf allen Seiten |
| Block-Editor | Block **„Shortcode“** |
| Classic Editor / Text-Widget | Shortcode direkt in den Text |

Weitere Shortcodes innerhalb des Codes funktionieren. Ruft sich ein Shortcode selbst auf, wird die Schleife abgefangen.

## Backend

*b83 → Shortcodes* im gemeinsamen b83-Menü. Die alte Adresse *Einstellungen → b83 Shortcodes* leitet automatisch weiter.

## Sicherheit

- Shortcodes enthalten ungefiltertes HTML/JavaScript. Ändern dürfen sie daher nur Benutzer mit **`manage_options` und `unfiltered_html`** – auf Multisite also nur Super-Admins; mit `DISALLOW_UNFILTERED_HTML` in der wp-config.php niemand (Administratoren sehen dann nur die Liste).
- Alle Aktionen sind per Nonce geschützt, Eingaben werden zentral geprüft (Größe je Code-Feld max. 256 KB, max. 300 Shortcodes).
- Namen, die WordPress oder ein anderes Plugin bereits verwendet (z. B. `gallery`, `caption`), werden abgelehnt, damit deren Funktion nicht überschrieben wird. Bestehende Kollisionen werden in der Liste markiert.
- Redakteure können Shortcodes nur einsetzen, nicht verändern. Attribute im Shortcode werden ignoriert.

## Umstieg von Version 1.x

Einfach aktualisieren – alle Shortcodes und Namen bleiben gleich. Fehlende interne IDs werden einmalig vergeben, neue Optionen haben Standardwerte, die dem bisherigen Verhalten entsprechen.

## Hooks für Erweiterungen

| Hook | Typ | Zweck |
| --- | --- | --- |
| `b83_shortcodes_loaded` | Action | Nach dem Laden aller Komponenten (`$plugin`) |
| `b83_shortcodes_output` | Filter | Ausgabe an der Stelle des Shortcodes (`$html, $entry, $atts`) |
| `b83_shortcodes_footer_output` | Filter | Code am Seitenende (`$html, $id`) |
| `b83_shortcodes_is_builder_preview` | Filter | Erkennung der Page-Builder-Vorschau (`$is`) |
| `b83_shortcodes_before_save` | Filter | Eintrag vor dem Speichern (`$entry, $old`) |
| `b83_shortcodes_saved` | Action | Nach dem Speichern (`$entry, $old`) |
| `b83_shortcodes_deleted` | Action | Nach dem Löschen (`$entry`) |
| `b83_shortcodes_can_manage` | Filter | Wer verwalten darf (`$allowed`) – Vorsicht: Wer verwaltet, kann JavaScript ausspielen |
| `b83_shortcodes_purge_post_ids` | Filter | Seiten, deren Cache geleert wird (`$ids, $tags`) |
| `b83_shortcodes_purge_all` | Filter | Gesamten Cache leeren (`$full, $tags`) |
| `b83_shortcodes_purge_cache` | Action | Nach dem Leeren des Caches (`$ids, $full`) |

## Daten

- `b83_shortcodes_entries` – Shortcodes (wie in 1.x)
- `b83_shortcodes_settings` – Einstellungen
- `b83_shortcodes_db_version` – Datenbank-Version

Beim Löschen des Plugins bleiben die Daten erhalten, außer *Import/Export & Einstellungen → Beim Löschen … entfernen* ist aktiviert.

## Updates

Das Plugin holt Updates aus den GitHub-Releases dieses Repositorys (`Update URI` im Header). Unter *Plugins* lässt sich „Automatische Updates aktivieren“ einschalten.

Neue Version veröffentlichen: Version in `b83-shortcodes.php` (Header und `B83_SC_VERSION`) erhöhen, Abschnitt im `CHANGELOG.md` ergänzen, auf `main` pushen – die GitHub Action erstellt das Release mit `b83-shortcodes.zip`.
