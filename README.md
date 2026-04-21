# Gutenberg Formbuilder

WordPress-Plugin: Formular-Builder **nur mit Gutenberg-Blöcken**, serverseitige Speicherung der Einsendungen, lokale Drafts im Browser (IndexedDB).

**Aktueller Stand (Version siehe `gutenberg-formbuilder.php` / `GFB_PLUGIN_VERSION`):** `0.4.1`

---

## Für die nächste Person: wo weitermachen?

1. **Plugin-Root:** dieses Verzeichnis ist das gesamte Plugin (`gutenberg-formbuilder.php` ist der Einstieg).
2. **Version:** bei Releases `GFB_PLUGIN_VERSION` und den Header `Version:` in `gutenberg-formbuilder.php` **gemeinsam** anheben, damit Browser-Caches für `editor.js` / `frontend.js` / CSS greifen.
3. **Wichtige Pfade:**
   - `includes/class-gfb-plugin.php` – Block-Registrierung, Form-Render, Schema für Submit
   - `includes/class-gfb-submit-handler.php` – Validierung, DB-Insert, Mail
   - `includes/class-gfb-admin-submissions.php` – Admin-Seite „Formular-Einträge“, Löschen (Redirect im `load-*`-Hook!)
   - `assets/editor.js` – alle Block-`edit`/`save`-Definitionen
   - `assets/frontend.js` – IndexedDB-Drafts, Debounce, Safari-Hacks
   - `assets/form.css` – Frontend + importiert `gfb-editor.css`
   - `assets/gfb-editor.css` – **nur** Editor-Canvas (wird zusätzlich per `block.json` → `editorStyle` im iframe geladen)
   - `assets/admin-submissions.css` – nur Referenz; im Admin wird CSS **inline** aus der Datei gelesen (kein zuverlässiger `plugins_url()` auf manchen Local-Setups)
4. **Bekannte technische Entscheidungen:**
   - **Einzigartige Feldnamen:** Defaults in `blocks/*/block.json` für `name` sind leer; `ensureFieldName` in `editor.js` vergibt eindeutige Namen. Sonst kollidieren POST-Keys.
   - **Editor-Styling:** `enqueue_block_editor_assets` allein reicht für die **Canvas-Vorschau** nicht; deshalb `editorStyle` in **allen** Block-`block.json` auf `assets/gfb-editor.css`.
   - **Admin-Löschen:** Redirect nur in `load-toplevel_page_gfb-submissions`, nicht in `render_page`, sonst „headers already sent“.

---

## Installation

1. Ordner `gutenberg-formbuilder` nach `wp-content/plugins/` kopieren oder klonen.
2. Im WordPress-Admin Plugin aktivieren (legt Tabelle `wp_gfb_submissions` an).
3. Seite/Beitrag bearbeiten → Block „Formular“ einfügen.

## Anforderungen

- WordPress ≥ 6.6 (laut Plugin-Header)
- PHP ≥ 7.4

## Funktionsumfang (Kurz)

- Container-Block `gfb/form` mit InnerBlocks; Feldblöcke `gfb/field-*` + `gfb/field-submit`
- Submit über `admin_post` / `admin_post_nopriv` mit Nonce, Honeypot, Timing, Rate-Limit
- Einsendungen in `{prefix}gfb_submissions` (JSON `payload`, inkl. `_gfb_labels` für Labels zum Zeitpunkt des Absendens)
- Admin-Menü **Formular-Einträge** (Liste, Detail, Löschen)
- Lokale Drafts (IndexedDB), optional Wiederherstellen / Draft löschen (inkl. Safari-Fixes)

## Repository-Layout

```
gutenberg-formbuilder.php   # Bootstrap, Konstanten, Version
includes/                   # PHP: Plugin, Submit, Admin
assets/                     # editor.js, frontend.js, CSS
blocks/*/block.json         # Block-Metadaten, editorStyle
```

## Entwicklung

- Kein npm-Build: JS/CSS sind Quelldateien.
- Nach Änderungen an JS/CSS Version in `gutenberg-formbuilder.php` erhöhen.
- PHP-Syntax prüfen: `php -l datei.php` (falls PHP im PATH).

## MVP-Grenzen / Ideen für später

- Mehrschritt, bedingte Logik, CRM-Webhooks
- Export CSV aus Admin
- Tests (PHPUnit / E2E)

## Lizenz

Ohne separate LICENSE-Datei: Klärung durch die Organisation **BlitzDonner** / Projekteigner; bei Bedarf MIT/GPL ergänzen.
