---
description: Neues Release vorbereiten (Version, Changelog, Tag, ZIP)
---

Bereite ein Release vor. Gewünschte Version oder Art: $ARGUMENTS (z. B. `2.1.0` oder `patch` / `minor` / `major`).

1. `git status` prüfen. Bei uncommitteten Änderungen abbrechen und nachfragen.
2. Version überall gleich setzen: Plugin-Header `Version:`, Versions-Konstante im Code, `Stable tag:` in `readme.txt`.
3. Changelog in `readme.txt` (und `CHANGELOG.md`, falls vorhanden) aus den Commits seit dem letzten Tag ergänzen. Kurz, für Kunden verständlich, ohne KI-Floskeln.
4. `php -l` auf alle PHP-Dateien.
5. Commit „Release X.Y.Z“ und Tag `vX.Y.Z` anlegen.
6. ZIP bauen: `git archive --format=zip --prefix={{PLUGIN_SLUG}}/ -o ../{{PLUGIN_SLUG}}-X.Y.Z.zip vX.Y.Z`
   (Dateien mit `export-ignore` in `.gitattributes` landen nicht im ZIP.)
7. Zusammenfassen, was zu tun bleibt: `git push --follow-tags`, GitHub-Release anlegen, bei Freemius-Plugins Upload im Freemius-Dashboard, bei wordpress.org SVN.

Nicht selbst pushen.
