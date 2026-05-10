# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Versionsnummern folgen [SemVer](https://semver.org/lang/de/); Vorab-Releases trugen das Suffix `-beta.N`.

## [2.0.5] – 2026-05-10

### Geändert

- Entwurfs-Wiederherstellung: Standard ist **automatisch** (ohne Browser-`confirm`); manuelle Bestätigung weiterhin über Block-Einstellung „Nachfragen“.

### Dokumentation

- `README.md`, `INSTALL.md` (neu: Abschnitt zu Locale/Übersetzungen), `SECURITY.md`, `SICHERHEITSKONZEPT.md`, `docs/FARBEN-UND-VERLAUFE.md`: an Stand **2.0.5** und aktuelle Features angepasst.

## [2.0.4] – 2026-05-10

### Neu

- Übersetzungen für Formular-/Fehlermeldungen: `languages/gutenberg-formbuilder-{en_US,fr_FR,it_IT}.mo` (Quellstrings bleiben Deutsch). WordPress-Locale unter **Einstellungen → Allgemein → Sprache der Website**; `load_plugin_textdomain` auf `init` Priorität 1.

## [2.0.3] – 2026-05-10

### Geändert

- Datei-Upload-Fehler (z. B. unerlaubter Dateityp): Meldung erklärt, dass die Datei nicht übernommen wurde und das **gesamte Formular nicht übermittelt** wurde; `gfb_detail` wird bei `err_file`, `err_external` und `err_crypto` ebenfalls in der Formular-Notice angezeigt.

## [2.0.2] – 2026-05-10

### Geändert

- `assets/form.css`: `input[type="number"]` — Spin-Buttons in WebKit explizit entfernt, `-moz-appearance: textfield` für Firefox, damit Zahl- und Telefon-/Textfelder dieselbe sichtbare Feldhöhe und Ausrichtung haben (Frontend und Editor-Canvas).

## [2.0.1] – 2026-05-10

### Geändert

- README, INSTALL, `docs/FARBEN-UND-VERLAUFE.md`, CHANGELOG: Inhalt an den aktuellen Code angeglichen (u. a. Speicherpfad `.gfb-private`, Redirect-Parameter `gfb_status` / `gfb_code`, Canvas-CSS nur über `gfbSyncEditorFormStylesheet`, WordPress **6.6+**, Aktivierung legt alle 2.0-Tabellen an, Radio-Doku).
- `includes/class-gfb-file-storage.php`: PHPDoc + `README-NGINX.txt`-Vorlage mit korrektem Nginx-`location` für `.gfb-private`.
- Sichtbare Bezeichnungen und Hinweise **Entwurf** statt „Draft“ (Formular, Editor-Inspector, `frontend.js`-Alerts, Kommentare).
- Plugin-Version **2.0.1** (Header, Konstante, Form-`block.json` → Cache-Busting für `editor.js` / `frontend.js` / CSS).

## [2.0.0] – 2026-05-09

**Hauptrelease: Verschlüsselung-by-default + Capability-Modell + Audit-Log.** Breaking change: Pflichtkonstanten in `wp-config.php`, neue DB-Tabellen, neue Caps. Setup-Anleitung in [`INSTALL.md`](INSTALL.md). Architektur und Bedrohungsmodell in [`SECURITY.md`](SECURITY.md).

### Hinzugefügt

- [`includes/class-gfb-crypto.php`](includes/class-gfb-crypto.php) — AES-256-GCM-Routinen mit AAD-Bindung, envelope encryption (KEK ⇒ wrapped DEK), Key-Generationen aus `GFB_MASTER_KEYS`, Re-Wrap-Helper.
- [`includes/class-gfb-file-storage.php`](includes/class-gfb-file-storage.php) — Datei-Anhänge werden in `wp-content/.gfb-private/gfb-encrypted/<yyyy>/<mm>/<storage-id>.bin` mit Modus `0600` und `.htaccess`/Nginx-Hinweis abgelegt; Metadaten in eigener Tabelle `wp_gfb_files`. Eigener Download-Endpoint (`admin-post.php?action=gfb_download&fid=…&_wpnonce=…`) mit Cap-Check, octet-stream-Erzwingung und Audit-Log.
- [`includes/class-gfb-capabilities.php`](includes/class-gfb-capabilities.php) — neue Caps: `gfb_view_submissions`, `gfb_decrypt_submissions`, `gfb_delete_submissions`, `gfb_download_files`, `gfb_view_audit`, `gfb_manage_settings`. Default-Mapping an `administrator`, Matrix in der Settings-Seite.
- [`includes/class-gfb-audit.php`](includes/class-gfb-audit.php) — tamper-evident Audit-Log mit SHA-256 Hash-Chain in `wp_gfb_audit`. Verifikations-Button in den Plugin-Einstellungen.
- [`includes/class-gfb-clamav.php`](includes/class-gfb-clamav.php) — Pflicht-Hook für Datei-Uploads. Modi `binary` (clamscan/clamdscan) und `socket` (clamd). EICAR-Selbsttest, Timeout, "Pflicht für Uploads"-Schalter.
- [`includes/class-gfb-admin-settings.php`](includes/class-gfb-admin-settings.php) — neue Settings-Seite mit Krypto-Status, ClamAV-Konfiguration, Cap-Matrix, Audit-Verifikation, Re-Wrap-Trigger, restriktiver CSP/`X-Content-Type-Options` für Plugin-Admin-Pages.
- [`includes/class-gfb-field-renderer.php`](includes/class-gfb-field-renderer.php) — alle Feld-Blöcke werden jetzt serverseitig gerendert (K3 Variante A). HTML-Output kommt zu 100 % aus PHP; `save()`-Output wird ignoriert.
- Block-Attribut `sensitive` (boolean) für alle Feld-Blöcke ausser dem Submit-Button. Editor-Toggle "Vertraulich (verschlüsselt speichern)". Felder mit `sensitive=true` werden vor dem Speichern via AES-256-GCM verschlüsselt; im Admin nur für Benutzer mit `gfb_decrypt_submissions` lesbar.
- [`INSTALL.md`](INSTALL.md) — komplette Setup-Anleitung inklusive `wp-config.php`-Konstanten, Webserver-Snippets, ClamAV-Setup, Cap-Verteilung, Key-Rotation und Backup-Hinweisen.
- WP-Cron `gfb_rewrap_cron` (stuendlich) re-verpackt alte DEKs nach Key-Rotation in Chargen.
- Selftest [`bin/security-selftest.sh`](bin/security-selftest.sh) erweitert um: privates Storage darf nicht web-erreichbar sein (TC5), Download-Endpoint ohne Anmeldung wird abgewiesen (TC6), Crypto-Roundtrip + AAD-Bindung (TC7).

### Geändert

- Submit-Pipeline: Crypto- und ClamAV-Vorbedingungen werden vor Validierung geprüft; bei nicht konfigurierter Krypto wird ein Form mit File-Feld oder `sensitive`-Feld vollständig abgelehnt (Status `err_crypto`). Files laufen nicht mehr durch `wp_handle_upload`, sondern direkt durch `GFB_File_Storage::encrypt_and_store()`.
- Mail-Notifications: verschlüsselte Werte landen NIE in der Mail. Stattdessen Hinweis "[verschlüsselt — bitte im Admin entschlüsseln]"; Datei-Anhänge erscheinen als "[verschlüsselte Datei #N — Download im Admin]".
- Admin-Submissions: Listen-Preview maskiert verschlüsselte Werte und Dateirefs; Detail-Ansicht entschlüsselt nur für Benutzer mit `gfb_decrypt_submissions` (und schreibt einen `submission_decrypted`-Audit-Eintrag); Datei-Spalte zeigt SHA-256-Fingerprint und Download-Link (mit Cap-Check).
- Aktivierung: legt zusätzlich `wp_gfb_files`, `wp_gfb_audit`, das private Storage-Verzeichnis und die Default-Caps an. Plant `gfb_rewrap_cron`.
- Plugin-Version 2.0.0; alle 16 Field-Block `version` 2.0.0; Form-Block 2.0.0.

### Sicherheit

- Datei-Inhalte verlassen `wp-content/uploads/` komplett. Direkter URL-Zugriff auf Uploads ist nicht mehr möglich, weil keine URLs mehr existieren.
- Datei-Inhalte und sensible Felder sind im DB- und im Filesystem-Backup nur Ciphertext (KEK liegt in `wp-config.php`, nicht in der DB).
- Stored-XSS-Pfad über `unfiltered_html`-Editoren bleibt durch dynamische Renderer geschlossen, auch ohne `wp_kses`-Bandage (die als zweite Schicht erhalten bleibt).
- Internal-Threat-Trennung durch Cap-Matrix: ein Konto kann Submissions sehen, ohne entschlüsseln oder herunterladen zu duerfen.
- Audit-Trail tamper-evident: jede Manipulation einer einzelnen Audit-Zeile fällt bei der Hash-Chain-Verifikation auf.

### Migrations-Hinweise

- `wp-config.php` braucht zwingend `GFB_MASTER_KEYS` und `GFB_ACTIVE_KEY_ID`. Vorgehen: siehe [`INSTALL.md`](INSTALL.md) Abschnitt 2.
- Bestandssubmissions aus 1.x bleiben unverändert (kein Rück-Encrypt). Neue Submissions werden gemaess Block-Attributen behandelt.
- Bestehende Forms im Editor: Editor zeigt evtl. einen einmaligen "Block wiederherstellen"-Hinweis, weil `save()` jetzt den dynamischen Renderer hat. Auf der Site selbst wird sofort der neue, sichere PHP-Output ausgespielt.

## [1.1.0] – 2026-05-09

**Sicherheits-Release** (siehe [`SECURITY.md`](SECURITY.md) für Threat-Model, Befunde und Konfigurationsempfehlungen).

### Hinzugefügt

- Neue Datei [`includes/class-gfb-security.php`](includes/class-gfb-security.php) bündelt Client-IP-Helfer mit Trusted-Proxy-Liste, HMAC-Anti-Replay-Token, deterministischen Honeypot-Feldnamen, Datei-Upload-Validation (MIME-Whitelist + finfo-Magic-Bytes + Doppel-Endung-Block), eigenes Upload-Verzeichnis `wp-content/uploads/gfb/` mit `.htaccess`/`index.html` und einen einheitlichen Security-Event-Hook `gfb_security_event`.
- DSGVO: `personal_data_exporter` und `personal_data_eraser` sind registriert. Filter `gfb_pseudonymize_ip`, `gfb_store_ip_pre`, `gfb_store_user_agent`.
- Verstecktes Feld: neues Attribut `lockedValue` (Toggle im Inspector). Wenn aktiv, ignoriert der Server jeden Browser-Wert und übernimmt den im Block hinterlegten Wert.
- Selbsttest-Skript [`bin/security-selftest.sh`](bin/security-selftest.sh) für lokale Smoke-Tests gegen die Reject-Pfade.

### Geändert

- Submit-Pipeline: Status-Slug-Whitelist (kein freier Notice-Text mehr aus URL-Parametern), HMAC-signierter Anti-Replay-Token statt Klartext-Timestamp, Honeypot-Feldname pro Form-Instanz, Mail-Notification mit `wp_strip_all_tags`/Limit/`text/plain`-Header.
- File-Upload: `accept`-Prüfung VOR `wp_handle_upload`, harte MIME-Whitelist, eindeutiger Dateiname `gfb_<random>.<ext>`, optionaler Post-Check via Filter `gfb_uploaded_file_post_check` (z. B. ClamAV).
- `render_form_block` filtert das gerenderte InnerBlock-HTML zusätzlich über eine restriktive `wp_kses`-Whitelist (Defense-in-Depth gegen Stored XSS bei `unfiltered_html`-Edit-Rechten); Filter `gfb_inner_blocks_allowed_html`.
- `sanitize_gfb_color()` blockt zusätzlich `;`, `\r`, `\n`, `\t`, `\0`; Max-Länge pro Wert auf 512 Zeichen.

### Sicherheit

- K1 (Datei-Upload-RCE), K2 (`accept`-Bypass), K3 (Stored-XSS-Pfad), H1 (Honeypot), H2 (IP-Trust-Boundary), H3 (Anti-Replay), H4 (Mail-Härtung), H5 (Reflected-Notice), M1 (DSGVO), M3 (CSS-Injection-Spielraum), M5 (Hidden-Manipulation) — alle behoben oder mit Hinweis dokumentiert. Vollständige Status-Tabelle in [`SECURITY.md`](SECURITY.md).

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
- Lokale Entwürfe (IndexedDB), optional Wiederherstellung / Entwurf löschen.
- **Erscheinungsbild:** Farbmodus (Theme, Auto, Hell, Dunkel), Formularfarben inkl. Verläufen; Editor-Canvas-Vorschau auch bei Theme-Stil mit `gfb-editor.css`.
- **Editor:** leeres Feld-`label` ohne Platzhalter in der Vorschau; verstecktes Feld mit editierbarem **Label (Hinweis)** für Bearbeiter und Eintragsdarstellung.

### Installation

- ZIP aus dem GitHub-Release laden, entpacken und den Ordner `gutenberg-formbuilder` nach `wp-content/plugins/` legen (oder per ZIP im WordPress-Admin **Plugins → Installieren** hochladen).
- Plugin aktivieren (legt die Tabelle `wp_gfb_submissions` an).

[1.0.0]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0
[1.0.0-beta.3]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/BlitzDonner/gutenberg-formbuilder/releases/tag/v1.0.0-beta.1
