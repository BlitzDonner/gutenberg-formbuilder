# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Versionsnummern folgen [SemVer](https://semver.org/lang/de/); Vorab-Releases trugen das Suffix `-beta.N`.

## [1.0.0] – 2026-05-06

**Erster stabiler Release** (GitHub: reguläres Release, kein Pre-release). Funktionsumfang entspricht **1.0.0-beta.3**; die Beta-Releases dienten der Erprobung.

- Siehe unten **1.0.0-beta.3 … beta.1** für die nachvollziehbare Entwicklungs-Historie.
- Installation: ZIP aus dem Release-Asset `gutenberg-formbuilder-1.0.0.zip` → Ordner `gutenberg-formbuilder` nach `wp-content/plugins/`.

## [1.0.0-beta.3] – 2026-04-29

- **„Formularschema nicht gefunden“:** Schema-Suche findet `gfb/form` nicht mehr nur in `post_content`, sondern auch in **Bibliotheks-/Musterblöcken** (`core/block` → `wp_block`), **Template-Parts** (`core/template-part`) und Kandidaten-**FSE-Templates** (`wp_template`, z. B. `page-{slug}`, `page`, `singular`, `index`). Filter: `gfb_form_schema_markup_sources`.

## [1.0.0-beta.2] – 2026-04-29

- **Eingabetext:** `form.css` und `gfb-editor.css` setzen Feld-`color` / `-webkit-text-fill-color` (und Platzhalter) mit `!important`, damit Block-Themes die gewählten Plugin-Farben nicht überschreiben.
- **Theme + eigene Farben:** `form.css` wird jetzt **immer** beim Formular-Render geladen; neue Regeln für `[data-gfb-appearance="theme"].gfb-form-colors-custom` binden die Inline-Variablen (`--gfb-light-*` / `--gfb-dark-*`) an Felder inkl. `prefers-color-scheme: dark`.

## [1.0.0-beta.1] – 2026-04-29

Erster öffentlicher **Beta**-Release (GitHub: Pre-release). Für Tests und Feedback; nicht für produktionskritische Sites ohne eigene Prüfung empfohlen.

### Enthalten

- Formular-Container `gfb/form` mit InnerBlocks; Feldblöcke (Text, E-Mail, Textbereich, Auswahl, Checkbox, Zahl, Telefon, URL, Datum, Uhrzeit, Datum/Uhrzeit, Radio, Bereich, verstecktes Feld, Datei, Absenden).
- Serverseitiger Submit (Nonce, Honeypot, Rate-Limit), Speicherung in `{prefix}gfb_submissions`, Admin **Formular-Einträge**.
- Lokale Entwürfe (IndexedDB), optional Wiederherstellung / Draft löschen.
- **Erscheinungsbild:** Farbmodus (Theme, Auto, Hell, Dunkel), Formularfarben inkl. Verläufen; Editor-Canvas-Vorschau auch bei Theme-Stil mit `gfb-editor.css`.
- **Editor:** leeres Feld-`label` ohne Platzhalter in der Vorschau; verstecktes Feld mit editierbarem **Label (Hinweis)** für Bearbeiter und Eintragsdarstellung.

### Installation

- ZIP aus dem GitHub-Release laden, entpacken und den Ordner `gutenberg-formbuilder` nach `wp-content/plugins/` legen (oder per ZIP im WordPress-Admin **Plugins → Installieren** hochladen).
- Plugin aktivieren (legt die Tabelle `wp_gfb_submissions` an).

[1.0.0]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0
[1.0.0-beta.3]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.1
