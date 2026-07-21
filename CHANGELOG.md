# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Versionsnummern folgen [SemVer](https://semver.org/lang/de/); Vorab-Releases trugen das Suffix `-beta.N`.

## [2.10.0] – Unreleased

### Neu

- **Bestätigungsmail an die ausfüllende Person (Autoresponder + Double-Opt-in):** Formulare können der absendenden Person eine Eingangsbestätigung schicken – wählbar pro Formular am Block («Bestätigungsmail an Absender/in»): `keine` (Standard), `sofort` oder `mit Bestätigungslink` (Double-Opt-in). Empfänger ist explizit ein E-Mail-Feld des Formulars (Dropdown); ohne gewähltes oder gültiges Feld wird nicht versendet, die Einsendung läuft normal. Der Inspector-Bereich führt mit neun kontextabhängigen Warnungen durch die Konfiguration (fehlendes/nicht gewähltes E-Mail-Feld, nicht erzwungenes Captcha im Sofort-Modus, vertrauliche Felder im Sofort-Modus, fehlende Container-Blöcke, fehlender `{{bestaetigungslink}}`, fehlender «Alle Feldwerte»-Block, deaktivierte Betreiber-Benachrichtigung im Link-Modus). Spezifikation: `docs/BESTAETIGUNGSMAIL-SPEC.md`; Mail-Engine in `includes/class-gfb-receipt-mail.php`.
- **Mail-Gestaltung mit Gutenberg:** Zwei neue Container-Blöcke im Formular – `gfb/receipt-mail` («Bestätigungsmail – Inhalt») und `gfb/doi-mail` («Bestätigungslink-Mail – Inhalt») – mit begrenztem Block-Set (Absatz, Überschrift, Liste, Bild, Trenner, Knopf) und Platzhaltern `{{feldname}}`/`{{label_feldname}}`. Der neue Block `gfb/all-fields` («Alle Feldwerte») fügt die automatische Daten-Tabelle ein. Die Link-Mail trägt den Pflicht-Platzhalter `{{bestaetigungslink}}`; fehlt er, hängt der Server den Link an. Leerer oder fehlender Container → eingebaute, übersetzte Standard-Vorlage. Alle drei Blöcke sind nur im Editor sichtbar und erscheinen nie im Formular-Frontend.
- **Eigener Block-zu-Mail-Übersetzer:** Serverseitige Übersetzung in mail-sicheres HTML (600-px-Tabellenlayout, Inline-CSS, bulletproof Button/Bild) plus automatisch gespiegelte Text-Fassung – echtes `multipart/alternative` über `phpmailer_init`/`AltBody`. Zusätzlich: Return-Path auf die Absenderadresse, Header `Auto-Submitted: auto-generated` (RFC 3834), Message-ID mit der From-Domain.
- **Double-Opt-in-Mechanik:** Einsendung sofort gespeichert und für den Betreiber sichtbar (Status «unbestätigt»); der Klick weist die Postfachkontrolle nach. Token aus `random_bytes(32)`, in der DB nur als gepfefferter HMAC-Hash (indizierte Spalte), 7 Tage gültig, Einmalgebrauch als atomarer Compare-and-swap. Die Landeseite (`admin-post.php?action=gfb_confirm`) ist datenfrei – Bestätigung ausschliesslich per POST hinter einem Knopf mit eigenem Nonce; Header `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `nosniff`, restriktive CSP. Nach dem Klick: Voll-Quittung inkl. vertraulicher Klartext-Werte an die Person, zweite Betreiber-Mail «jetzt bestätigt» (beide Betreiber-Mails setzen die aktivierte Betreiber-Benachrichtigung voraus – sie sind Ausprägungen davon, siehe E9-Präzisierung in der Spec). Die Erfolgsseite formuliert nach tatsächlichem Übergabe-Status («Ihre Bestätigung ist erfasst.» plus «an Ihren Mailserver übergeben» oder neutral «Der Seitenbetreiber hat Ihre Meldung erhalten.») – nie «zugestellt». Ungültig/abgelaufen/verbraucht → uniforme neutrale Meldung (kein Oracle).
- **Missbrauchsabwehr:** Der Sofort-Modus versendet nur bei serverseitig erzwungenem Captcha (Phishing-Verstärker-Blocker) – und nur, wenn die Captcha-Verifikation dieses Requests tatsächlich «pass» ergab (fail-closed): Lässt der soft-Modus die Einsendung bei nicht erreichbarem Anbieter ausnahmsweise durch, wird die Sofort-Mail trotzdem unterdrückt (Audit `receipt_skipped`/`captcha_unverified`). Mehrschichtiges, atomares Send-Gate (INSERT … ON DUPLICATE KEY UPDATE, fail-closed): 50 Bestätigungsmails/Stunde/Site, 10/Stunde/IP, 3/Stunde/Empfängeradresse – Filter `gfb_receipt_gate_limits`. Der Bestätigungs-Endpunkt selbst ist IP-gedrosselt (20 Versuche/10 Minuten, Überschreitung liefert die uniforme neutrale Antwort, Ereignis `doi_rate_limited`). Vertrauliche Felder stehen im Sofort-Modus nur als «vertraulich gespeichert» in der Mail; Dateien nie als Anhang, nur Dateiname. Feld-Platzhalter werden in der Link-Mail bewusst leer ersetzt (keine Fremdinhalte in Mails an frei wählbare Adressen).
- **Feste Absenderadresse:** From der Bestätigungsmail ist immer betreiber-eigen (`noreply@site-domain`), per Filter `gfb_receipt_from`/`gfb_receipt_from_name`/`gfb_receipt_return_path` setzbar – nie die feldgesteuerte `emailFrom*`-Logik der Betreiber-Mail. Sprache zur Absendezeit über `determine_locale()`, Filter `gfb_receipt_locale`.
- **Status pro Einsendung:** Neue Spalten (Schema-Version 3) halten Bestätigungs- und Versandstatus; die Detailansicht der Formular-Einträge zeigt «unbestätigt»/«bestätigt am …» und «an Mailserver übergeben»/«Übergabe fehlgeschlagen» – bewusst nie «zugestellt». Jeder Versand, jede Bestätigung und jedes Überspringen ist ein eigenes Audit-Ereignis (`receipt_handed_off`, `receipt_handoff_failed`, `receipt_skipped`, `doi_confirmed`, `doi_rejected`) – nur mit Einsendungs-ID und Status-Slug, ohne Adressen oder Feldwerte, nie mit dem Roh-Token.
- **Neuer Block «Bestätigungs-Knopf» (gfb/confirm-button):** Der Gutenberg-Link-Dialog akzeptiert `{{bestaetigungslink}}` nicht als URL (Live-Befund beta.rell.ch) – ein core/button mit Platzhalter-href ist für Redaktoren real nicht setzbar. Der neue dedizierte Knopf-Block (nur im Container «Bestätigungslink-Mail – Inhalt») hat bewusst kein URL-Feld: Der Server setzt den Bestätigungslink beim Versand immer selbst. Editierbar sind Beschriftung (RichText, Default «Jetzt bestätigen») und Farben über native Block-Supports (Custom-Farben erscheinen im Mail-Knopf; Paletten-Presets fallen im Mail-Kontext bewusst auf die Default-Optik zurück – kein Theme-CSS in Mails). Editor-Darstellung als Knopf mit Hinweis «Link wird beim Versand automatisch gesetzt»; Mail-Rendering als bulletproof Tabellen-Button, Plaintext als «Beschriftung: URL»-Zeile. Die Standard-Vorlagen der Link-Mail (Editor-Template und Server-Vorlage) nutzen den Knopf statt des nackten Platzhalter-Absatzes; der erklärende Textabsatz davor bleibt. Der Text-Platzhalter bleibt in Absätzen unterstützt, die Editor-Warnung gilt als erfüllt durch Knopf ODER Platzhalter, der Server-Fallback (Link anhängen, wenn beides fehlt) bleibt, und core/button mit Platzhalter-href wird im Übersetzer weiter akzeptiert (Abwärtskompatibilität).
- **Bestätigungslink als Frontend-URL:** Der Link in der DOI-Mail lautet neu `https://site/?gfb-bestaetigung={Nummer}&gfb-token={Token}` (home_url) statt der bisherigen wp-admin-Adresse – eine admin-post-URL in einer Kundenmail wirkt wie Admin-Zugang/Phishing und wird von Firmen-Mailfiltern abgestraft (Live-Befund beta.rell.ch). Bewusst Query-Variante ohne Rewrite-Rules (kein Flush), Parameter kollisionssicher mit gfb-Präfix; der Handler greift früh auf `template_redirect` und nur bei gesetztem `gfb-bestaetigung`. Auch das POST-Formular der Landeseite und der Server-Fallback («Link anhängen, wenn der Platzhalter fehlt») erzeugen die neue URL. Die alte admin-post-Route bleibt als voll funktionsfähiger Alias – bereits verschickte Links überleben die 7 Tage Token-Gültigkeit; beide Einstiege sind dünne Parser über demselben Bestätigungs-Kern (identisches Verhalten, Subprozess-Harness-belegt).
- **Integrations-Hooks für Dritt-Plugins:** Drei neutrale Hooks für DOI- und einwilligungsbewusste Weiterverarbeitung (z. B. CRM-Anbindung; keine Anbindungs-Logik im Plugin): Filter `gfb_doi_cleared` (Übermittlung aus DOI-Sicht freigegeben? true ohne DOI-Modus oder bei bestätigter Adresse, sonst false), Filter `gfb_doi_status` (''/none/pending/confirmed/expired, gleiche Zustandslogik wie die DOI-Ampel) und Action `gfb_doi_confirmed( $submission_id, $form_id, $post_id )` – feuert genau einmal beim erfolgreichen Bestätigungs-CAS, nach dem Statuswechsel und vor der Voll-Quittung. Dazu das neue Formular-Attribut `consentField` («Datenweitergabe (Integrationen)» im Inspector: Dropdown der Checkbox-Felder) mit Filter `gfb_transfer_consent` – true nur bei designiertem Feld UND angekreuzter Checkbox, nie implizit; vertraulich markierte Felder werden nicht entschlüsselt (Filter liefert false, Editor warnt). Die Filter geben nur boolesche/Status-Werte heraus, nie Feldinhalte. Doku mit Beispiel: `docs/INTEGRATIONEN.md`.
- **Einstellungs-Karte «Bestätigungsmail»: Hinweise als Toggles.** Die drei Hinweisblöcke (Zustellbarkeit, Missbrauchsschutz, Datenschutz und Recht) stecken neu in standardmässig zugeklappten Aufklapp-Elementen (natives details/summary-Muster der Karte) – Inhalte unverändert, die Karte wird deutlich kompakter.
- **Vorschau und Testmail für die Bestätigungsmail:** Die Karte «Bestätigungsmail an Absender/innen» zeigt neu eine serverseitig gerenderte Inline-Vorschau der Person-Mail mit dem gespeicherten Branding (Logo-Header, eingebaute Standard-Vorlage, Dummy-Feldwerte-Tabelle mit statischen, übersetzten Beispielwerten, Footer-Identität, neutraler Schluss-Satz) – dargestellt in einem sandboxed iframe mit escaped srcdoc, damit Mail-HTML und Admin-CSS sich nicht berühren; die Vorschau rendert aus den gespeicherten Werten (kein AJAX-Live-Preview, bewusst schlank). Dazu ein Knopf «Testmail senden» mit Empfänger-Feld (Default: E-Mail des eingeloggten Admins): Versand über den echten Engine-Pfad (Multipart, From/Return-Path/Auto-Submitted wie produktiv), Betreff mit Präfix «[Test] », Inhalt identisch zur Vorschau. Ergebnis-Notice sagt «an den Mailserver übergeben» bzw. «Übergabe fehlgeschlagen» – nie «zugestellt». Die Testmail umgeht das öffentliche Send-Gate (Admin-Aktion, verbraucht keine Deckel), ist aber mild gedrosselt (5 pro 10 Minuten je Site); Audit-Eintrag `receipt_test_mail` ohne Empfängeradresse.
- **Fusszeilen-Feld mit visuellem Editor:** Das Footer-Textfeld des Mail-Brandings nutzt statt der rohen Textarea einen kleinen klassischen TinyMCE (`wp_editor()`, teeny-Modus, ohne Medien-Knöpfe). Toolbar und `valid_elements` sind exakt auf die kses-Allowlist begrenzt (bold, italic, link/unlink, undo/redo – a[href], br, strong/b, em); Enter erzeugt `<br>` statt `<p>`-Blöcken (`forced_root_block ''`), `wpautop` bleibt aus – gespeicherte br-Werte wandern unverändert in den Editor und zurück (Roundtrip ohne Drift). Der Text-Tab bietet nur strong/em/link. Die Sicherheitsgrenze bleibt unverändert die kses-Filterung beim Speichern und Rendern; der Editor ist reiner Komfort. Beim ersten Aufklappen der zugeklappten Karte wird der Editor einmalig re-initialisiert (WP-Muster), damit die Iframe-Höhe nicht kollabiert.
- **Site-weites Branding der Bestätigungsmails:** Drei neue Einstellungen in der Karte «Bestätigungsmail an Absender/innen» – Logo (Mediathek-Auswahl per wp.media, Attachment-ID, plus http(s)-URL-Fallback; data:-URIs bewusst abgelehnt, da Gmail Base64-Bilder nicht anzeigt und sie als Spam-Signal gelten), Logo-Link und Fusszeile mit Absender-Identität (kses-Allowlist nur a[href]/br/strong/b/em, gefiltert beim Speichern UND beim Rendern). Die Engine rahmt damit jede Person-Mail (Sofort-Quittung, Link-Mail, Voll-Quittung): Logo zentriert im Kopf (max. 200 px, alt = Blogname, optional verlinkt; ohne Logo kein Kopfbereich), Fusszeile klein und grau über dem bestehenden neutralen Schluss-Satz. Die Textfassung spiegelt die Fusszeile (Links als «Text: URL»), das Logo entfällt dort. Betreiber-Mails bleiben unverändert text/plain. Zwei Filter für vollständige Übersteuerung: `gfb_receipt_mail_header_html` und `gfb_receipt_mail_footer_html` (Doku in `docs/EMAIL-BENACHRICHTIGUNG.md`).
- **Technische Kennungen ohne Pillen-Optik.** Formular-IDs (z. B. `gfb_cbf71bd19c89`) und Datei-Fingerprints erscheinen im Backend der Einsendungen (Liste und Detailansicht) nicht mehr als Tag/Pille, sondern als kleiner, dezenter Grautext: 11 px, `#646970` (≈ 5.3:1 auf Weiss, WCAG AA auch bei dieser Grösse), Monospace bleibt für die Erkennbarkeit als technischer Wert. Die beiden bisherigen Pillen-Regeln (Hintergrund, Rahmen, Radius) sind durch eine gemeinsame Kennungen-Regel ersetzt; das Core-code-Styling wird dabei explizit neutralisiert.
- **Einsendungsliste: volle Breite und Spalten-Sortierung.** Die Listen-Ansicht skaliert neu mit der verfügbaren Seitenbreite (bisheriges 1200-px-Limit entfernt; die Detailansicht behält bewusst ihr 960-px-Limit für lesbare Feldwerte). Alle Spalten ausser «Absender» und «Aktionen» sind nach dem Core-Muster der Listen-Tabellen sortierbar (Sortier-Links mit sortable/sorted-Klassen, Pfeil-Indikatoren, aria-sort am aktiven Spaltenkopf); Standard bleibt Datum absteigend, die Sortierung wirkt per SQL ORDER BY vor LIMIT über den Gesamtbestand, Paginierung und Detail-Rücksprung behalten die Sortier-Args. Serverseitig laufen orderby/order ausschliesslich über eine strikte Whitelist (Spaltenschlüssel → SQL-Fragment; order nur ASC/DESC – nie Roh-Input in der Query). Die DOI-Spalte sortiert über einen deterministischen SQL-Status-Rang analog zur Ampel (bestätigt → offen → abgelaufen → keiner, Zeitquelle UTC_TIMESTAMP wie die Engine). «Seite» sortiert nach der Seiten-ID (gruppiert Einsendungen derselben Seite; der Titel liegt nicht in der Tabelle). «Absender» bleibt bewusst unsortierbar: Der Wert stammt heuristisch aus dem Payload-JSON und kann verschlüsselt sein – für Sortierkomfort wird weder Klartext persistiert noch entschlüsselt.
- **DOI-Ampel in der Einsendungsliste:** Die Backend-Übersicht hat eine neue Spalte «DOI» mit genau einem farbigen Punkt pro Zeile: grau = kein Bestätigungslink, grün = bestätigt, orange = Bestätigung offen (Ablauf noch nicht erreicht oder unbekannt), rot = nicht rechtzeitig bestätigt (abgelaufen). Zeitvergleich serverseitig in UTC, konsistent zur Ablauf-Logik der Engine. Barrierefrei: Information nie nur über Farbe – jeder Punkt trägt title-Attribut und Screenreader-Text mit Datum («Bestätigt am …», «Bestätigung offen bis …», «Nicht rechtzeitig bestätigt (abgelaufen am …)»); Punktfarben aus der Admin-Palette mit mindestens 3:1-Kontrast auf Weiss (WCAG 1.4.11). Die DOI-Spalten laufen in der bestehenden Listen-Query mit (keine Zusatzabfragen).
- **DOI-Landeseiten als Site-Editor-Templates:** Die Bestätigungs-Landeseite und die Ergebnisseite sind im Website-Editor unter «Templates» gestaltbar («Formular – Bestätigungsseite», «Formular – Bestätigungs-Ergebnis»; `register_block_template()`, WP 6.7+). Plugin-Default: Gruppe, Site-Logo, Überschrift, Absatz plus der neue dynamische Block `gfb/confirm-status`, der serverseitig Bestätigungs-Knopf bzw. Statusmeldung rendert – nie Feldwerte, nie «zugestellt». User-Anpassungen aus dem Site Editor überlagern den Plugin-Default (`get_block_template()`-Pfad); gerendert wird mit `wp_head()`/`wp_footer()`, damit Theme-CSS und Global Styles greifen. Unter WP 6.7 bleibt die bisherige Minimalseite als Fallback (Requires-Version unverändert 6.6). CSP des Template-Wegs bewusst auf self-only geöffnet (style/img/font/script 'self', keine Remote-Hosts – zusammen mit no-referrer kein Token-Leck über Fremd-Ressourcen); die Minimalseite behält `default-src 'none'`. Der Block ist im Beitrags-/Seiten-Inserter ausgeblendet, im Site Editor verfügbar. Details: `docs/BESTAETIGUNGSMAIL-SPEC.md`, Abschnitt 13.
- **Neue Karte «Bestätigungsmail an Absender/innen»** unter «Sicherheit & Einstellungen»: Betreiber-Hinweise zu SPF/DKIM/DMARC, Return-Path/Bounces, Freemail-Warnung, «übergeben ≠ zugestellt», AVV-Pflicht beim SMTP-Dienst, Beweiswert des DOI-Klicks (Postfachkontrolle, keine Willenserklärung) – plus kopierbarer Datenschutz-Textbaustein für Autoresponder/Double-Opt-in.

### Geändert

- **Datenschutz-Werkzeuge finden jetzt auch verschlüsselte E-Mail-Felder:** Beim Speichern wird pro E-Mail-Wert ein deterministischer, gepfefferter HMAC als Suchindex im Payload abgelegt (`_gfb_email_hmacs`); Exporter und Eraser suchen zusätzlich über diesen Hash – die bisherige LIKE-Suche griff auf Ciphertext nicht. Der Exporter gibt vertrauliche Werte für die betroffene Person entschlüsselt aus (oder klar gekennzeichnet, wenn nicht möglich) und nennt Datei-Anhänge beim Namen. Der Eraser löscht neu auch die verschlüsselten Datei-Anhänge (`GFB_File_Storage::delete_for_submission()`, fehlte bisher) und mit der Zeile alle Token-Daten.
- **Retention-Cron:** Täglicher Lauf löscht nie bestätigte DOI-Einsendungen nach 45 Tagen (Filter `gfb_receipt_retention_days`) inklusive Datei-Anhängen, entwertet abgelaufene Token-Hashes und räumt die Send-Gate-Zähler ab.
- **Zustellbarkeits-Hinweis ausgebaut** (`docs/EMAIL-BENACHRICHTIGUNG.md`, README, Einstellungs-Karte): SPF/DKIM/DMARC, Return-Path, Bounce-Sichtbarkeit, Freemail-Warnung, AVV.

## [2.9.6] – 2026-07-09

### Geändert

- **Sende-Overlay: Platzhaltertext ersetzt.** Die Verschlüsselungs-Animation beim Absenden zeigte testhalber den Klartext «Hallo Stefan» als eines von mehreren durchlaufenden Beispielwörtern – für Kunden wirkte das befremdlich. Ersetzt durch ein dreizeiliges Haiku zum Thema Datenschutz («Worte werden still / in Geheimschrift eingehüllt / sicher an ihr Ziel»), das denselben Zeichen-für-Zeichen-Effekt durchläuft (`assets/frontend.js`, `gfbRunCipherAnimation`).

## [2.9.5] – 2026-07-07

### Behoben

- **Radio-Feld: Ausrichtung «zeilenweise» wirkte im Theme-Modus nicht.** Die CSS-Regel für die zeilenweise Anordnung der Optionen (`.gfb-radio-options--row`) war nur für die Erscheinungsmodi Hell, Dunkel und Automatik definiert. Im Theme-Modus (Theme-Standard) fehlte sie, sodass die im Editor gewählte Richtung ohne Wirkung blieb. Die Anordnung ist eine funktionale Wahl (kein Farb-Styling) und gilt jetzt modus-unabhängig in allen Erscheinungsmodi. Der Renderer setzte die Klasse stets korrekt; der Fehler lag allein im fehlenden CSS für den Theme-Modus – eine Regression aus der Stil-Aufräumung in 2.6.4.

## [2.9.4] – 2026-07-06

### Behoben

- **Update-Client blockierte fremde Blitz-&-Donner-Plugins.** Die eingebettete Klasse `BD_Update_Client` wird von allen B&D-Plugins geteilt (wer zuerst lädt, gewinnt). Die alte Kopie brach ohne Lizenz-Token lokal ab – dadurch bekamen tokenfreie Plugins wie «Blitz & Donner PDF» auf derselben Installation fälschlich «Lizenz abgelaufen» angezeigt. Die Kopie ist jetzt auf dem kanonischen Stand: Ohne Token wird der Server trotzdem angefragt, er entscheidet (freie Plugins liefern aus, lizenzpflichtige antworten mit 403). Für den Formbuilder selbst ändert sich nichts.

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
