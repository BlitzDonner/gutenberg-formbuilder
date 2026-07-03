# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Versionsnummern folgen [SemVer](https://semver.org/lang/de/); Vorab-Releases trugen das Suffix `-beta.N`.

## [2.9.3] – 2026-07-03

### Geändert

- **Plugin umbenannt in «Blitz & Donner Formular».** Der sichtbare Produktname erscheint neu überall als «Blitz & Donner Formular» – Plugin-Kopf, Admin-Hinweise, Datenschutz-Export und -Löschung, generierte Schutzdateien und die gesamte Dokumentation. Die Beschreibung nennt neu den «Block-Editor» statt «Gutenberg». Die technische Kennung bleibt bewusst unverändert (Ordner/Slug `gutenberg-formbuilder`, Textdomain, Block-Namen `gfb/*`, Datenbank-Tabellen, Präfixe `gfb_`), damit bestehende Installationen – Formulare, Einsendungen, Einstellungen und die Update-Verbindung – ohne Bruch weiterlaufen.

## [2.9.2] – 2026-07-03

### Behoben

- **ZIP-Export: Dateianhänge fehlten bei nachträglich geändertem Formular.** Der Einzel-Einsendungs- und der Formular-ZIP-Export fügten einen Dateianhang nur ein, wenn das Formular-Schema das Feld noch als Typ `file` führte. Wurde das zugehörige Formular nach der Einsendung geändert oder gelöscht, lieferte `get_form_schema_from_post()` ein Ersatz-Schema ohne Feld-Typen, und die Datei fiel aus dem ZIP – `eintrag.csv` und `eintrag.json` blieben vollständig, nur der Anhang fehlte. Die Datei-Erkennung erfolgt jetzt über die Datei-Referenz im Wert (`_ref` = `gfb-file:`) statt über den Schema-Typ, in `stream_zip` und `stream_zip_single`. Der einzelne Datei-Download in der Feldansicht (`handle_download`) war nie betroffen. Der Fehler bestand seit der Einführung des Einzel-Downloads in 2.9.0.

## [2.9.1] – 2026-07-02

### Sicherheit

- **Schwere Lücke geschlossen: Klartext-Datei-Download ohne Entschlüsselungs-Recht.** Der Datei-Download und beide ZIP-Export-Wege lieferten hochgeladene Dateien im Klartext aus, sobald ein Benutzer die Berechtigung «Dateien herunterladen» (`gfb_download_files`) hatte – **ohne** die Berechtigung «Entschlüsseln» (`gfb_decrypt_submissions`) zu verlangen. Eine Rolle, die sensible Textfelder nur maskiert sah, konnte so die zugehörige Datei entschlüsselt herunterladen (beobachtet mit einer Redaktions-Rolle auf einer Live-Seite). Die Dateien liegen weiterhin korrekt verschlüsselt (AES-256-GCM); die Lücke lag allein in der Autorisierung an der Ausgabe. **Fix (Defense in Depth):** Das Ausgeben einer Datei im Klartext gilt jetzt überall als Entschlüsselung und verlangt zwingend BEIDE Rechte – im Download-Endpunkt (`GFB_File_Storage::handle_download`), im Formular-ZIP-Export und im Einzel-Einsendungs-ZIP (`handle_export`, `handle_export_single`), am gemeinsamen Engpass `zip_cell_for_file` sowie in der Sichtbarkeit aller betroffenen Knöpfe. Ohne Entschlüsselungs-Recht wird kein Klartext mehr geliefert (HTTP 403), und Versuche werden protokolliert (`file_download_denied`, `file_export_denied`). **Empfehlung für Betreiber:** Rollen prüfen – wer «Dateien herunterladen» hat, sollte bewusst auch «Entschlüsseln» haben; andernfalls das Datei-Recht entziehen.

## [2.9.0] – 2026-07-02

### Geändert

- **Einstellungsseite: Rücksprung zur richtigen Karte statt an den Seitenanfang:** Nach jeder Aktion auf «Sicherheit & Einstellungen» (Speichern, EICAR-Test, Privatsphäre-Test, Token testen, Re-Wrap usw.) leitet die Seite jetzt mit Anker auf die Karte zurück, aus der die Aktion kam – vorher landete man verwirrend am Seitentitel. Jede Karte trägt dafür eine ID (`#gfb-clamav`, `#gfb-privatsphaere`, …); die Zuordnung Aktion→Anker steht in `maybe_handle_post()`. Die Bestätigungsmeldung wandert beim Ankersprung per kleinem Skript in die Ziel-Karte (ohne JavaScript bleibt sie oben – kein Funktionsverlust).
- **Einstellungsseite: Abstand in Ja/Nein-Auswahlgruppen:** WordPress-Core überschreibt Label-Abstände in `form-table`-Feldgruppen mit `!important` (forms.css) – dadurch klebten «Nein» und «Ja» aneinander. Die Plugin-Regel setzt den Abstand jetzt ebenfalls mit `!important` durch (`assets/admin-submissions.css`).
- **Seite «Sicherheit & Einstellungen» aufgeräumt (Karten-Layout):** Der Menüpunkt heisst neu «Sicherheit & Einstellungen» (vorher «Sicherheit»). Die Seite nutzt jetzt das Design-System der Einträge-Seite: jede Sektion (Verschlüsselung, Lizenz / Updates, ClamAV, CAPTCHA, Berechtigungen, Audit-Log, Privatsphäre-Test, Formular-Frontend, Key-Rotation) steht in einer eigenen Karte statt zwischen `<hr>`-Linien. Fliesstext auf lesbare Zeilenlänge begrenzt (46 rem), Text-/Passwort-Felder auf 26 rem, Zahlenfelder auf 7 rem; aufeinanderfolgende Formulare erhalten Trennabstand statt aneinanderzukleben; Aufklapp-Elemente (`details`) als dezente Blöcke. Knopf-Beschreibungen (EICAR-Test, Privatsphäre-Test, Token testen) stehen als Absatz unter dem Knopf statt daneben. Die Berechtigungs-Matrix passt ohne Quer-Scroller in die Kartenbreite (feste Spaltenaufteilung, Innenabstand links; technische Kennungen wie `gfb_view_submissions` und Rollen-Slugs aus der Anzeige entfernt – sie halfen nur Entwicklern, die Klarnamen mit Beschreibung genügen). Alle neun Karten sind auf-/zuklappbar (natives `details`/`summary`, Start zugeklappt): Pfeil-Indikator, Überschriften auf 18 px vergrössert, Aufklapp-Zustand wird pro Browser gemerkt (localStorage `gfbSettingsOpen`), der Anker-Rücksprung nach Aktionen öffnet die Ziel-Karte automatisch. Sechs Karten tragen in der Titelzeile eine Status-Etikette, damit der Zustand auch zugeklappt sichtbar ist: Verschlüsselung (Aktiv/Nicht aktiv), Lizenz (Token gültig/Per wp-config/Kein Token/Token ungültig/Server nicht erreichbar), ClamAV (Aktiv/Deaktiviert), CAPTCHA (Aktiv/Unvollständig konfiguriert/Deaktiviert), Privatsphäre-Test (Test bestanden/Handlungsbedarf/Nicht getestet), Formular-Frontend (Fallback aktiv/Native Picker); Farben: grün = an, grau = aus/neutral, orange = Achtung, rot = Handlungsbedarf (`summary_badge()`). Texte entschlackt: zwölf Beschreibungen gestrafft (Selbstverständliches wie «Häkchen setzen und speichern», «Klicke unten auf Jetzt prüfen» und Doppelwarnungen entfernt, Jargon wie DEK/KEK ersetzt), sieben Geviertstriche durch Halbgeviertstriche ersetzt. Die Audit-Log-Karte (nur ein Verweis) ist von der Seite entfernt – das Audit-Log hat einen eigenen Submenü-Punkt. Neuer Aufklapp-Block «Wie erhalte ich Site-Key und API-Key?» in der CAPTCHA-Karte: vier Schritte mit direkten Links auf die Dashboard-Seiten von Friendly Captcha (Applications, API Keys), verifiziert gegen die offizielle Anbieter-Dokumentation. Umsetzung: `assets/admin-submissions.css` (neuer Abschnitt `.gfb-settings`), Karten-Markup in `class-gfb-admin-settings.php`, CSS-Auslieferung auch auf der Einstellungsseite (`print_inline_admin_css`).

### Neu

- **Einzel-Download einer Einsendung als ZIP-Paket:** In der Einzelansicht einer Einsendung gibt es neu den Knopf «Diese Einsendung herunterladen (ZIP)». Das Paket enthält `eintrag.csv` (eine Datenzeile, gleiche Spalten-Logik und CSV-Härtung wie der Formular-Export), `eintrag.json` (dieselben Werte maschinenlesbar mit Feldnamen und Labels) und `dateien/…` (Anhänge entschlüsselt, Dateinamen bereinigt). Sichtbar nur mit Cap `gfb_download_files`; die Option «Feldwerte entschlüsseln» zusätzlich nur mit Cap `gfb_decrypt_submissions` – der Server prüft beide Berechtigungen unabhängig von der Oberfläche. Der Nonce ist an die Einsendungs-ID gebunden (`gfb_export_single_{id}`), fremde IDs scheitern. Neue Audit-Ereignisse `submission_export_single` und `submission_export_single_denied`; pro Anhang weiterhin `file_exported`, im Decrypt-Modus zusätzlich `submission_exported_decrypted`. Das ZIP entsteht im privaten Storage-Root und wird nach Auslieferung gelöscht (Shutdown-Fallback inklusive). Neuer Handler `handle_export_single()`/`stream_zip_single()` in `includes/class-gfb-admin-submissions.php`, registriert als `admin_post_gfb_export_single`.

## [2.8.1] – 2026-06-26

### Neu

- **Zweiter Signatur-Schlüssel im Update-Client:** Davids Ed25519-Public-Key in die Schlüsselliste des Update-Clients aufgenommen (`includes/class-bd-update-client.php`). Damit kann auch er Releases für `plugins.blitzdonner.ch` signieren; vom Client signiert akzeptierte Updates bleiben auf die hinterlegten Schlüssel beschränkt.

## [2.8.0] – 2026-06-26

### Neu

- **Submit-Overlay mit Verschlüsselungs-Animation:** Nach dem Absenden zeigt das Formular ein Overlay, das den Verschlüsselungs- und Übertragungsvorgang visualisiert (`assets/form.css`, `assets/frontend.js`, Rendering in `includes/class-gfb-plugin.php`). Spezifikation: `docs/SUBMIT-OVERLAY-SPEC.md`.
- **Core-Blöcke in Formularen:** Innerhalb des `gfb/form`-Blocks sind jetzt auch WordPress-Core-Blöcke erlaubt (Anpassung in `includes/class-gfb-field-renderer.php` und `includes/class-gfb-plugin.php`).

### Behoben

- **CSS-Klassen-Fix** im Feld-Rendering.

## [2.7.4] – 2026-06-26

Enthält auch die Änderungen der nicht separat veröffentlichten Version 2.7.3.

### Neu

- **Signierte Erstveröffentlichung auf `plugins.blitzdonner.ch`:** 2.7.4 ist das erste Release, das signiert über den eigenen Update-Server ausgeliefert wird.

### Geändert

- **Ed25519-Signaturprüfung als Pflicht im Update-Client:** Der Client akzeptiert nur noch Update-Pakete mit gültiger Ed25519-Signatur eines hinterlegten Schlüssels; die reine SHA-256-Prüfung genügt nicht mehr (`includes/class-bd-update-client.php`, ursprünglich als 2.7.3).
- **Auto-Publish via GitHub Actions entfernt:** Der Workflow `.github/workflows/publish-update.yml` veröffentlicht nicht mehr automatisch an den Update-Server, sondern dient nur noch als Qualitätsgate für Releases. Die Veröffentlichung erfolgt seither signiert ausserhalb von GitHub.

## [2.7.2] – 2026-06-22

### Neu

- **Update-Client für den selbst gehosteten Update-Server angebunden:** Neue Klasse `BD_Update_Client` (`includes/class-bd-update-client.php`) und Initialisierung in `gutenberg-formbuilder.php`. Das Plugin bezieht Updates ab sofort über die WordPress-Standard-Update-API vom Server `plugins.blitzdonner.ch` (Hooks `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_pre_download` mit SHA-256-Prüfung). Das Token wird pro Installation als wp-config-Konstante `GFB_UPDATE_TOKEN` gesetzt; bei abgelaufenem oder gesperrtem Token werden nur Updates verweigert (kein Killswitch, GPL-konform). Details siehe `docs/UPDATE-SERVER-INTEGRATION.md`.
- **Lizenz-Token im Backend hinterlegbar:** Neue Einstellungs-Sektion «Lizenz / Updates» auf `gfb-settings`. Das Token für die Updates von `plugins.blitzdonner.ch` lässt sich jetzt direkt im Backend eintragen (Option `gfb_update_token`) – Installationen ohne Zugriff auf `wp-config.php` können die automatischen Updates damit selbst aktivieren. Die Konstante `GFB_UPDATE_TOKEN` behält Vorrang. Passwort-Feld ohne Klartext-Ausgabe, «Token testen», Status-Box, Audit nur mit `token_set: yes/no`.
- **Auto-Publish via GitHub Actions:** Neuer Workflow `.github/workflows/publish-update.yml`. Ein veröffentlichtes reguläres Release (kein Pre-Release/Draft) baut das Plugin-ZIP nach Konvention und postet es an den Auto-Publish-Endpunkt von `plugins.blitzdonner.ch`. Vier Gates laufen vorab (php -l, Header-Version === Tag, ZIP-Struktur, SemVer); bei Fehler oder Server-Status ungleich 2xx bricht der Lauf ab. Das Deploy-Token kommt aus dem GitHub-Environment-Secret `BD_DEPLOY_TOKEN` und wird nie geloggt. Anleitung: `docs/AUTO-PUBLISH.md`. **Nächster Schritt (Mittelweg):** Ed25519-Signatur des ZIP – noch nicht enthalten.

### Behoben

- **Automatische Aktualisierung wurde in der Plugin-Liste nicht angeboten:** Der Update-Client wird jetzt früher angemeldet (`plugins_loaded` statt `init`), damit das Plugin rechtzeitig im `update_plugins`-Transient erscheint. Dadurch zeigt die Plugin-Übersicht die Schaltung «Automatische Aktualisierungen aktivieren» und die «neue Version verfügbar»-Zeile. Zusätzlich das `id`-Feld in den Update-Einträgen ergänzt.

### Geändert

- **Anbieter im Plugin-Kopf:** «Von ClaudeStation» ersetzt durch «Von Blitz & Donner»; Author URI und Plugin URI verweisen auf `https://plugins.blitzdonner.ch`.

## [2.7.0] – 2026-06-14

### Neu

- **Spam-Schutz mit Friendly Captcha (CAPTCHA-Integration):** Das Plugin unterstützt jetzt Friendly Captcha als datenschutzfreundlichen EU-Spam-Schutz. Friendly Captcha arbeitet mit Proof-of-Work, ohne Cookies, ohne Fingerprint und ohne Drittlandtransfer. Es ist der einzige Anbieter – keine Anbieterwahl, kein zweiter Block.
  - **Neue Klasse `GFB_Captcha`** (`includes/class-gfb-captcha.php`): kapselt Settings, Wirksamkeit pro Formular, Widget-Rendering und serverseitige Verifikation – analog zum bestehenden `GFB_Clamav`-Muster. Ein zweiter Anbieter liesse sich später hinter `verify()`/`render_widget()` nachrüsten, ohne den Submit-Fluss umzubauen.
  - **Admin-Abschnitt «Spam-Schutz (CAPTCHA)»** auf der Seite «Sicherheit & Einstellungen», eingeordnet zwischen ClamAV und Berechtigungen: globale An/Aus-Schaltung, Site-Key und API-Key (Secret, serverseitig), Erzwingungsmodus «Mit Ausnahme bei Serverausfall»/«Streng», Option «ohne Consent laden» sowie ein schlanker Datenschutz-Hinweis mit kopierbarem Textbaustein.
  - **Geltungsbereich pro Formular:** neues Block-Attribut `captchaMode` am `gfb/form`-Block («Von globaler Einstellung übernehmen / Immer an / Immer aus»).
  - **Verifikation als 5. Stufe der Abwehrkette** in `GFB_Submit_Handler::handle()`, nach dem Rate-Limit und vor der Schema-/Feldverarbeitung. Reihenfolge: Nonce → HMAC → Honeypot → Rate-Limit → CAPTCHA → Felder. Der bestehende Schutz bleibt unangetastet.
  - **Datenschutz by default:** Das Friendly-Captcha-Skript lädt nie eager, sondern erst nach erster Formular-Interaktion oder nach Consent-Freigabe (`assets/captcha.js`). Kein Vorab-Ping. Nur der Site-Key steht im Frontend; das Secret bleibt serverseitig. Consent-Anbindung über den PHP-Filter `gfb_captcha_consent_granted` und das dokumentierte JS-Event `gfb-captcha-consent`.
  - **Erzwingungsmodus:** Beide Modi verlangen grundsätzlich ein bestandenes CAPTCHA – fehlendes oder ungültiges Token wird in beiden Fällen abgewiesen. Der Unterschied liegt allein beim nicht erreichbaren Anbieter: «Mit Ausnahme bei Serverausfall» (Default) lässt den Submit dann als Ausfallsicherung durch; «Streng» blockiert auch in diesem Fall (fail-closed).
  - **Audit und Security-Events:** jede Konfigurationsänderung und jeder Verifikationsausgang wird protokolliert (`captcha_pass`, `captcha_fail`, `captcha_unreachable`) – ohne Secret im Klartext und ohne rohes Token. Fehlendes Token erzeugt jetzt einen `captcha_fail`-Reject-Event (vorher im weichen Modus ein Skip-Event).
  - **siteverify-Endpoint** (verifiziert gegen die offizielle Anbieter-Doku, v2): `POST https://global.frcapi.com/api/v2/captcha/siteverify`, Authentifizierung über den Header `X-API-Key`, Timeout 5 Sekunden.
  - **i18n:** alle neuen Strings übersetzbar (de/en/fr/it).
  - **Erweiterte Datenschutz-Bausteine für den consent-losen Standardbetrieb:** Der Standardbetrieb verzichtet auf einen Einwilligungsschritt für Besucher (Rechtsgrundlage berechtigtes Interesse, Lazy-Load bleibt). Dafür trägt der Admin-Abschnitt jetzt zwei kopierbare Textbausteine und einen erweiterten Datenschutz-Hinweis.
    - **Vollständiger Datenschutz-Textbaustein** (`GFB_Captcha::privacy_text_snippet()`): löst die schlanke Drei-Punkte-Fassung ab und deckt alle elf Pflicht-Inhalte (Anbieter, Zweck, Datenart, Funktionsweise, Rechtsgrundlage, Speicherort EU, AVV, Speicherdauer, Widerspruchsrecht, Betroffenenrechte, Kennzeichnung als unverbindliche Vorlage). Firmenadresse und konkrete Speicherdauer bleiben als Platzhalter `[…]` – das Plugin erfindet sie nicht.
    - **LIA-Vorlage** (`GFB_Captcha::lia_text_snippet()`): zweiter kopierbarer Baustein, ein interner Vermerk zur Interessenabwägung (Zweck, Erforderlichkeit, Abwägung, Ergebnis) mit Platzhaltern für Datum und verantwortliche Person.
    - **Übersichtliche Darstellung:** Die langen Rechtstexte stecken in eingeklappten Aufklapp-Elementen mit kurzer Zusammenfassungszeile; je ein Knopf «In die Zwischenablage kopieren». Die sichtbaren Beschriftungen sind laienverständlich gehalten («Vorlage für die Datenschutzerklärung anzeigen», «Interner Vermerk zur Rechtsgrundlage (Interessenabwägung) anzeigen»).
    - **Erweiterter Admin-Hinweis:** informativer Block (kein nicht-wegklickbarer Warnblock) mit AVV-Pflicht, LIA-Empfehlung und dem Hinweis, dass im Standardbetrieb keine Besucher-Einwilligung nötig ist, die Datenschutzerklärung aber den vollständigen Baustein tragen muss.
    - Alle neuen Strings inklusive der beiden Bausteine sind übersetzbar (de/en/fr/it).

### Geändert (consent-loser Standardbetrieb)

- **Erzwingungslogik vereinheitlicht:** Im weichen Modus liess ein fehlendes oder ungültiges Token den Submit bisher durch (fail-open auf alles). Neu verlangen beide Modi ein bestandenes CAPTCHA; fehlendes oder ungültiges Token wird in beiden Fällen abgewiesen (`STATUS_ERR_CAPTCHA`). Der einzige Unterschied bleibt das Verhalten bei nicht erreichbarem Anbieter: «Mit Ausnahme bei Serverausfall» lässt dann durch, «Streng» blockiert (`includes/class-gfb-submit-handler.php`).
- **Beschriftungen angepasst:** Modus 1 heisst neu «Mit Ausnahme bei Serverausfall» (vorher «Weich (empfohlen)»), Modus 2 bleibt «Streng». Die Erklärtexte beschreiben jetzt das Verfügbarkeitsverhalten statt das Koppelungsverbot (`includes/class-gfb-admin-settings.php`).
- **Koppelungsverbot-Hinweise entfernt:** Der Zusatz «(Koppelungsverbot beachtet)» und die Warnung «Kann mit dem Koppelungsverbot kollidieren» sind im consent-losen Standardbetrieb gegenstandslos und entfallen.
- **Strict-Quittung entfernt:** Die einmalige Pflicht-Quittung zum Koppelungsverbot beim Umschalten auf «Streng» entfällt samt zugehörigem Speicher-Check; das Schema-Feld `strict_ack` bleibt ohne Funktion erhalten (keine Migration).
- **i18n:** Geänderte Strings angeglichen, verwaiste msgids (Koppelungsverbot, Quittung) in de/en/fr/it bereinigt, `.mo` neu kompiliert.
- **ClamAV-Abschnitt platzsparender dargestellt:** Der lange Erklärungs- und Anleitungsteil («Was ist das?», «Optional.», «In 3 Schritten zum Virenscan», die vier Plattform-Anleitungen und «Hilfe bei Fehlern») steckt jetzt in einem übergeordneten, standardmässig eingeklappten Aufklapp-Element (natives `<details>`, gleiches Muster wie die CAPTCHA-Datenschutz-Bausteine). Sichtbar bleibt nur die Zeile «Hilfe und Installationsanleitung für ClamAV anzeigen». Die Status-Zeile «Status auf diesem Server» bleibt handlungsrelevant ausserhalb des Akkordeons stehen (`includes/class-gfb-admin-settings.php`).
- **ClamAV-Konfigurationsfelder nur bei aktivem Modus:** Pfad, Socket-Pfad, Timeout und «Pflicht für Datei-Uploads» werden per JavaScript ausgeblendet, solange der Modus auf «Deaktiviert» steht, und beim Umschalten auf «Binary»/«Socket» sofort eingeblendet. Die Felder bleiben immer im DOM, das Speichern ändert sich nicht; ohne JavaScript bleiben die Felder sichtbar (`includes/class-gfb-admin-settings.php`).
- **Typografie korrigiert:** Im berührten ClamAV-Abschnitt die als Gedankenstrich verwendeten Bindestriche durch Halbgeviertstriche «–» ersetzt.

### Entfernt (Einwilligungs-Mechanismus vollständig)

- **Consent-Mechanismus restlos entfernt:** Im consent-losen Standardbetrieb war der Einwilligungs-Mechanismus gegenstandslos. Entfernt sind die Admin-Checkbox «Friendly Captcha ohne Consent laden» samt Erklärtext und Tabellenzeile «Consent-Gate», die Einstellung `load_without_consent` aus Defaults, `get_settings()`, `update_settings()` und dem `save_captcha`-Handler (keine Migration), die PHP-Methoden `GFB_Captcha::consent_gate_active()` und `consent_granted()` samt dem Filter `gfb_captcha_consent_granted`, die Frontend-Attribute `data-gfb-consent-gate`/`data-gfb-consent-granted` am Widget sowie das JavaScript-Event `gfb-captcha-consent` und die gesamte Consent-Prüflogik in `assets/captcha.js` (`includes/class-gfb-captcha.php`, `includes/class-gfb-admin-settings.php`, `assets/captcha.js`).
- **Audit/Event-Kontext bereinigt:** Der Schlüssel `consent_gate` fällt aus dem Audit-Record `settings_captcha_saved` und aus dem `$base_ctx` der CAPTCHA-Events/-Audits weg; der übrige Kontext (Form-Id, Post-Id, Modus, Ergebnis) bleibt unverändert (`includes/class-gfb-submit-handler.php`, `includes/class-gfb-admin-settings.php`).
- **Reserve-Feld `strict_ack` entfernt:** Das funktionslose Schema-Feld der nie umgesetzten In-Form-Einwilligung (Spec-Abschnitt 7) ist aus dem Settings-Schema und dem Speicher-Pfad entfernt (keine Migration).
- **Verzögertes Laden bleibt, von Einwilligung entkoppelt:** Das Friendly-Captcha-Skript lädt weiterhin erst nach der ersten Formular-Interaktion (Feld-Fokus/Klick/Eingabe) – jetzt allein zur Datensparsamkeit (kein Vorab-Ping, Kriterium B2), ohne jede Consent-Prüfung. Funktionen, Variablen und Kommentare sind neutral benannt («verzögertes Laden zur Datensparsamkeit») (`assets/captcha.js`).
- **Datenschutz-Bereich als Akkordeon:** Der gesamte Datenschutz-Block im CAPTCHA-Abschnitt (Hinweisliste «Was Sie zum Datenschutz wissen sollten» plus die beiden kopierbaren Bausteine) steckt jetzt in einem übergeordneten, standardmässig eingeklappten Aufklapp-Element (natives `<details>`, gleiches Muster wie der ClamAV-Hilfe-Block). Sichtbar bleibt nur die Zeile «Datenschutz-Hinweise und Textbausteine anzeigen»; die beiden Bausteine bleiben als verschachtelte `<details>` mit ihren Kopier-Schaltflächen erhalten, ihr Inhalt ist unverändert (`includes/class-gfb-admin-settings.php`).
- **i18n:** Verwaiste msgids (Consent-Gate, «ohne Consent laden», Consent-Gate-Erklärtext) in de/en/fr/it bereinigt, neue Zusammenfassungszeile «Datenschutz-Hinweise und Textbausteine anzeigen» als msgid ergänzt, `.mo` neu kompiliert.

## [2.6.6] – 2026-06-09

### Behoben

- **Erfolgs-Platzhalter bei deaktivierten Entwürfen:** Wenn am Formularblock «Entwürfe speichern» aus war (`draftEnabled: false`), wurde der Submit-Snapshot für `{{feldname}}` in `sessionStorage` nicht angelegt – Platzhalter in `gfb/form-success` blieben nach dem Absenden leer. Der Snapshot-Listener wird jetzt unabhängig von der Entwurf-Einstellung registriert (`assets/frontend.js`).

## [2.6.5] – 2026-06-09

### Behoben

- **Block-Validation-Fehler `gfb/field-hidden`:** Die `save()`-Funktion gab statisches HTML mit `value=""` zurück, während PHP den tatsächlichen Wert (`hiddenValue`) dynamisch via `render_callback` einbettete. Der Mismatch löste bei jedem Öffnen eines Posts einen Gutenberg-Validation-Fehler aus. `save()` gibt jetzt `null` zurück (korrektes Verhalten für dynamische Blöcke). Das alte statische HTML ist als `deprecated`-Eintrag registriert, damit bestehende Posts valide bleiben.
- **Block-Validation-Fehler `gfb/field-radio`:** Die `save()`-Funktion splittete `a.options` nur auf `\n`. Wenn der Block-Comment-Wert korrumpiert war (echte Newlines durch den Buchstaben `n` ersetzt), lieferte das Split ein einzelnes Element, was zu einem langen, zusammengeklebten `value`-Attribut führte. `save()` gibt jetzt ebenfalls `null` zurück. Das deprecated-Objekt splittet robuster auf `\r\n|\r|\n`, um bestehende Posts korrekt zu migrieren.

## [2.6.4] – 2026-06-04

### Geändert

- **Sprechendere Feldname-Beschriftung im Block-Editor:** Das Control heisst neu «Eindeutiger Feldname» (vorher «Technischer Feldname»). Der erläuternde Hilfetext («POST-Schlüssel; innerhalb dieses Formulars eindeutig …») entfällt.
- **Klarere Beschriftung des Vertraulich-Schalters:** Der Schalter heisst neu schlicht «Vertraulich» (vorher «Vertraulich (verschlüsselt speichern)»). Neuer Hilfetext: «Feldwert kann nur mit entsprechender Berechtigung eingesehen werden. Das gilt nicht für den Versand per Mail, da ist alles zu sehen.»
- **Platzhalter-Feld eingedeutscht:** Das Editor-Control für den Eingabe-Platzhalter trägt neu das deutsche Label «Platzhalter» (vorher englisch «Placeholder») – als übersetzbarer String passend zur Sprache ausgegeben.
- **Farb-Verwaltung auf Form-Ebene zentralisiert:** Farben werden nur noch am Formular eingestellt («Formularfarben Hell/Dunkel»); alle Felder erben sie. Die beiden frontend-wirkungslosen Farb-/Stil-UIs auf Feldern wurden entfernt: der native «Stil»-Tab (Farbe, Typografie, Abstand, Rahmen – alle Supports aus den `field-*`-Blöcken) und das tote Panel «Farben (Feld überschreiben)». Das verhindert die bisherige doppelte und irreführende Farbeingabe.
- **Theme-Modus ohne Farb-Controls:** Im Farbmodus «Theme (Standard)» sind die Panels «Formularfarben Hell/Dunkel» ausgeblendet – das Aussehen kommt vollständig aus dem WordPress-Theme. Eigene Formularfarben gibt es nur in den Modi Hell, Dunkel und Automatisch. Hinweistext des Farbmodus-Selects entsprechend angepasst.
- **Blöcke sprechender benannt:** «Erfolgsbereich» heisst neu «Rückmeldung», «Platzhalter-Hilfe» heisst neu «Rückmeldungsfelder». Die Block-Titel sind in Englisch, Französisch und Italienisch übersetzt (Confirmation / Conferma usw.). Interne Block-Namen, CSS-Klassen und gespeicherte Formulare bleiben unverändert.

### Behoben

- **Doppeltes Feldname-Control im Block-Editor:** Bei acht Feldtypen (Auswahlfeld, Zahl, Datum, Uhrzeit, Datum und Uhrzeit, Radio-Auswahl, Schieberegler, Datei-Upload) erschien das Control «Technischer Feldname» im Inspector zweimal untereinander. Ursache war ein doppelter Aufruf der Komponente `GfbFieldNameInspector` je Block; der überzählige Aufruf wurde entfernt. Das Control erscheint nun – wie bei den übrigen Feldtypen – genau einmal.

## [2.6.3] – 2026-06-04

### Hinzugefügt

- **Audit-Log-Ansicht im Admin** (Menü «Formular-Einträge → Audit-Log», Berechtigung `gfb_view_audit`): seitenweise Liste aller Ereignisse mit verständlichen Aktionsnamen (z. B. «Einsendungen exportiert», «Datei heruntergeladen»), Akteur (Login + IP), Ziel und lesbar formatierten Details. Die Hash-Chain-Integritätsprüfung ist integriert. Neue Datei `includes/class-gfb-admin-audit.php`; auf der Sicherheits-Seite ersetzt ein Verweis den bisherigen Prüf-Block.
- **Pflichtfeld-Kennzeichnung:** Pflichtfelder tragen ein Sternchen «*» in der Akzentfarbe – an Label, Radio-Legende und Checkbox-Label, identisch in Frontend und Block-Editor.
- **Datei vor dem Versand entfernen:** Sobald eine Datei gewählt ist, erscheint pro Datei-Feld ein «Entfernen»-Link (rechtsbündig in der Hinweiszeile), der nur dieses eine Feld leert.

### Geändert

- **Erscheinungs-Stile vollständig neu aufgebaut.** Die Modi **Hell, Dunkel, Automatik** sind jetzt komplett von Theme- und externem CSS isoliert (harter Reset, feste Apple-artige Paletten, selbst gezeichnete Checkbox/Radio/Range/Select/Datei-Button) und sehen im Block-Editor-Canvas und im Frontend identisch aus (gleiche Abstände, line-height, Borders, Radien). Der Modus **«Theme»** bleibt bewusst ohne Plugin-Stil und übernimmt das Website-Theme. Feldbreiten einheitlich; Block-Auswahl im Editor wieder pro Einzelfeld.
- **Berechtigungs-Matrix verständlich beschriftet:** Die Spaltenüberschriften zeigen einen Klartext-Titel plus Beschreibung (was die Rolle damit darf), der technische Capability-Name bleibt klein als Referenz.
- **«verschlüsselt»-Pille** sitzt rechtsbündig auf der Label-Zeile statt darunter.
- **Audit-Kontext beim Export erweitert** (`decrypt_mode`, `fields_decrypted`, `format`): Ein Export im Entschlüsseln-Modus wird zuverlässig protokolliert, auch wenn das Formular keine verschlüsselten Felder hat.

### Behoben

- Datei-Auswahl-Button: weisse Schrift auf der Akzentfläche (zuvor schwarz/unleserlich).
- Range-Slider: Knopf vertikal korrekt auf dem Track zentriert.

## [2.5.0] – 2026-06-03

### Hinzugefügt

- **CSV-Export der Formular-Einsendungen:** Auf der Admin-Seite «Formular-Einträge» erscheint eine Export-Box, sobald ein einzelnes Formular gefiltert ist. Ein Klick auf «Als CSV exportieren» lädt alle Datensätze des Formulars (ungepaginiert) als CSV-Datei herunter. Trennzeichen ist Semikolon `;` (DACH-Standard für Excel), Zeichensatz UTF-8 mit BOM.
- **Spalten:** Metaspalten (`id`, `created_at`, `post_id`, `form_id`, `form_title`) plus alle Felder des Formular-Schemas in Schema-Reihenfolge. Spaltenüberschriften sind die Feld-Labels aus dem Schema. Die Spalte `ip_address` erscheint **ausschliesslich**, wenn der Export tatsächlich entschlüsselt wird (Cap `gfb_decrypt_submissions` vorhanden und Option «Entschlüsseln» aktiviert).
- **Verschlüsselte Felder:** Standardmässig als `[verschlüsselt]` maskiert. Nur Nutzer mit Cap `gfb_decrypt_submissions` sehen die Entschlüsseln-Option; ist sie aktiv, erscheinen Klartextwerte. Schlägt die Entschlüsselung fehl, steht `[Entschlüsselung fehlgeschlagen]`.
- **Mehrwert-Felder** (Mehrfachauswahl, Arrays) werden als kommaseparierte Liste in einer Zelle ausgegeben.
- **Datei-Felder** erscheinen im reinen CSV-Export als `Dateiname (Grösse) sha256:<fingerprint>` (keine Binärdaten).
- **ZIP-Export (CSV + hochgeladene Dateien):** Zweiter Button «CSV + Dateien (ZIP)» neben «Als CSV exportieren». Er erscheint nur, wenn das Formular mindestens ein Datei-Feld hat, der Benutzer die Cap `gfb_download_files` besitzt und die PHP-Erweiterung `ZipArchive` verfügbar ist (sonst dezenter Hinweis). Das Archiv enthält `eintraege.csv` und einen Ordner `dateien/`. Pro Einsendung ein Unterordner, benannt nach der Absender-E-Mail (`dateien/<email>/`); bei mehreren Einsendungen derselben Adresse mit Suffix `-2`, `-3`; ohne lesbare E-Mail `eintrag-<id>`. Jede Datei wird aus dem verschlüsselten Storage in Klartext entpackt und unter ihrem Originalnamen abgelegt; die Datei-Zelle der CSV enthält statt des Fingerprints den relativen ZIP-Pfad, sodass jede Datei eindeutig ihrer Zeile und ihrem Feld zugeordnet ist.
- **Gemeinsame Export-Box:** Eine einzige «Felder entschlüsseln»-Option (protokolliert) gilt für beide Buttons; beide laufen über die Action `gfb_export` (Parameter `gfb_export_kind` = `csv`|`zip`).

### Geändert

- **Audit-Kontext erweitert:** `submission_exported` enthält jetzt `{count, decrypt_mode, fields_decrypted, format}` (`format` = `csv` oder `zip`, bei ZIP zusätzlich `files_included`) statt des früheren einfachen Decrypt-Flags.

### Sicherheit

- **CSV-Injection-Härtung:** Jede Zelle wird vor dem Schreiben geprüft; beginnt der Wert mit `=`, `+`, `-`, `@`, Tab oder Carriage-Return, wird ein Hochkomma `'` vorangestellt.
- **Audit-Einträge:** Jeder Export schreibt `submission_exported`. `submission_exported_decrypted` wird geschrieben, sobald der Entschlüsseln-Modus aktiv und berechtigt war – unabhängig davon, ob das Formular verschlüsselte Felder enthält. So ist im Protokoll erkennbar, dass ein Export potenziell Klartext und die IP-Adresse enthielt. ZIP-Exporte schreiben zusätzlich pro Datei einen `file_exported`-Eintrag (Datei-ID, sha256, Formular- und Einsendungs-ID). Abgebrochene Exporte (kein Login, fehlende Cap, ungültige Nonce, kein Formular, fehlendes `ZipArchive`) schreiben `submission_export_denied` mit Grund.
- **Datei-Inhalte im ZIP:** Entschlüsselter Klartext verlässt das private Storage nur für Nutzer mit `gfb_download_files` und wird nach dem Schreiben aus dem Speicher entfernt. Das temporäre Archiv wird ausserhalb der Web-Wurzel (`.gfb-private`) erzeugt und nach der Auslieferung zwingend gelöscht – auch im Fehlerfall (`register_shutdown_function`).
- **Pfad-Sicherheit:** Ordner- und Dateinamen im ZIP werden bereinigt (Zeichen-Whitelist, Pfad-Traversal `..`/`/`/`\` ausgeschlossen, Längenbegrenzung, Endungserhalt).
- **Serverseitige Durchsetzung:** Ist die Decrypt-Option im Request gesetzt, aber die Cap `gfb_decrypt_submissions` fehlt, wird maskiert exportiert – die Option wird ignoriert, kein Fehler.
- **Export «Alle Formulare» gesperrt:** Verschiedene Formulare haben unterschiedliche Schemas; ein Misch-Export wird nicht unterstützt.

## [2.4.1] – 2026-05-20

### Hinzugefügt

- **E-Mail-Benachrichtigung — eigener Absender:** Neben Admin-E-Mail und E-Mail-Feld der Einsendung kann im Inspector **Eigene E-Mail-Adresse** gewählt werden (`emailFromCustom`, Modus `gfb_custom_sender` in `emailFromField`). Adresse nur aus Block-Attributen, nicht aus POST. Doku: [`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md).

## [2.4.0] – 2026-05-19

### Hinzugefügt

- **Technische Feldnamen:** Im Inspector editierbar (`GfbFieldNameInspector`); bleiben über Speichern stabil (nicht mehr bei jedem Reload neu aus `clientId`). Neues oder dupliziertes Feld → einmaliger Vorschlag aus Label (eindeutig im Formular). Attribut `nameClientId` bindet den Namen an die Block-Instanz; Duplikat im Editor → Hinweis, serverseitig `err_duplicate`.
- **Formular-ID (`formId`):** Pro Block-Instanz stabil; bei Duplizieren/Einfügen des Formulars vergibt `syncFormInstance` eine neue ID. Submit, Schema, Admin-Filter und Payload-Auswertung (`get_submission_payload_for_row()` nach `form_id`) nutzen diese ID.
- **Datum / Uhrzeit / Termin (Frontend):** `pattern` (Regex) und `placeholder` (Anzeigemaske, z. B. `d.m.Y` → `dd.mm.yyyy`) aus **Einstellungen → Allgemein** (`date_format`, `time_format`) in `includes/class-gfb-field-renderer.php`; nur wenn kein eigener Block-Platzhalter gesetzt ist.
- **Admin → Sicherheit → Formular (Frontend):** Option **Safari/WebKit Text-Fallback** (Standard **an**): ersetzt `date` / `time` / `datetime-local` durch Textfelder mit `pattern`/`placeholder` aus den WP-Formaten. Abschaltbar → native Browser-Picker. Option `gfb_webkit_datetime_fallback`; Filter `gfb_webkit_datetime_fallback`; Steuerung per `gfbFrontendConfig.webkitDateTimeFallback` (`'1'`/`'0'`) und `data-gfb-webkit-datetime-fallback` am `<form>`.

### Geändert

- **Safari WebKit-Fallback** (`assets/frontend.js`): übernimmt `pattern` und `placeholder` vom Server; `maxLength` an die Maske angepasst; `title` = `placeholder` für Validierungsmeldungen.
- Leere Datums-/Zeitfelder ohne serverseitigen Voreinstellungs-Wert: Safaris Anzeige „heute“ wird nicht mehr als `value` in Text-Fallback-Felder übernommen (`data-gfb-has-default` am Input).

### Behoben

- Deaktivierter WebKit-Fallback wirkte nicht: `wp_localize_script` castet Booleans zu Strings (`false` → `""`), der JS-Check auf `=== false` griff nicht — Konfiguration jetzt explizit `'1'`/`'0'`.

## [2.3.0] – 2026-05-19

### Hinzugefügt

- **E-Mail-Benachrichtigung** am Block `gfb/form` (Inspector-Panel): Schalter **E-Mail nach Absenden senden** (Standard aus). **Empfänger** als `FormTokenField` mit E-Mail-Validierung pro Token; Admin-E-Mail als Voreinstellung beim Anlegen des Formulars und erneut nach Verlassen des Blocks bei leerem Feld (`gfbEditorAssets.adminEmail`); ohne Tokens beim Versand Fallback Admin-E-Mail (max. 10 Empfänger). Optionaler **Betreff** und **Absendername** mit Platzhaltern `{{feldname}}` / `{{label_feldname}}`; Standardbetreff nutzt bei leerem Feld den **Anzeigenamen** (`formTitle`), falls gesetzt. **Absender-E-Mail:** Admin oder Wert aus einem `gfb/field-email` der Einsendung. Block-Attribute: `emailNotificationEnabled`, `emailRecipients`, `emailSubject`, `emailFromField`, `emailFromName`. Server: `GFB_Submit_Handler::send_notification_mail()` — nur Block-Attribute, `text/plain`, keine entschlüsselten sensiblen Werte in der Mail. Doku: [`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md); Kurzüberblick in [`README.md`](README.md#formular-e-mail-benachrichtigung) und [`INSTALL.md`](INSTALL.md#12-e-mail-benachrichtigung-redaktion).

## [2.2.0] – 2026-05-10

### Hinzugefügt

- **Formular-Anzeigename** (`formTitle` am Block `gfb/form`): optional im Editor; wird bei Einsendungen in der Tabelle `form_title` gespeichert (serverseitig aus Block-Attributen, nicht aus POST). Datenbank-Upgrade `gfb_submissions_db_version` 2 inkl. Spalte `form_title`.
- **Admin „Formular-Einträge“:** Filter nach `form_id`, Sortierung (Datum / Formularname), Spalte **Formular** mit Name und technischer ID; **Absender** statt Kurzüberblick: E-Mail und Vor-/Nachname per Feldnamen-Heuristik (bzw. Platzhalter bei Verschlüsselung).
- **Datenexport (DSGVO):** Eintrag enthält Feld **Formularname** (`form_title`).

## [2.1.4] – 2026-05-10

### Geändert

- **Platzhalter-Hilfe** (`gfb/token`): Auswahlliste zeigt die **technischen Feldnamen** (POST-`name`); die Auswahl fügt den **Wert-Platzhalter** `{{feldname}}` ein, nicht mehr `{{label_…}}`.

## [2.1.3] – 2026-05-10

### Behoben

- **Erfolgs-Platzhalter:** `assets/frontend.js` initialisiert zuverlässig auch bei **defer**/spätem Skript-Laden (`DOMContentLoaded` bereits vorbei). Platzhalter-Ersetzung läuft **vor** IndexedDB und **vor** dem URL-Strip; Submit-Snapshot in der **Capture-Phase**; etwas robustere Zuordnung (`data-gfb-await-tokens` ohne striktes `=1`, Kleinbuchstaben für Form-ID und Snapshot-Keys).

## [2.1.2] – 2026-05-10

### Geändert

- **Platzhalter-Hilfe** (`gfb/token`): nur noch ein **Auswahlfeld** mit den Feldbezeichnungen; nach der Wahl wird der passende Platzhalter `{{label_feldname}}` an dieser Stelle als **Absatz** eingefügt (der Hilfsblock entfällt).

## [2.1.1] – 2026-05-10

### Hinzugefügt

- Block **Platzhalter-Hilfe** (`gfb/token`), nur im **Erfolgsbereich**: listet alle Formularfelder (ohne Absenden) mit kopierbaren Platzhaltern `{{feldname}}` und `{{label_feldname}}` aus der aktuellen Gutenberg-Struktur des Formulars. Im Frontend wird nichts ausgegeben.

## [2.1.0] – 2026-05-10

### Hinzugefügt

- Block **Erfolgsbereich** (`gfb/form-success`) nur innerhalb von `gfb/form`: freier InnerBlocks-Inhalt, der nach erfolgreichem Absenden **ohne gewählte Folgeseite** anstelle des Formulars gerendert wird. Platzhalter im Text: `{{feldname}}` (POST-Name), optional `{{label_feldname}}` (Feldbezeichnung). Werte kommen aus einem **sessionStorage**-Snapshot beim Absenden (`assets/frontend.js`); Datei-Felder liefern den Platzhalter `[Datei]`. Schema/Validierung ignoriert den Erfolgsbereich.

### Geändert

- `gfb/form`-Frontend: KSES für den Erfolgsbereich orientiert sich an typischem Beitrags-HTML (`wp_kses_allowed_html('post')` minus `script`/`style`).

## [2.0.9] – 2026-05-10

### Geändert

- Nach Submit bleiben `gfb_status` / `gfb_form` / … nicht mehr dauerhaft in der URL: `assets/frontend.js` entfernt diese Parameter per `history.replaceState` nach der Formular-Initialisierung (Reload = leeres Formular ohne Hinweis). **Entwurf löschen** entfernt zusätzlich die Notice im Wrapper und bereinigt die URL.

## [2.0.8] – 2026-05-10

### Geändert

- **Safari / WebKit (ohne Blink):** `assets/frontend.js` ersetzt `date` / `time` / `datetime-local` durch formatierte **Textfelder** mit `pattern`, `placeholder` und `maxLength` (Chrome, Firefox, Edge, Opera, Chrome iOS bleiben bei nativen Pickern). Entwurf-löschen leert diese Fallback-Felder ebenfalls.

## [2.0.7] – 2026-05-10

### Hinzugefügt

- Blöcke **Datum**, **Uhrzeit**, **Termin** (`datetime-local`): optionales Attribut **Voreingestellter Wert** im Inspector; leer = kein HTML-`value` (Feld startet leer). Gültige Formate werden serverseitig gefiltert.

### Geändert

- **Entwurf löschen:** Nach `form.reset()` werden `input[type=date|time|datetime-local]` explizit geleert (kein hängender Entwurfs-/Browserzustand).
- **Datum (Frontend):** `min`/`max` aus den Block-Attributen werden im PHP-Renderer mit ausgegeben (wie schon im Editor-Save).

## [2.0.6] – 2026-05-10

### Geändert

- Formular-Markup: `<form class="gfb-form">` erhält `lang="…"` (Locale), damit native `type="time"`/`date`-Controls stärker an die Site-Sprache gebunden sind (Hinweis in README zu Browser-Meldung „Ungültiger Wert“ bei 12h-System-UI).

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
