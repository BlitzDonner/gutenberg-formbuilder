# Gutenberg Formbuilder

WordPress-Plugin: Formular-Builder **nur mit Gutenberg-Blöcken**, serverseitige Speicherung der Einsendungen, lokale Entwürfe im Browser (IndexedDB).

**Aktuelle Version:** in `gutenberg-formbuilder.php` → `GFB_PLUGIN_VERSION` / Header `Version:` (bei Releases immer **beide** Stellen sowie `blocks/form/block.json` → `version` anheben, damit Browser-Caches für `editor.js` / `frontend.js` / CSS greifen). **GitHub-Releases:** [Releases](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) inkl. Installations-ZIP; Änderungsliste: [`CHANGELOG.md`](CHANGELOG.md).

---

## Formular: Erscheinungsbild und Farben

Am Block **Formular** (`gfb/form`) steuern:

- **Farbmodus** (`appearanceMode`): `theme` (Theme-Standard), `auto` (System `prefers-color-scheme`), `light` oder `dark`.
- **Zwei Paletten:** **Formularfarben (Hell)** (`colorLabel`, `colorText`, …) und **Formularfarben (Dunkel)** (`darkColorLabel`, …). Beide sind unabhängig einstellbar.
- **Inspector:** Wo verfügbar, nutzt der Editor `PanelColorGradientSettings` (Farbe inkl. **Transparenz** und **Verläufe**). Dafür werden `disableCustomColors` / `disableCustomGradients` für diese Panels auf „erlaubt“ gesetzt, damit auch ohne Theme-Gradient-Palette eigene Verläufe möglich sind. Fallback: `PanelColorSettings` mit `enableAlpha`.

**Speichern / Callbacks (Editor):** `ColorGradientControl` ruft bei Farbwahl nacheinander `onColorChange(wert)` und `onGradientChange()` ohne Argument auf (internes Zwei-Slot-Modell von WordPress). Die Zuordnung auf **ein** Attribut pro Zeile behandelt diese Hilfsaufrufe über kurzlebige Flags, damit der Wert nicht sofort wieder geleert wird.

**Frontend**

- Der Wrapper rendert als `<div class="gfb-form-wrapper" data-gfb-appearance="theme|auto|light|dark">`.
- Benutzerdefinierte Farben kommen als Inline-CSS-Variablen **`--gfb-light-*`** / **`--gfb-dark-*`**; `assets/form.css` mappt sie auf die genutzten **`--gfb-*`** (Labels, Text, Felder, Button) je nach Modus.
- Die **Formular-Karte** (Padding, Rahmen, Hintergrund, Schatten) entspricht der Editor-Vorschau, damit Text und Felder nicht «auf der weissen Seite» liegen, während nur die Inputs dunkel wirken.
- **Hintergrund Formularbereich** (`colorFormShell` / `darkColorFormShell`): Einfarbig wird derselbe Wert für oben/unten in einen vertikalen Verlauf gelegt (`--gfb-shell-top` / `--gfb-shell-bottom` → `linear-gradient(180deg, …)`). Ist der Wert selbst ein **CSS-Verlauf**, wäre ein Verschachteln in diesem äusseren Verlauf **ungültig** (Browser verwirft die Deklaration → wirkt weiss). Lösung: PHP setzt bei erkanntem Verlauf die Klassen **`gfb-form-shell-gradient--light`** bzw. **`gfb-form-shell-gradient--dark`**; `form.css` setzt dann `background: var(--gfb-light-form-shell)` bzw. `var(--gfb-dark-form-shell)` direkt. Bei `appearanceMode=auto` und `prefers-color-scheme: dark` sorgt eine Zusatzregel dafür, dass ohne Dunkel-Verlauf-Klasse wieder der Standard-Shell-Verlauf genutzt wird.
- **PHP:** `GFB_Plugin::sanitize_gfb_color()` akzeptiert u. a. 3/4/6/8-stelliges Hex, `transparent` / `currentColor`, `var(--…)` (optional mit einfachem Fallback), klassische und moderne `rgb`/`rgba`/`hsl`/`hsla`, CSS-Verläufe (`linear-gradient`, `radial-gradient`, `conic-gradient`, `repeating-*`) sowie `color-mix`, `oklch`, `oklab`, `hwb`, `lch`, `lab` — jeweils mit Klammer-Check, Längenlimit und Ausschluss von `url()`, `expression()` usw.

**Editor**

- Vorschau-Container: `data-gfb-appearance` + dieselben Variablen; `assets/form.css` und `assets/gfb-editor.css` werden per `editor.js` in den **Block-Canvas-Iframe** eingehängt (`gfbSyncEditorFormStylesheet`: Link-IDs `gfb-editor-canvas-form-stylesheet` bzw. `gfb-editor-chrome-stylesheet`, URLs aus `gfbEditorAssets.editorCanvasFormStylesUrl` und `gfbEditorAssets.editorChromeStylesUrl`), sobald **mindestens ein** `gfb/form`-Block im Dokument existiert (inkl. `appearanceMode=theme`, damit die Theme-Vorschau dem Frontend nahekommt).
- Feldblöcke beziehen Kontext über `usesContext` (u. a. `gfb/appearanceMode`, Hell-/Dunkel-Farben); Feld-Overrides im Inspector nutzen dieselbe Farb-/Verlauf-Logik wie das Formular (`renderGfbColorPanel`, `mapColorSettingsToGradientSettings` in `assets/editor.js`).
- **Feld-Label in der Canvas-Vorschau:** Ist das Attribut `label` leer oder nur Leerzeichen, wird **kein** sichtbares Label gerendert (keine Platzhalter wie „Textfeld“ mehr). Checkbox zeigt dann nur die Box; Radio-Gruppe ohne Gruppenüberschrift, wenn das Label leer ist. Der Block **Absenden** behält bei leerem Button-Text weiterhin den übersetzten Standard-Text in der Vorschau. Details: [`docs/FARBEN-UND-VERLAUFE.md`](docs/FARBEN-UND-VERLAUFE.md#feld-labels-canvas-vorschau).

**Hinweis CSS:** Eigenschaften wie `color:` oder `border-color:` erwarten **Farben**, keine Verläufe. Verläufe wirken sinnvoll z. B. bei **Feldhintergrund**, **Button-Hintergrund** und **Hintergrund Formularbereich** (`background`).

Ausführlicher technischer Abriss: [`docs/FARBEN-UND-VERLAUFE.md`](docs/FARBEN-UND-VERLAUFE.md).

---

## Für die nächste Person: wo weitermachen?

1. **Plugin-Root:** dieses Verzeichnis ist das gesamte Plugin (`gutenberg-formbuilder.php` ist der Einstieg).
2. **Version:** bei Releases `GFB_PLUGIN_VERSION` und den Header `Version:` in `gutenberg-formbuilder.php` **gemeinsam** anheben, damit Browser-Caches für `editor.js` / `frontend.js` / CSS greifen.
3. **Wichtige Pfade:**
   - `includes/class-gfb-plugin.php` – Block-Registrierung, Form-Render (inkl. `data-gfb-appearance`, Farb-Variablen), Schema für Submit
   - `includes/class-gfb-submit-handler.php` – Validierung, DB-Insert, Mail
   - `includes/class-gfb-admin-submissions.php` – Admin-Seite „Formular-Einträge“, Löschen (Redirect im `load-*`-Hook!)
   - `assets/editor.js` – alle Block-`edit`/`save`-Definitionen
   - `assets/frontend.js` – IndexedDB-Entwürfe, Debounce, Safari-Hacks
   - `assets/form.css` – **Frontend**-Styles (`wp_enqueue_style( 'gfb-form' )` bei jedem gerenderten Formular, inkl. `appearanceMode=theme`); im öffentlichen Frontend wird `gfb-editor.css` **nicht** geladen.
   - `assets/gfb-editor.css` – **nur** Block-Editor-Canvas: zusammen mit `form.css` per `assets/editor.js` → `gfbSyncEditorFormStylesheet()` in den Canvas-Iframe (Link-IDs `gfb-editor-canvas-form-stylesheet` / `gfb-editor-chrome-stylesheet`), sobald ein `gfb/form`-Block existiert. Die `block.json`-Dateien setzen dafür **kein** `editorStyle`.
   - `assets/admin-submissions.css` – nur Referenz; im Admin wird CSS **inline** aus der Datei gelesen (kein zuverlässiger `plugins_url()` auf manchen Local-Setups)
4. **Bekannte technische Entscheidungen:**
   - **Einzigartige technische Feldnamen:** Im Inspector nicht editierbar; `syncAutoFieldName` in `assets/editor.js` setzt `name` aus Label (sonst Platzhalter, sonst Typ-Fallback) plus Kurz-ID aus der Block-`clientId` (ASCII-Slug, Umlaute → ae/oe/ue/ss). Duplizieren von Blöcken oder ganzen Formularen erzeugt neue IDs → neue Namen. `syncFormInstance` vergibt bei neuer Formular-Instanz eine neue `formId`. Sonst kollidieren POST-Keys.
   - **Editor-Styling:** `enqueue_block_editor_assets` allein reicht für die **Canvas-Vorschau** nicht; deshalb hängt `editor.js` bei vorhandenem `gfb/form`-Block `form.css` und `gfb-editor.css` in den Canvas-Iframe (`gfbSyncEditorFormStylesheet`, URLs aus `gfbEditorAssets` in `includes/class-gfb-plugin.php` → `wp_localize_script`).
   - **Eingabetext vs. Theme:** In `form.css` / `gfb-editor.css` nutzen sichtbare Feld-Texte `color` und `-webkit-text-fill-color` mit `!important`, damit Block-Themes die Plugin-Farben (`--gfb-text` / Hell-Dunkel-Variablen) nicht überschreiben. Bei **Theme + eigene Farben** greifen zusätzliche Regeln auf `.gfb-form-wrapper[data-gfb-appearance="theme"].gfb-form-colors-custom`.
   - **Submit / Schema:** Die Server-Validierung sucht den `gfb/form`-Block per `GFB_Plugin::locate_form_block_for_post()` in Beitragsinhalt, **Bibliotheks-/Musterblöcken** (`core/block` → `wp_block`), **Template-Parts** (`core/template-part`) und typischen **FSE-Templates** (`wp_template`). Fehlermeldung „Formularschema nicht gefunden“ entsteht, wenn der Block dort nicht vorkommt (früher nur `post_content`); zusätzliche Markup-Quellen per Filter `gfb_form_schema_markup_sources`.
   - **Formularbereich als Verlauf:** Klassen `gfb-form-shell-gradient--light` / `--dark` werden in `includes/class-gfb-plugin.php` gesetzt, wenn der jeweilige Shell-Wert ein CSS-Verlauf ist; Auswertung über `gfb_sanitized_attr_is_css_gradient()` (basiert auf `sanitize_gfb_color()`).
   - **Admin-Löschen:** Redirect nur in `load-toplevel_page_gfb-submissions`, nicht in `render_page`, sonst „headers already sent“.

---

## Installation

1. Ordner `gutenberg-formbuilder` nach `wp-content/plugins/` kopieren oder klonen.
2. Im WordPress-Admin Plugin aktivieren (legt u. a. `wp_gfb_submissions`, `wp_gfb_files`, `wp_gfb_audit` an; ab **2.0** zusätzlich privates Storage und Cron — siehe [`INSTALL.md`](INSTALL.md)).
3. Seite/Beitrag bearbeiten → Block „Formular“ einfügen.

## Anforderungen

- WordPress ≥ 6.6 (laut Plugin-Header)
- PHP ≥ 7.4

## Funktionsumfang (Kurz)

- Container-Block `gfb/form` mit InnerBlocks; Feldblöcke `gfb/field-*` + `gfb/field-submit` (verstecktes Feld: optionales **Label (Hinweis)** nur für Editor/Eintragsdarstellung, nicht im Frontend-Formular)
- Submit über `admin_post` / `admin_post_nopriv` mit Nonce, Honeypot, Timing, Rate-Limit
- Einsendungen in `{prefix}gfb_submissions` (JSON `payload`, inkl. `_gfb_labels` für Labels zum Zeitpunkt des Absendens)
- Admin-Menü **Formular-Einträge** (Liste, Detail, Löschen)
- Lokale Entwürfe (IndexedDB), optional Wiederherstellen / Entwurf löschen (inkl. Safari-Fixes)

## Repository-Layout

```
gutenberg-formbuilder.php   # Bootstrap, Konstanten, Version
includes/                   # PHP: Plugin, Submit, Admin
assets/                     # editor.js, frontend.js, CSS
blocks/*/block.json         # Block-Metadaten
docs/                       # optionale Zusatzdoku (z. B. Farben/Verläufe)
```

## Entwicklung

- Kein npm-Build: JS/CSS sind Quelldateien.
- Nach Änderungen an JS/CSS **Version** in `gutenberg-formbuilder.php` **und** in `blocks/form/block.json` (`version`) erhöhen (Query-String `ver=` für eingebundene Editor-Styles).
- PHP-Syntax prüfen: `php -l datei.php` (falls PHP im PATH).

**Zuletzt dokumentiert (Auszug):** automatische technische Feldnamen (`syncAutoFieldName` in `assets/editor.js`), Formular-`formId` bei Duplikat (`syncFormInstance`), Farb-/Verlauf-Panels inkl. zugeklapptem Panel „Farben (Feld überschreiben)“ (`renderGfbColorPanel` mit `PanelBody`), Canvas-Styling (`gfb-editor.css`: disabled-Felder, Theme-Hintergrund), `flex-direction: column` für `.gfb-form-fields` in `form.css`, erweiterter Farb-Sanitizer, Shell-Gradient-Klassen, Radio-`optionsLayout`, leeres Feld-`label` in der Editor-Vorschau, Schema-Suche beim Submit (`locate_form_block_for_post`, FSE/Muster/Template-Part), Redirect-Parameter `gfb_status` / `gfb_code` (ohne `gfb_msg`).

## Submit-Fehler: „Formularschema nicht gefunden“

Nach dem Absenden leitet WordPress u. a. auf eine URL mit `gfb_status=error`, `gfb_code=err_schema` und passender `gfb_form=…` um, wenn die **serverseitige** Suche keinen `gfb/form`-Block mit der gesendeten `formId` findet (Validierung in `includes/class-gfb-submit-handler.php`). Die nutzersichtliche Meldung kommt aus `GFB_Submit_Handler::status_message_for()` (nicht aus einem Klartext-Parameter in der URL).

**Seit 1.0.0** durchsucht `GFB_Plugin::locate_form_block_for_post()` u. a.:

1. `post_content` der Seite/ des Beitrags (`gfb_post_id` aus dem Formular),
2. eingebettete **Bibliotheks-/Musterblöcke** (`core/block` → `wp_block`),
3. **Template-Parts** (`core/template-part`),
4. typische **FSE-Block-Templates** (`wp_template`, z. B. `page-{slug}`, `page`, `single`, `singular`, `index`).

**Wenn der Fehler bleibt:** Formular liegt vermutlich in einem anderen Template (z. B. eigenes `archive-…`) oder ausserhalb von WordPress-Blöcken. Dann per PHP den Filter **`gfb_form_schema_markup_sources`** nutzen und zusätzliche Block-Markup-Strings an `$sources` anhängen (siehe Docblock in `includes/class-gfb-plugin.php`).

## MVP-Grenzen / Ideen für später

- Mehrschritt, bedingte Logik, CRM-Webhooks
- Export CSV aus Admin
- Tests (PHPUnit / E2E)

## Lizenz

Ohne separate LICENSE-Datei: Klärung durch die Organisation **BlitzDonner** / Projekteigner; bei Bedarf MIT/GPL ergänzen.
