# b83 Plugin-Standard

Dieses Repository gehört zu den WordPress-Plugins von b83 – Strategisches Online Marketing (https://b83.at, Wolfgang Grabs-Schrempf, office@b83.at). Jede Änderung folgt diesem Standard.

## Kennzeichnung
- Slug, Ordner, Hauptdatei, Text Domain und Repository: `b83-<name>` (Kleinbuchstaben)
- Header: `Author: b83 – Wolfgang Grabs-Schrempf`, `Author URI: https://b83.at`, `Plugin URI: https://b83.at`, `License: GPL-2.0-or-later`, `Requires PHP: 8.1`, `Requires at least: 6.2`, `Update URI:` GitHub-URL des Repositorys
- Namespace `B83\<Name>`, Präfix für Optionen, Hooks, Capabilities, Nonces: `b83_<name>_` bzw. kurz `b83_<kürzel>_`

## Struktur
- Hauptdatei nur Header, Konstanten, PSR-4-Autoloader (`src/`), Bootstrap
- Logik in Klassen unter `src/`, Backend in `src/Admin/`, Ausgabe in `src/Frontend/`
- CSS/JS als eigene Dateien unter `assets/`, kein Inline-Code außer kleiner Konfiguration (wp_localize_script)
- `uninstall.php` vorhanden; Daten nur löschen, wenn der Nutzer das aktiviert hat
- `SECURITY.md` (Meldeweg für Lücken) und `.github/dependabot.yml` (Actions aktuell halten) in jedem Repository
- `README.md` und `CHANGELOG.md` pflegen, Version in Header, Konstante und Changelog gleich halten

## Backend-Menü
- Alle Einstellungen liegen im gemeinsamen Hauptmenü „b83“: Klasse `src/Admin/B83Menu.php` unverändert aus dem Schneebericht übernehmen (nur Namespace und Text Domain anpassen), dazu `assets/img/b83-logo.svg`, `assets/img/b83-menu-icon.svg`, `assets/css/b83-menu.css`
- Eigene Seite mit `add_submenu_page( B83Menu::SLUG, … )` einhängen, Link über Filter `b83_admin_plugin_links` melden
- Bei Änderungen an B83Menu `MENU_VERSION` erhöhen – die neueste Version auf einer Website baut das Menü

## Sicherheit (Pflicht)
- `defined( 'ABSPATH' ) || exit;` in jeder PHP-Datei
- Jede schreibende Aktion: Capability prüfen **und** Nonce prüfen (`check_admin_referer` / `check_ajax_referer`)
- Eingaben: `wp_unslash`, dann zentral validieren (Whitelist statt Blacklist), Längen begrenzen
- Ausgaben: immer spät escapen (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- SQL nur über `$wpdb->prepare()`; REST-Routen immer mit `permission_callback`
- Uploads: `is_uploaded_file`, Größen- und Formatprüfung
- Keine Daten im Plugin-Ordner speichern (wird bei Updates ersetzt), keine Secrets im Code
- Hinweise per festem Schlüssel in der URL, nie Freitext aus der URL ausgeben

## Erweiterbarkeit
- Eigene Actions/Filter mit Präfix an sinnvollen Stellen, im README dokumentieren
- Datenbank-Version als Option, Migrationen in `Migration`
- Bestehende Shortcodes, CSS-Klassen und Endpunkte bei Umbauten kompatibel halten

## Sprache
- Benutzeroberfläche Deutsch, alle Texte über `__()` / `esc_html__()` mit Text Domain übersetzbar
- Kommentare auf Deutsch

## Updates und Releases
- `Update URI: https://github.com/b83-Strategisches-Online-Marketing/<repo>` im Header
- Eigene `Updater`-Klasse (Filter `update_plugins_github.com`), Release-Asset heißt `<slug>.zip`
- Private Repos: Token nur als Konstante `B83_GITHUB_TOKEN` in der wp-config.php, nie im Code
- `.github/workflows/release.yml` erstellt das Release automatisch, sobald eine neue Version auf `main` liegt
- Bei Cache-relevanten Daten: Cache der betroffenen Seiten nach dem Speichern leeren

## Commits
- Aussagekräftige Commit-Nachrichten auf Deutsch
- Version im Header, in der Versionskonstante und im CHANGELOG gleichzeitig erhöhen (Semantic Versioning: Fehlerbehebung = x.y.Z, neue Funktion = x.Y.0, inkompatibel = X.0.0)
