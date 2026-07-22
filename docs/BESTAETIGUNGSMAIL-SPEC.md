# Feature-Spezifikation: Bestätigungsmail an die ausfüllende Person (Autoresponder + Double-Opt-in)

Status: FREIGABEREIF. Entstanden aus der Planungs-Session vom 20.07.2026 (Stefan + Picard, 10 protokollierte Entscheide), gehärtet durch ein Vier-Perspektiven-Review (Zustellbarkeit, Datenschutz/Recht, WP-Umsetzbarkeit, Sicherheit) am selben Tag. Das Review korrigierte zwei Entscheide (siehe Abschnitt 9). Gebaut wird erst nach Stefans Bau-Freigabe.

## 1. Ziel und Nutzen

Wer ein Formular absendet, erhält sofort eine E-Mail-Bestätigung mit den gemeldeten Daten – als Beleg («Ist meine Kündigung/Meldung angekommen?»). Das ersetzt Rückversicherungs-Telefonate und gibt der Person eine Handhabe. Für beweiskritische Prozesse gibt es zusätzlich einen Bestätigungslink-Modus (Double-Opt-in): Die Person weist per Klick die Kontrolle über ihr Postfach nach, bevor die volle Quittung folgt.

## 2. Protokollierte Entscheide (Stefan, 20.07.2026)

| Nr. | Entscheid |
|---|---|
| E1 | Die Bestätigungsmail enthält alle **nicht-vertraulichen** Feldwerte eins zu eins. **Vertrauliche Felder** erscheinen im Sofort-Modus nur als Hinweis «vertraulich gespeichert», im Klartext ausschliesslich in der Quittung **nach** bestätigter Adresse (DOI). Dateien nie als Anhang – nur Dateiname + «verschlüsselt gespeichert». (Angepasst durch Review, siehe Abschnitt 9.) |
| E2 | Empfänger-Wahl explizit per Dropdown der E-Mail-Felder am Formular-Block; Funktion Opt-in pro Formular (Bestand bleibt unverändert). |
| E3 | Missbrauchsschutz ohne Verzögerung: Versand nur nach bestandener Abwehrkette; mehrschichtiges Send-Gate (global + Per-IP + Per-Empfänger, atomar, fail-closed); neutraler Schluss-Satz «Sie haben dieses Formular nicht ausgefüllt? …» (anpassbar). Sofort-Modus mit frei wählbarem Empfänger nur bei erzwungenem Captcha. (Erweitert durch Review.) |
| E4 | Double-Opt-in nach Modell «eingegangen ab Absenden»: Einsendung sofort gespeichert und für Betreiber sichtbar (Status «unbestätigt»); Klick = Nachweis der Postfachkontrolle. Voll-Quittung an die Person erst nach dem Klick; Link-Mail sofort. |
| E5 | Modus ist explizite Wahl am Formular-Block: keine / sofort / mit Bestätigungslink. Bei vertraulichen Feldern + Sofort-Modus: Editor-Hinweis, plus serverseitige Unterdrückung der Klartext-Werte (nicht nur Warnung). |
| E6 | Mail-Gestaltung mit begrenztem Gutenberg-Set in einem Bereich «Bestätigungsmail» am Formular-Block: Absatz, Überschrift, Liste, Bild/Logo, Trenner, Knopf; Platzhalter `{{feldname}}`, `{{label_feldname}}`; Spezial-Platzhalter «Alle Feldwerte» (automatische Daten-Tabelle inkl. E1-Hinweisen). Serverseitige Übersetzung in mail-sicheres HTML (Tabellenlayout, Inline-CSS) + automatische Text-Fassung (echtes Multipart). Link-Mail nutzt dieselbe Mechanik mit Pflicht-Platzhalter `{{bestaetigungslink}}`. Präzisierung (Live-Test 21.07.2026): Der Gutenberg-Link-Dialog akzeptiert `{{bestaetigungslink}}` nicht als URL (URL-Validierung) – `core/button` mit Platzhalter-href funktioniert für Redaktoren real nicht. Neuer dedizierter Block `gfb/confirm-button` («Bestätigungs-Knopf», nur in `gfb/doi-mail`): ohne URL-Feld, der Server setzt den Link immer selbst; editierbar sind Beschriftung (RichText, Default «Jetzt bestätigen») und Farben über Block-Supports. Standard-Vorlagen nutzen den Knopf statt des nackten Platzhalter-Absatzes (erklärender Text davor bleibt – nicht Knopf-only). Der Text-Platzhalter bleibt in Textabsätzen unterstützt; die Pflicht-Prüfung gilt als erfüllt durch Knopf ODER Platzhalter, der Server-Fallback bleibt. `core/button` mit Platzhalter-href wird im Übersetzer weiter akzeptiert (Abwärtskompatibilität). |
| E7 | Versand über `wp_mail()`; jeder Versand im Audit-Log + Status pro Einsendung im Backend («an Mailserver übergeben»/«Übergabe fehlgeschlagen», nie «zugestellt»); Hinweis in den Einstellungen: zuverlässige Zustellung setzt SPF/DKIM/DMARC-fähiges SMTP-Setup der Site voraus. Kein eigener SMTP-Client. |
| E8 | Bestätigungslink: 7 Tage gültig, Einmal-Nutzung. Landeseite **datenfrei** – zeigt weder vor noch nach dem Klick Feldwerte; nur Knopf und neutrale Statusmeldung. Bestätigung ausschliesslich per POST. Token zufällig (CSPRNG), gespeichert nur als gepfefferter Hash. (Angepasst durch Review, siehe Abschnitt 9.) |
| E9 | Betreiber erhält im Double-Opt-in-Modus zwei Mails: sofort «unbestätigt eingegangen» und nach Klick «jetzt bestätigt». Betreiber-Mails laufen über `format_notification_field_value()`, vertrauliche Werte bleiben dort «[verschlüsselt]». Präzisierung (QA 2026-07-20): Voraussetzung ist die aktivierte Betreiber-Benachrichtigung (`emailNotificationEnabled`) – die beiden DOI-Mails sind Ausprägungen der Betreiber-Benachrichtigung, nicht davon losgelöst. Ist sie deaktiviert, gehen keine Betreiber-Mails; der Editor warnt bei dieser Kombination. |
| E10 | Restpaket: i18n de/en/fr/it mit überschreibbaren Texten (`switch_to_locale()`); fehlendes/nicht gewähltes E-Mail-Feld → Editor-Warnung, serverseitig kein Versand, Einsendung läuft normal; Sofort-Modus und Double-Opt-in werden zusammen gebaut (gemeinsame Mail-Engine). |
| Strategie | Kein Freemium, keine Add-ons: Das gesamte Plugin wird als EIN lizenziertes Produkt geführt. Der Autoresponder ist normaler Bestandteil. |

## 3. Nicht-Ziele

- Kein Datei-Versand per Mail (nur Dateinamen).
- Kein eigener SMTP-Client, keine Versand-Dienste-Integration.
- Keine verschlüsselte Zustellung an die Person (kein Schlüsselkanal vorhanden – bewusst verworfen).
- Kein Double-Opt-in als Existenzbedingung der Einsendung (Modell «nichts zählt bis zur Bestätigung» ist verworfen).
- Keine Erinnerungs-Mails bei ausbleibender Bestätigung (bewusst schlank; später denkbar).
- Keine Anzeige von Feldwerten auf der Bestätigungsseite (Review-Beschluss).

## 4. Datenmodell

Tabelle `{prefix}gfb_submissions`, neue Spalten über den bestehenden dbDelta-Pfad `maybe_upgrade_submissions_db()` (class-gfb-submit-handler.php:70), Schema-Version auf 3:

| Spalte | Typ | Inhalt |
|---|---|---|
| `confirm_status` | VARCHAR(20) | `''` (kein DOI) / `pending` / `confirmed` |
| `confirm_token_hash` | CHAR(64), KEY | `hash_hmac('sha256', token, wp_salt('auth').'|gfb-confirm-v1')`; leer nach Verbrauch |
| `confirm_expires_at` | DATETIME NULL | Ablauf (Absenden + 7 Tage) |
| `confirm_used_at` | DATETIME NULL | Zeitpunkt des Klicks (Einmalgebrauch) |
| `receipt_mail_status` | VARCHAR(20) | `''` / `handed_off` / `handoff_failed` |
| `receipt_mail_at` | DATETIME NULL | Zeitpunkt des Versands/Versuchs |

Kein zweites Token-Verzeichnis: Der Roh-Token liegt einmalig in der Link-URL, in der DB nur der gepfefferte Hash (indizierte Spalte, Lookup ohne LIKE). Einmalgebrauch als atomarer Compare-and-swap: `UPDATE … SET confirm_status='confirmed', confirm_used_at=NOW() WHERE confirm_token_hash=? AND confirm_used_at IS NULL AND confirm_expires_at > NOW()`, danach `affected_rows === 1` prüfen (kein read-then-write). Ablauf serverseitig absolut, nie clientseitig.

## 5. Block-Attribute (`gfb/form`)

| Attribut | Typ | Default | Bedeutung |
|---|---|---|---|
| `receiptMode` | string | `none` | `none` \| `instant` \| `doi` |
| `receiptEmailField` | string | `''` | Feldname des E-Mail-Felds (Empfänger), Dropdown im Inspector |
| `receiptSubject` | string | `''` | Betreff der Bestätigungsmail (Platzhalter erlaubt); leer = Standard |
| `doiSubject` | string | `''` | Betreff der Link-Mail; leer = Standard |

Die Absenderadresse der Bestätigungsmail ist **kein** Attribut und **nicht** die `emailFrom*`-Feldlogik (siehe Abschnitt 7, Blocker 1). Sie ist fest die betreiber-eigene Site-Adresse.

Mail-Inhalte als Inner-Blocks, zwei neue Container-Blöcke analog `gfb/form-success` (nur Editor-sichtbar, Frontend rendert `''` via render_callback wie `gfb/token`, class-gfb-plugin.php:192):

- `gfb/receipt-mail` – Inhalt der Daten-Bestätigungsmail. Erlaubte Kind-Blöcke: `core/paragraph`, `core/heading`, `core/list`, `core/image`, `core/separator`, `core/buttons`; plus neuer Platzhalter-Block `gfb/all-fields` («Alle Feldwerte»). Fehlt `gfb/all-fields`, wird die Tabelle nicht automatisch angehängt (bewusste Gestaltungsfreiheit); Editor zeigt Hinweis.
- `gfb/doi-mail` – Inhalt der Link-Mail. Pflicht-Platzhalter `{{bestaetigungslink}}` (Editor-Warnung; serverseitiger Fallback hängt den Link an, falls er fehlt – die Mail geht nie ohne Link raus).

Integration in den Block-Baum: `split_form_inner_blocks()` (class-gfb-plugin.php:974) um den Eimer `confirmation` erweitern; `extract_fields_from_inner_blocks()` (:1291) und `block_to_field_row()` überspringen die neuen Blöcke wie `gfb/form-success`, damit sie nie ins Feldschema geraten. Leerer Container → eingebaute Standard-Vorlage (übersetzt, i18n).

## 6. Ablauf

### Sofort-Modus (`instant`)
1. Abwehrkette vollständig bestanden (Nonce → HMAC → Honeypot → Rate-Limit → **Captcha erzwungen** → Felder) und Einsendung gespeichert.
2. Betreiber-Benachrichtigung wie bisher.
3. Send-Gate prüfen (Abschnitt 8): globaler Stunden-Deckel + Per-IP-Deckel + Per-Empfänger-Limit, atomar, fail-closed. Überschritten → kein Versand, Audit `receipt_skipped (gate)`.
4. Empfängeradresse = Wert des gewählten E-Mail-Felds nach `sanitize_email` + `is_email`. Feld leer/ungültig → kein Versand, Audit `receipt_skipped (no_recipient)`.
5. Mail rendern (Abschnitt 7) und versenden; vertrauliche Felder erscheinen als «vertraulich gespeichert». `receipt_mail_status`/`_at` setzen; Audit `receipt_handed_off` / `receipt_handoff_failed`.

### Double-Opt-in-Modus (`doi`)
1.–2. wie oben; zusätzlich Status `pending`, Token erzeugen (`random_bytes(32)`, base64url), nur Hash + Ablauf speichern. Betreiber-Mail trägt Vermerk «unbestätigt eingegangen» (E9, Mail 1).
3. Link-Mail an die Person (gleiches Send-Gate). Link: `add_query_arg(['action'=>'gfb_confirm','s'=>{id},'t'=>{token}], admin_url('admin-post.php'))`. Präzisierung (Live-Test 21.07.2026): Der Link ist neu eine **Frontend-URL** `home_url('/') . '?gfb-bestaetigung={id}&gfb-token={token}'` – eine wp-admin-URL in einer Kundenmail wirkt wie Admin-Zugang/Phishing und wird von Firmen-Mailfiltern abgestraft. Bewusst Query-Variante ohne Rewrite-Rules (kein Flush, keine Permalink-Bruchflächen), Parameter kollisionssicher mit gfb-Präfix. Die alte admin-post-URL bleibt als voll funktionsfähiger Alias (bereits verschickte Links überleben die 7 Tage Gültigkeit).
4. Klick öffnet die datenfreie GET-Landeseite (neutral, idempotent, kein State-Wechsel). Bestätigung erst per POST hinter einem Knopf mit eigenem Nonce.
5. POST: atomarer Compare-and-swap (Abschnitt 4). Erfolg → `confirmed`; Voll-Quittung inkl. vertraulicher Klartext-Werte an die Person; Betreiber-Mail 2 «jetzt bestätigt» (E9). Audit `doi_confirmed`. Ungültig/abgelaufen/schon benutzt → uniforme neutrale Meldung (kein Oracle), Audit `doi_rejected`.

### Kein-Modus (`none`)
Keine Bestätigungsmail. Bestehendes Verhalten unverändert.

## 7. Mail-Rendering und Zustellbarkeit

**Blocker 1 – Absenderadresse.** Die Bestätigungsmail zieht ihr From **nicht** aus `resolve_notification_from_email()` (class-gfb-submit-handler.php:766). From ist fest eine betreiber-eigene, ausrichtbare Adresse der Site-Domain (`noreply@site-domain` oder ein echtes Postfach, per Filter/Einstellung setzbar). Die Adresse der Person steht im To, bei der Betreiber-Mail zusätzlich im Reply-To. Kein feldgesteuertes From bei der Bestätigungsmail.

**Multipart.** `wp_mail()` mit blossem `Content-Type: text/html` erzeugt HTML-only (starkes Spam-Signal). Stattdessen unmittelbar vor dem Versand `add_action('phpmailer_init', $cb)`: `$phpmailer->AltBody = <Textfassung>`, Body = HTML; danach `remove_action`. PHPMailer setzt bei gesetztem AltBody automatisch `multipart/alternative`. Die Textfassung spiegelt den HTML-Inhalt (nicht leer, kein «bitte HTML aktivieren»).

**Return-Path.** Im selben Hook `$phpmailer->Sender` auf ein überwachtes Postfach der From-Domain setzen (SPF-Alignment, Bounce-Sichtbarkeit). Harte Bounces zitieren den Body – deshalb keine vertraulichen Klartext-Werte im Sofort-Modus (deckt sich mit E1).

**Header.** `Auto-Submitted: auto-generated` (RFC 3834) gegen Autoresponder-Schleifen; Message-ID mit der From-Domain. Subject wie heute bereinigt (`wp_strip_all_tags`, CRLF entfernt), zusätzlich ALL-CAPS und Emoji-Häufung vermeiden.

**Mail-HTML-Übersetzer.** `do_blocks()` liefert Web-Markup und ist in Outlook/Gmail unbrauchbar. Eigener rekursiver Walk über `$block['innerBlocks']` des Regionblocks, pro Core-Blocktyp inline-gestyltes Tabellen-HTML plus parallele Plaintext-Fassung. `core/image` → bulletproof `<img>` mit absoluter URL und Inline-Breite. `core/button` → tabellenbasierter Button, href aus `{{bestaetigungslink}}`. Platzhalter über `preg_replace_callback` nach Muster `replace_notification_placeholders()` (:839).

**Escaping (zwei Kontexte).** Betreiber-Template über `wp_kses` mit mail-sicherer Allowlist (kein `<script>`, kein Remote-CSS, kein Hintergrundbild). Feldwerte immer `esc_html`; in Attribut-/URL-Kontext `esc_attr`/`esc_url` mit http(s)-Allowlist (Muster aus `process_field_value()` Typ «url»). Platzhalter in `href`/`src` verboten.

**Klartext-Beschaffung.** Zum Sendezeitpunkt sind sensible Werte bereits verschlüsselt (Feldschleife :285–304). In der Schleife einen Klartext-Snapshot **vor** der Verschlüsselung aufbauen und an den Mail-Builder übergeben (sauberer als Entschlüsseln am Sendepunkt).

**Betreiber-Hinweis (Einstellungen + README).** Konkret um SPF, DKIM, DMARC, Return-Path, Bounce-Sichtbarkeit und Freemail-Warnung erweitern; heutiger Hinweis (docs/EMAIL-BENACHRICHTIGUNG.md:86) ist zu knapp. Klarstellen: Status «übergeben» ≠ «zugestellt»; der DOI-Klick ist der einzige positive Zustellnachweis.

## 8. Sicherheit und Missbrauchsabwehr

**Blocker 2 – Phishing-Verstärker.** Sofort-Modus = anonymer Endpunkt + frei wählbarer Empfänger + gebrandetes HTML. Gegenmassnahmen:
- Sofort-Modus mit frei wählbarem Empfängerfeld nur zulassen, wenn Captcha für das Formular **erzwungen** ist (nicht «wo aktiv»).
- Send-Gate mehrschichtig und atomar (`wp_cache_incr` mit add oder `INSERT … ON DUPLICATE KEY UPDATE count=count+1`): globaler Site-Stunden-Deckel (fail-closed) + Per-IP-Deckel + Per-Empfänger-Limit (`sha256`, 3/h, Adresse vorher trim/lower normalisiert). Globaler und Per-IP-Deckel sind die harte Grenze; Per-Empfänger nur ergänzend (umgehbar über +Tag, Punkt-Varianten, IDN).
- Neutraler Disclaimer über den Feldwerten in der Mail.

**Token.** `random_bytes(32)`, base64url. Speicherung nur als `hash_hmac('sha256', token, wp_salt('auth').'|gfb-confirm-v1')`. Vergleich per `hash_equals`, Lookup über indizierte Hash-Spalte. Einmalgebrauch als atomarer Compare-and-swap (Abschnitt 4). Kein WP-Nonce (session-gebunden, für Anonyme site-weit fix, nur 24 h).

**Bestätigungsseite.** Route über `admin_post_nopriv gfb_confirm` (kein REST-Neubau; Plugin hat heute keine REST-Routen). Präzisierung (Live-Test 21.07.2026): Primärer Einstieg ist neu ein früher Frontend-Hook (`template_redirect`, Priorität 0) auf den Query-Parameter `gfb-bestaetigung`; die admin-post-Route bleibt als Alias. Beide Einstiege sind dünne Parameter-Parser über demselben Kern (`run_confirm_flow`: Rate-Limit, GET-Landeseite, POST-Nonce + CAS, Template-/Minimalseiten-Rendering, alle Header inkl. CSP) – identisches Verhalten, keine Duplikation. Das POST-Formular der Landeseite postet ebenfalls auf die Frontend-URL (form-action 'self'). GET neutral und idempotent, keine Feldwerte, kein State-Wechsel. Bestätigung per POST mit eigenem Nonce. Header: `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'` (Muster `handle_download`). HTTPS erzwingen, bevor Links erzeugt werden. Kein Remote-Bild auf der Seite (Token-Leck über Referer).

**Keine öffentliche Entschlüsselung.** Vertrauliche Felder werden auf der Seite nie entschlüsselt. Der Beleg-Zweck ist durch die Mail (E1) gedeckt. Der 7-Tage-Link darf kein Bearer-Credential auf entschlüsselte Werte werden.

**Roh-Token nie in Logs.** In `gfb_security_event` und `GFB_Audit` nur Token-ID oder Hash-Präfix, nie den Roh-Token, nie die volle URL. Jeder Versand und jede Bestätigung als eigenes Audit-Event wie `captcha_verify`.

## 9. Review-Korrekturen (Vier-Perspektiven-Review, 20.07.2026)

Das Review korrigierte zwei ursprüngliche Entscheide (durch Stefan bestätigt):

- **E1 (vertrauliche Werte).** Ursprünglich: alle Werte 1:1 inkl. vertraulich, auch im Sofort-Modus. Grund der Korrektur: Ein Tippfehler in der Empfängeradresse sendet vertrauliche Klartext-Werte dauerhaft an ein fremdes Postfach; bei besonders schützenswerten Daten (Art. 5 lit. c DSG) wäre das meldepflichtig (Art. 24 DSG, Art. 33 f. DSGVO). Neu: vertrauliche Werte im Sofort-Modus unterdrückt, Klartext nur in der Quittung nach nachgewiesener Adresse (DOI).
- **E8 (Bestätigungsseite).** Ursprünglich: Seite zeigt der Person ihre Daten. Grund der Korrektur: Der 7-Tage-Token würde zum Generalschlüssel auf entschlüsselte Werte; Mail-Scanner (Outlook SafeLinks, Proofpoint) lesen die Daten per GET-Prefetch mit und verbrennen den Einmal-Token. Neu: datenfreie Seite, Bestätigung per POST, keine öffentliche Entschlüsselung.

## 10. Datenschutz und Compliance (WP-seitig umzusetzen)

- **Exporter/Eraser** (class-gfb-security.php:650/725) fit für verschlüsselte E-Mail-Felder machen: deterministischen, gepfefferten HMAC des E-Mail-Werts mitspeichern und danach suchen (heutige `LIKE`-Suche greift auf Ciphertext nicht). Im Eraser zusätzlich `GFB_File_Storage::delete_for_submission()` aufrufen (fehlt heute) und Token-Zeilen entfernen. Exporter gibt vertrauliche Werte für die betroffene Person entschlüsselt oder klar gekennzeichnet aus.
- **Audit-Log ohne PII.** Versand-Events nur mit `submission_id` + Status, nie Adresse oder Feldwerte in `context_json` (Muster `submission_insert`, class-gfb-audit.php:420). Die Hash-Chain macht Einträge sonst unlöschbar – Konflikt mit Art. 17 DSGVO.
- **Retention-Cron** analog `gfb_rewrap_cron`: nie bestätigte Einsendungen und abgelaufene Token nach konfigurierbarer Frist (Default 30–60 Tage) löschen, inkl. `delete_for_submission()`.
- **AVV-Hinweis.** Der gewählte SMTP-Dienst wird Auftragsbearbeiter (Art. 9 DSG, Art. 28 DSGVO). In Einstellungen/Doku warnen: AVV nötig, Serverstandort/TLS prüfen, EU/CH-Anbieter empfehlen.
- **Datenschutz-Textbaustein.** Bestehenden kopierbaren Baustein (`GFB_Captcha::privacy_text_snippet`) um Autoresponder, Double-Opt-in, SMTP-Bearbeiter, Aufbewahrungsfrist unbestätigter Einsendungen und Token-Mechanismus erweitern; Rechtsgrundlage je Anwendungsfall benennen (Art. 31 DSG bzw. Art. 6 Abs. 1 lit. b/f DSGVO).
- **Beweiswert.** UI-/Doku-Text stellt klar: Der DOI-Klick belegt Postfachkontrolle, keine rechtsverbindliche Willenserklärung. Für bindende Erklärungen auf geeignetere Mittel verweisen, nicht mit dem Klick werben.
- **Neutraler Schluss-Satz** pro Modus wahrheitsgemäss: Im Sofort-Modus offen sagen, dass die Einsendung beim Betreiber vorliegt, plus niederschwelliger Lösch-/Widerspruchsweg. Aussage «nicht gespeichert» nur, wo die Adresse ohne Bestätigung technisch verworfen wird.

## 11. Offene Betreiber-Parameter (bei Umsetzung zu setzen)

- Feste From-Adresse und Reply-To-Postfach der Bestätigungsmail (betreiber-eigene, ausrichtbare Domain).
- Konkrete Werte für globalen Stunden-Deckel, Per-IP-Deckel, Per-Empfänger-Limit.
- Aufbewahrungsfrist nie bestätigter Einsendungen und abgelaufener Token (fix oder konfigurierbar).
- Sprachquelle der Bestätigungsmail: fix Site-Locale, per Formular oder aus einem Sprachfeld abgeleitet.

## 12. Umsetzungs-Reihenfolge (Bau erst nach Freigabe)

1. DB-Migration (Schema-Version 3), Block-Registrierung `gfb/receipt-mail`, `gfb/doi-mail`, `gfb/all-fields`.
2. Mail-Engine: Block-zu-HTML-Übersetzer + Plaintext, phpmailer_init-Hook (Multipart, Return-Path, Header), Escaping-Schicht.
3. Sofort-Modus: Send-Gate, Empfänger-Auflösung, Captcha-Erzwingung, vertrauliche Felder unterdrücken.
4. Double-Opt-in: Token, admin_post_nopriv-Route, datenfreie POST-Bestätigungsseite, Voll-Quittung, zweite Betreiber-Mail.
5. Datenschutz: Exporter/Eraser, Audit ohne PII, Retention-Cron, Textbaustein.
6. i18n, Editor-Warnungen, README/Einstellungs-Hinweise.
7. QA (Neo/Chloe): Mail-Auth-Prüfdienst (SPF/DKIM/DMARC-Pass), Test an Gmail/Outlook/GMX, Token-Missbrauch, Escaping, Löschpfad.

## 13. Landeseiten als Site-Editor-Templates (Erweiterung, 20.07.2026)

Die zwei DOI-Landeseiten sind im Website-Editor als Templates gestaltbar, statt als fixe Plugin-Minimalseiten zu rendern.

**Weg (WP-nativ).** `register_block_template()` (WP 6.7+) registriert zwei Plugin-Templates: `gutenberg-formbuilder//gfb-confirm` («Formular – Bestätigungsseite», GET-Zustand) und `gutenberg-formbuilder//gfb-confirm-result` («Formular – Bestätigungs-Ergebnis», POST-Ergebnis). Der Plugin-Default ist eine schlanke Struktur aus Core-Blöcken (Gruppe, Site-Logo, Überschrift, Absatz) plus dem neuen dynamischen Block `gfb/confirm-status`. Dieser rendert serverseitig den Zustand des laufenden `gfb_confirm`-Requests: auf der Landeseite das POST-Formular mit Knopf und eigenem Nonce, auf der Ergebnisseite die Statusmeldung nach den bestehenden Wortlaut-Regeln (nie «zugestellt»). Der Block gibt nie Feldwerte oder PII aus – die Templates haben by design keinen Zugriff darauf; ausserhalb der Route rendert er leer. Im Beitrags-/Seiten-Editor ist er aus dem Inserter genommen (`allowed_block_types_all`), im Site Editor bleibt er verfügbar.

**Auflösung und User-Customization.** Die Route löst das Template über `get_block_template()` auf: Eine im Site Editor gespeicherte Anpassung (wp_template-Post) überlagert den Plugin-Default – das ist erwünschtes Verhalten (Style-Hierarchie: Core → Theme → User-Customization). Präzisierung (Beta-Befund 21.07.2026, empirisch auf formbuilder.local): Der Core-REST-Controller des Site Editors speichert die Customization eines plugin-registrierten Templates unter der ID des AKTIVEN THEMES (`{stylesheet}//{slug}`, wp_template-CPT mit wp_theme-Term = Theme), nicht unter der Plugin-ID. Die Auflösung fragt deshalb zuerst `get_block_template( get_stylesheet() . '//' . {slug} )` ab und akzeptiert source «custom» (Site-Editor-Anpassung) wie «theme» (theme-eigenes gleichnamiges Template – ebenfalls bewusste Gestaltung); erst danach greift der Plugin-Default über die Plugin-ID. Gerendert wird via `do_blocks()` in einem minimalen Gerüst mit `wp_head()`/`wp_footer()`, damit Theme-CSS und Global Styles greifen; wie in Cores template-canvas.php wird der Inhalt vor dem Kopf gerendert. Locale-Handling wie beim übrigen Bestätigungsfluss.

**Fallback WP < 6.7.** Der Plugin-Header verlangt weiterhin 6.6. Fehlt `register_block_template()` (function_exists-Guard), bleibt die bisherige eingebaute Minimalseite vollständig aktiv – identische Texte, da beide Ausgabewege dasselbe geteilte Zustands-Markup nutzen.

**CSP-Abwägung (bewusst).** Die Minimalseite behält `default-src 'none'; style-src 'unsafe-inline'; form-action 'self'`. Der Template-Weg braucht Theme-Assets und erhält deshalb `default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; script-src 'self'; form-action 'self'` – ausschliesslich self, keine Remote-Hosts. Zusammen mit `Referrer-Policy: no-referrer` ist ein Token-Leck über nachgeladene Fremd-Ressourcen ausgeschlossen; Remote-Bilder, die der Betreiber ins Template setzt, laden bewusst nicht. Die übrigen Header (Cache-Control no-store, X-Robots-Tag noindex, nosniff) bleiben in beiden Wegen identisch. GET bleibt idempotent, POST-Nonce, CAS und Rate-Limit unverändert.
