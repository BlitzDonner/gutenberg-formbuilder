# Gutenberg Formbuilder

WordPress-Plugin: Formular-Builder **nur mit Gutenberg-Blöcken**, serverseitige Speicherung der Einsendungen, lokale Entwürfe im Browser (IndexedDB).

**Aktuelle Version:** in `gutenberg-formbuilder.php` → `GFB_PLUGIN_VERSION` / Header `Version:` (bei Releases immer **beide** Stellen sowie `blocks/form/block.json` → `version` anheben, damit Browser-Caches für `editor.js` / `frontend.js` / CSS greifen). **GitHub-Releases:** [Releases](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) inkl. Installations-ZIP; Änderungsliste: [`CHANGELOG.md`](CHANGELOG.md).

**Sprachen:** Öffentliche Formular- und Fehlermeldungen nutzen WordPress-i18n (`load_plugin_textdomain` auf `init`, Ordner [`languages/`](languages/)). Mitgeliefert sind u. a. **Englisch (`en_US`)**, **Französisch (`fr_FR`)**, **Italienisch (`it_IT`)**; Quellstrings im Code sind Deutsch. Die gewählte Locale entspricht typischerweise **Einstellungen → Allgemein → Sprache der Website** (mehrsprachige Plugins können den `locale`-Filter setzen). Details: [`INSTALL.md`](INSTALL.md#10-mehrsprachige-frontend-meldungen).

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

## Formular: E-Mail-Benachrichtigung

Am Block **Formular** (`gfb/form`) → Inspector **E-Mail-Benachrichtigung**:

| Einstellung | Kurz |
|-------------|------|
| **E-Mail nach Absenden senden** | Standard **aus**; bei Aktivierung wird nach erfolgreicher Speicherung `wp_mail` ausgelöst. |
| **Empfänger** | `FormTokenField` mit E-Mail-Validierung pro Token; Standard-Vorschau = **Admin-E-Mail** (beim Anlegen des Formulars und erneut, wenn der Block verlassen wird und das Feld leer ist). Leer gespeichert → Versand an Admin-E-Mail. Max. 10 Empfänger. |
| **Betreff** | Optional; leer = Standard (`[Seitenname]` + Formularname/ID). Platzhalter: `{{feldname}}`, `{{label_feldname}}`. |
| **Absender-E-Mail** | **Admin-E-Mail**, **eigene feste Adresse** oder ein **E-Mail-Feld** aus dem Formular als `From`-Adresse (sonst Fallback Admin). |
| **Absendername** | Optional; leer = Seitentitel. Platzhalter `{{feldname}}`, `{{label_feldname}}` wie beim Betreff. |

**Sicherheit / Inhalt:** Einstellungen kommen nur aus Block-Attributen (nicht aus POST). Verschlüsselte Felder und Dateien erscheinen in der Mail nicht im Klartext. Ausführlich: [`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md).

---

## Spam-Schutz (Friendly Captcha)

Optionaler, **serverseitig geprüfter** Spam-Schutz über [Friendly Captcha](https://friendlycaptcha.com/de/) – einen EU-Anbieter (Deutschland). Statt Bilderrätsel löst der Browser eine **Proof-of-Work**-Aufgabe. Friendly Captcha setzt **keine Cookies**, betreibt **kein Fingerprinting**, **kein Tracking** und führt **keinen Drittlandtransfer** durch.

| Einstellung | Kurz |
|-------------|------|
| **Datensparsamkeit** | Im Standardbetrieb braucht es keine Einwilligung – Rechtsgrundlage ist das **berechtigte Interesse** (Spam-Abwehr). Das Captcha-Element lädt **erst bei Formular-Interaktion**, nicht beim Seitenaufbau – kein Vorab-Aufruf des Anbieters. |
| **Verifikation** | Serverseitig gegen die offizielle **siteverify**-Schnittstelle (Version 2, `global.frcapi.com`). Im Frontend liegt nur der **Site-Key**; das **Secret** bleibt serverseitig. |
| **Erzwingungsmodus** | **Mit Ausnahme bei Serverausfall:** Captcha Pflicht; nur wenn der Anbieter nicht erreichbar ist, geht der Versand trotzdem durch. **Streng:** auch bei Serverausfall **kein** Versand. |
| **Admin-Vorlagen** | Mitgeliefert und kopierbar: ein **Textbaustein für die Datenschutzerklärung** und ein **interner Vermerk zur Interessenabwägung** – jeweils als **unverbindliche Vorlage** gekennzeichnet. |

Die Admin-Bereiche **ClamAV-Einstellungen** und **Datenschutz** sind in einklappbaren Akkordeons dargestellt. Ausführlich: [`docs/CAPTCHA-INTEGRATION-SPEC.md`](docs/CAPTCHA-INTEGRATION-SPEC.md).

---

## Für die nächste Person: wo weitermachen?

1. **Plugin-Root:** dieses Verzeichnis ist das gesamte Plugin (`gutenberg-formbuilder.php` ist der Einstieg).
2. **Version:** bei Releases `GFB_PLUGIN_VERSION` und den Header `Version:` in `gutenberg-formbuilder.php` **gemeinsam** anheben, damit Browser-Caches für `editor.js` / `frontend.js` / CSS greifen.
3. **Wichtige Pfade:**
   - `includes/class-gfb-plugin.php` – Block-Registrierung, Form-Render (inkl. `data-gfb-appearance`, Farb-Variablen), Schema für Submit
   - `includes/class-gfb-submit-handler.php` – Validierung, DB-Insert, E-Mail-Benachrichtigung (`send_notification_mail`)
   - `includes/class-gfb-admin-submissions.php` – Admin-Seite „Formular-Einträge“, Löschen (Redirect im `load-*`-Hook!)
   - `assets/editor.js` – alle Block-`edit`/`save`-Definitionen
   - `assets/frontend.js` – IndexedDB-Entwürfe, Wiederherstellung (`restoreMode`: Standard **automatisch**), Debounce, Safari-Hacks
   - `assets/form.css` – **Frontend**-Styles (`wp_enqueue_style( 'gfb-form' )` bei jedem gerenderten Formular, inkl. `appearanceMode=theme`); im öffentlichen Frontend wird `gfb-editor.css` **nicht** geladen.
   - `assets/gfb-editor.css` – **nur** Block-Editor-Canvas: zusammen mit `form.css` per `assets/editor.js` → `gfbSyncEditorFormStylesheet()` in den Canvas-Iframe (Link-IDs `gfb-editor-canvas-form-stylesheet` / `gfb-editor-chrome-stylesheet`), sobald ein `gfb/form`-Block existiert. Die `block.json`-Dateien setzen dafür **kein** `editorStyle`.
   - `assets/admin-submissions.css` – nur Referenz; im Admin wird CSS **inline** aus der Datei gelesen (kein zuverlässiger `plugins_url()` auf manchen Local-Setups)
4. **Bekannte technische Entscheidungen:**
   - **Formular-ID (`formId`):** Pro Block-Instanz stabil; bei Duplizieren/Einfügen des Formulars vergibt `syncFormInstance` eine neue `formId` (`gfb_…` aus `clientId`). Submit, Schema, Admin-Filter und Payload-Auswertung binden immer zuerst an diese ID.
   - **Technische Feldnamen:** Im Inspector editierbar (`GfbFieldNameInspector`); bleiben über Speichern stabil (nicht mehr an jedem Reload an `clientId` gekoppelt). Neues Feld oder **Feld-Duplikat** → einmaliger Vorschlag aus Label (eindeutig innerhalb des Formulars, ggf. `_2`). Duplikat im Editor → Fehlerhinweis; serverseitig `err_duplicate`. Attribute `nameClientId` markiert die Block-Instanz.
   - **Payload im Admin:** `GFB_Plugin::get_submission_payload_for_row()` liefert nur Felder des Schemas der gespeicherten `form_id` (relevant bei mehreren Formularen mit gleichen Feldnamen auf einer Seite).
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

- Container-Block `gfb/form` mit InnerBlocks; Feldblöcke `gfb/field-*` + `gfb/field-submit` (verstecktes Feld: optionales **Label (Hinweis)** nur für Editor/Eintragsdarstellung, nicht im Frontend-Formular); **Datum / Uhrzeit / Termin:** optionaler **Voreingestellter Wert** im Inspector, Standard leer (kein HTML-`value`); Frontend **`pattern`** und **`placeholder`** aus **Einstellungen → Allgemein** (`date_format` / `time_format`, z. B. `dd.mm.yyyy`)
- **Erfolgsbereich** (`gfb/form-success`, nur innerhalb von `gfb/form`): beliebige InnerBlocks, die nach erfolgreichem Absenden **anstelle des Formulars** erscheinen, wenn **keine** Folgeseite gewählt ist. Im Text stehen Platzhalter `{{feldname}}` (technischer Name) und optional `{{label_feldname}}`; die Werte setzt `assets/frontend.js` per `sessionStorage`-Snapshot beim Absenden (Datei-Felder: `[Datei]`). Mit gewählter Folgeseite bleibt das bisherige Verhalten (Hinweiszeile / Redirect-Zielseite). Im Erfolgsbereich kann der Block **Platzhalter-Hilfe** (`gfb/token`) die **technischen Feldnamen** in einem Auswahlfeld anbieten; nach der Wahl wird `{{feldname}}` (übermittelter Wert) an dieser Stelle als Absatz eingefügt (nur Editor). Optional weiterhin `{{label_feldname}}` manuell für die Anzeige-Bezeichnung.
- Submit über `admin_post` / `admin_post_nopriv` mit gestaffelter Abwehrkette: **Nonce → HMAC-Token → Honeypot → Rate-Limit → Captcha** (Friendly Captcha, optional; siehe Abschnitt **Spam-Schutz** unten)
- **E-Mail-Benachrichtigung** pro Formular (optional): Empfänger, Betreff, Absender — siehe Abschnitt oben und [`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md)
- Einsendungen in `{prefix}gfb_submissions` (JSON `payload`, inkl. `_gfb_labels` für Labels zum Zeitpunkt des Absendens)
- Admin-Menü **Formular-Einträge** (Liste, Detail, Löschen, **CSV-Export**): Export eines einzelnen Formulars als UTF-8-BOM-CSV (Semikolon, RFC-4180); verschlüsselte Felder maskiert oder – mit Cap `gfb_decrypt_submissions` – im Klartext; IP-Adresse nur bei Klartext-Export; CSV-Injection-Härtung; Audit-Einträge für jeden Export
- Lokale Entwürfe (IndexedDB): **Standard** ist automatische Wiederherstellung ohne Browser-Dialog; optional **Nachfragen** (`restoreMode: prompt`) im Block **Formular** → Formulareinstellungen; Button **Entwurf löschen** (abschaltbar)

## Repository-Layout

```
gutenberg-formbuilder.php   # Bootstrap, Konstanten, Version
includes/                   # PHP: Plugin, Submit, Admin
assets/                     # editor.js, frontend.js, CSS
blocks/*/block.json         # Block-Metadaten
languages/                  # Übersetzungen (*.po / *.mo), siehe INSTALL.md
docs/                       # Zusatzdoku (Farben/Verläufe, E-Mail-Benachrichtigung, …)
```

## Entwicklung

- Kein npm-Build: JS/CSS sind Quelldateien.
- Nach Änderungen an JS/CSS **Version** in `gutenberg-formbuilder.php` **und** in `blocks/form/block.json` sowie in `blocks/form-success/block.json` und `blocks/token/block.json` (`version`) erhöhen (Query-String `ver=` für eingebundene Skripte/Styles).
- PHP-Syntax prüfen: `php -l datei.php` (falls PHP im PATH).

**Zuletzt dokumentiert (Auszug):** **Technische Feldnamen** (stabil, editierbar, `nameClientId`), **`formId`** pro Formular-Instanz, **E-Mail-Benachrichtigung** ([`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md)), **Erfolgsbereich**, **Platzhalter-Hilfe** (`gfb/token`), **Datum/Zeit** (`pattern`/`placeholder` aus WP-Datumsformat), **Safari/WebKit-Fallback** (Admin-Option), Entwürfe, Submit-**Detailnotices**, Schema-Suche (`locate_form_block_for_post`).

## Datum, Uhrzeit, Termin (Frontend)

Die Blöcke `gfb/field-date`, `gfb/field-time` und `gfb/field-datetime` rendern serverseitig (`includes/class-gfb-field-renderer.php`):

| Ausgabe | Quelle | Beispiel bei `date_format` = `d.m.Y` |
|--------|--------|--------------------------------------|
| `pattern` | Regex aus PHP-Formatzeichen | `\d{2}\.\d{2}\.\d{4}` |
| `placeholder` | Anzeigemaske (nur ohne eigenen Block-Platzhalter) | `dd.mm.yyyy` |

Die **serverseitige Validierung** beim Absenden erwartet weiterhin **ISO-Strings** (`Y-m-d`, `H:i`, `Y-m-d\TH:i` in `includes/class-gfb-submit-handler.php`).

**Safari / WebKit (ohne Blink):** Standardmäßig wandelt `assets/frontend.js` die Felder in **Texteingaben** um (stabiler bei 12h-Format und Validierung). Abschaltbar unter **Formular-Einträge → Sicherheit → Formular (Frontend)**. Deaktiviert bleiben native `type="date"` / `time` / `datetime-local`; Safari kann in leeren Feldern trotzdem das **heutige Datum** als graue Anzeige zeigen — das ist Browser-Verhalten, kein gespeicherter Wert. Mit aktivem Fallback und leerem Feld: `placeholder` aus den WP-Einstellungen, kein Übernehmen von Safaris „heute“ als `value` (`data-gfb-has-default`).

Chrome, Firefox, Edge und Opera nutzen weiterhin die **nativen Picker** (unabhängig von der Admin-Option).

## Zeitfeld: „Ungültiger Wert“ (Browser)

Die Meldung **„Ungültiger Wert“** bei `<input type="time">` kommt von der **HTML5-Validierung im Browser**, nicht von der Plugin-PHP-Logik (die erwartet weiterhin normales **`HH:MM`** im 24-Stunden-Format).

Typische Ursache: **System/Browser mit 12-Stunden-UI** — im Stunden-Segment sind oft nur **1–12** erlaubt; eine Eingabe wie **18** für die Stunde wirkt dort ungültig, obwohl **18:30** in einem reinen 24h-Modell korrekt wäre.

Das gerenderte `<form class="gfb-form">` trägt das Attribut **`lang="…"`** (WordPress-Locale via `determine_locale()`), damit Steuerelemente stärker an die **Sprache der Website** gekoppelt sind — in manchen Browser/OS-Kombinationen reduziert das 12h-/24h-Reibungen. Trotzdem kann das Verhalten browser- und systemabhängig bleiben; bei hartnäckigen Fällen helfen **24-Stunden-Anzeige** in den Systemeinstellungen oder ein anderer Browser zum Test.

**Hinweis nach Absenden:** Die Erfolgs-/Fehlerzeile kommt aus der Redirect-URL (`gfb_status`, …). Nach dem Laden entfernt `frontend.js` diese Parameter mit **`history.replaceState`**, damit ein **Reload** das Formular ohne feststeckenden Hinweis zeigt. **Entwurf löschen** blendet die Notice zusätzlich aus und bereinigt die URL.

## Erfolgsbereich: Nachricht und Platzhalter

Gilt nur, wenn im Block **Formular** **keine** Folgeseite gewählt ist und mindestens ein Block **Erfolgsbereich** (`gfb/form-success`) eingefügt wurde.

- **Darstellung:** Nach erfolgreichem Absenden rendert PHP **kein** `<form>` mehr, sondern nur noch den Inhalt der Erfolgsbereiche (InnerBlocks wie Absätze, Überschriften, …). Die Standard-Erfolgsnotice entfällt in diesem Fall.
- **Platzhalter:** Im Text stehen `{{feldname}}` für den **übermittelten Wert** (technischer POST-Name des Feldes) und optional `{{label_feldname}}` für die **Bezeichnung** aus dem Block-Schema (sichtbares Label zum Zeitpunkt der Speicherung).
- **Datenquelle:** Beim Absenden schreibt `assets/frontend.js` einen Snapshot der Feldwerte in **`sessionStorage`** (Schlüssel `gfb_submit_snapshot:` + derselbe Wert wie `data-gfb-key` am Formular). Nach dem Redirect ersetzt das Skript die Platzhalter in den **Textknoten** des Erfolgsbereichs. **Datei-Felder** liefern im Snapshot nur den Platzhaltertext **`[Datei]`** (kein Dateiinhalt).
- **Platzhalter-Hilfe** (`gfb/token`, nur im Erfolgsbereich): Auswahlliste der **technischen Feldnamen**; nach der Wahl wird ein **Absatz** mit `{{feldname}}` eingefügt (der Hilfsblock wird dabei entfernt). Für Fliesstext im gleichen Absatz den Token nachträglich anpassen oder manuell setzen.
- **Grenzen:** Platzhalter funktionieren **nicht** bei Weiterleitung auf eine **andere** Folgeseite (dort liegt der Formularblock nicht). Ohne `sessionStorage` (blockiert, privates Fenster ohne derselben Navigation) bleiben Platzhalter sichtbar, werden aber nicht ersetzt.

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
- Tests (PHPUnit / E2E)

## Lizenz

Ohne separate LICENSE-Datei: Klärung durch die Organisation **BlitzDonner** / Projekteigner; bei Bedarf MIT/GPL ergänzen.
