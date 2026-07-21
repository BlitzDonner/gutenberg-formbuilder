# E-Mail-Benachrichtigung (Block `gfb/form`)

Nach erfolgreicher Speicherung einer Einsendung kann das Plugin optional eine **Text-E-Mail** (`wp_mail`, `text/plain`) an konfigurierte Empfänger senden. Alle Einstellungen liegen am Block **Formular** im Inspector-Panel **E-Mail-Benachrichtigung** — nicht in einer globalen Plugin-Seite.

**PHP:** `GFB_Submit_Handler::send_notification_mail()` in `includes/class-gfb-submit-handler.php`  
**Editor:** `renderEmailNotificationControls()`, `syncFormInstance()`, `syncEmailRecipientsOnFormBlur()` in `assets/editor.js`  
**Attribute:** `blocks/form/block.json`

---

## Im Editor öffnen

1. Seite/Beitrag im Block-Editor bearbeiten.
2. Block **Formular** (`gfb/form`) auswählen.
3. Rechte Seitenleiste → Panel **E-Mail-Benachrichtigung**.

Das Panel ist nur sichtbar, wenn der Formular-Block (oder ein Inner Block darin) ausgewählt ist.

---

## Einstellungen (Übersicht)

| Einstellung | Standard | Beschreibung |
|-------------|----------|--------------|
| **E-Mail nach Absenden senden** | Aus | Schaltet den Versand nach erfolgreichem Submit ein. |
| **Empfänger** | Admin-E-Mail (siehe unten) | `FormTokenField`: eine oder mehrere gültige E-Mail-Adressen als Tokens. |
| **Betreff** | Leer → Standardbetreff | Optional eigener Betreff; Platzhalter `{{feldname}}`, `{{label_feldname}}`. |
| **Absender-E-Mail** | Admin-E-Mail der Website | Admin-E-Mail, **eigene feste Adresse** oder **From-Adresse** aus einem `gfb/field-email` der Einsendung. |
| **Absendername** | Leer → Seitentitel | Optional; Platzhalter wie beim Betreff (`{{feldname}}`, `{{label_feldname}}`). |

Zusätzlich in **Formulareinstellungen** (gleicher Inspector, anderes Panel):

| Einstellung | Bezug zur E-Mail |
|-------------|------------------|
| **Anzeigename (optional)** | Nur für **Standardbetreff**, wenn kein eigener Betreff gesetzt ist (`formTitle`). |

---

## Empfänger (`emailRecipients`)

- **Komponente:** WordPress `FormTokenField` (`wp.components.FormTokenField`).
- **Speicherung:** Array von Strings im Block-Attribut `emailRecipients` (max. **10** gültige Adressen serverseitig).
- **Validierung beim Einfügen:** Nur Tokens im üblichen E-Mail-Format werden akzeptiert (`__experimentalValidateInput` im Editor); ungültige Eingaben zeigen *Keine gültige E-Mail-Adresse.*
- **Tokenisierung:** Enter, Komma oder Leerzeichen (`tokenizeOnSpace`).

### Voreinstellung Admin-E-Mail

Die Admin-E-Mail der Website (`get_option( 'admin_email' )`) wird dem Editor über `gfbEditorAssets.adminEmail` (`wp_localize_script` in `includes/class-gfb-plugin.php`) bereitgestellt.

| Situation | Verhalten im Editor |
|-----------|---------------------|
| **Neues Formular** (neue `formId` / Duplikat / Einfügen) | Ist `emailRecipients` leer, wird die Admin-E-Mail **einmalig** als Token gesetzt (`syncFormInstance`). |
| **Bearbeitung** | Tokens können entfernt werden. |
| **Block verlassen** | War das Feld leer und der Formularblock (inkl. Inner Blocks) verliert den Fokus, wird die Admin-E-Mail **wieder** als Token gesetzt (`syncEmailRecipientsOnFormBlur`). |

So bleibt im Editor erkennbar, wohin die Mail standardmässig geht, ohne dass beim Tippen ständig nachgefüllt wird.

### Versand ohne Tokens

Ist `emailRecipients` beim Speichern des Blocks **leer** (alle Tokens entfernt und Seite gespeichert), verwendet der Server beim Versand ebenfalls die **Admin-E-Mail** (`resolve_notification_recipients()`). Ohne gültige Admin-E-Mail und ohne Empfänger wird **keine** Mail versendet.

---

## Absender-E-Mail (`emailFromField`, `emailFromCustom`)

Dropdown **Absender-E-Mail**:

| Option | From-Adresse beim Versand |
|--------|---------------------------|
| **Admin-E-Mail der Website** (Wert `""`) | Admin-E-Mail (`get_option( 'admin_email' )`). |
| **Eigene E-Mail-Adresse** (`emailFromField` = `gfb_custom_sender`) | `emailFromCustom` aus dem Block (serverseitig `sanitize_email`); ungültig oder leer → Fallback Admin-E-Mail. **Nicht** aus POST — Manipulationsschutz wie bei den übrigen Mail-Einstellungen. |
| **E-Mail-Feld** (z. B. `email_abc123`) | Gültige Adresse aus diesem Feld der Einsendung, falls vorhanden und nicht verschlüsselt; sonst Fallback Admin-E-Mail. |

## Absendername (`emailFromName`)

- **Optional**, kein Pflichtfeld.
- **Leer:** Anzeigename im `From:`-Header = **Seitentitel** der Website (`blogname`).
- **Eigener Text:** dieselben Platzhalter wie beim Betreff:
  - `{{feldname}}` — Wert aus der Einsendung (verschlüsselte Felder → Hinweistext, max. 120 Zeichen pro Platzhalter).
  - `{{label_feldname}}` — Feldbezeichnung aus `_gfb_labels`.
- Nach Ersetzung: `wp_strip_all_tags`, CRLF entfernt, max. 120 Zeichen gesamt.
- Bleibt nach Platzhalter-Ersetzung nur Leerzeichen, gilt der **Seitentitel** als Fallback.

**Beispiel:** `Einsendung von {{label_email}}` oder `{{vorname}} {{nachname}}` (technische Feldnamen).

**Hinweis Zustellbarkeit (gilt für Betreiber-Mail und Bestätigungsmail):**

- **SPF, DKIM, DMARC:** Zuverlässige Zustellung setzt ein SMTP-Setup voraus, bei dem die Site-Domain diese drei Nachweise besteht. Ohne sie landen automatisch erzeugte Mails oft im Spam oder werden abgewiesen.
- **From-Adresse:** Kommt die From-Adresse aus einem Besucher-Feld, scheitert der Versand je nach DMARC-Richtlinie der fremden Domain. Die **Bestätigungsmail** an die ausfüllende Person nutzt deshalb **immer** eine feste, betreiber-eigene Adresse (`noreply@site-domain`, Filter `gfb_receipt_from`) – nie ein Besucher-Feld. Keine Freemail-Domain (gmail.com, gmx.ch …) als From verwenden.
- **Return-Path / Bounces:** Die Bestätigungsmail setzt den Return-Path auf die From-Adresse (Filter `gfb_receipt_return_path`). Dieses Postfach einrichten oder umleiten, damit unzustellbare Mails (Bounces) sichtbar werden. Harte Bounces zitieren den Mail-Inhalt – ein Grund mehr, weshalb vertrauliche Werte im Sofort-Modus unterdrückt bleiben.
- **«Übergeben» heisst nicht «zugestellt»:** Der Status in den Formular-Einträgen bestätigt nur die Übergabe an den Mailserver. Der einzige positive Zustellnachweis ist der Klick auf den Bestätigungslink (Double-Opt-in).
- **AVV:** Ein externer SMTP-Dienst ist Auftragsbearbeiter (Art. 9 revDSG, Art. 28 DSGVO) – AVV abschliessen, Serverstandort und TLS prüfen, EU-/CH-Anbieter bevorzugen.

**Kopplung an den Double-Opt-in-Modus:** Die beiden Betreiber-Mails des Bestätigungslink-Modus («unbestätigt eingegangen» beim Absenden, «jetzt bestätigt» nach dem Klick) sind Ausprägungen dieser Betreiber-Benachrichtigung. Sie werden nur versendet, wenn **E-Mail nach Absenden senden** (`emailNotificationEnabled`) aktiviert ist – ist die Benachrichtigung aus, gehen keine Betreiber-Mails, der Bestätigungslink an die ausfüllende Person funktioniert unabhängig davon. Der Editor warnt bei dieser Kombination.

**Branding der Bestätigungsmails (site-weit):** Unter «Sicherheit & Einstellungen → Bestätigungsmail an Absender/innen» lassen sich ein Logo (Mediathek oder http(s)-URL; data:-URIs werden abgelehnt), ein Logo-Link und eine Fusszeile mit der Absender-Identität hinterlegen. Der Rahmen gilt für alle drei Person-Mails (Sofort-Quittung, Link-Mail, Voll-Quittung); Betreiber-Mails bleiben reiner Text. Die Fusszeile erlaubt nur `a[href]`, `br`, `strong`, `b`, `em` (kses beim Speichern und beim Rendern); in der Textfassung erscheinen Links als «Text: URL», das Logo entfällt. Für Profis ersetzen zwei Filter den jeweiligen Bereich vollständig: `gfb_receipt_mail_header_html( $default_html, $ctx )` und `gfb_receipt_mail_footer_html( $default_html, $ctx )` – beide erhalten den gerenderten Default (Tabellenzeilen-HTML) plus den Render-Kontext (u. a. `mode`, `form_id`, `post_id`); die Rückgabe wird unverändert eingesetzt, Escaping liegt dann beim Filter-Autor.

**Landeseiten des Bestätigungslinks gestalten:** Ab WordPress 6.7 sind die zwei Seiten des Bestätigungslinks im Website-Editor unter «Templates» anpassbar («Formular – Bestätigungsseite», «Formular – Bestätigungs-Ergebnis»). Der Block «Bestätigungs-Status» rendert dort Knopf bzw. Ergebnis-Meldung und gibt nie Feldwerte aus. Remote-Bilder laden auf diesen Seiten bewusst nicht (Sicherheits-Policy self-only gegen Token-Leck). Bei WordPress-Versionen vor 6.7 erscheint die eingebaute Minimalseite. Details: [`BESTAETIGUNGSMAIL-SPEC.md`](BESTAETIGUNGSMAIL-SPEC.md), Abschnitt 13.

---

## Betreff (`emailSubject`)

- **Leer:** Standardbetreff  
  - Mit **Anzeigename:** `Neues Formular: {formTitle} ({formId}), Beitrag {postId}`  
  - Ohne Anzeigename: `Neues Formular ({formId}) auf Beitrag {postId}`  
  - Präfix: `[{blogname}]`
- **Eigener Text:** Platzhalter werden aus der **gespeicherten** Einsendung ersetzt:
  - `{{feldname}}` — Wert (technischer POST-Name); verschlüsselte Felder → Hinweistext, keine Entschlüsselung in der Mail.
  - `{{label_feldname}}` — Feldbezeichnung aus `_gfb_labels`.

Betreff wird bereinigt (`wp_strip_all_tags`, CRLF entfernt, max. 250 Zeichen).

---

## E-Mail-Inhalt (Body)

- Format: **text/plain**, UTF-8.
- Zeilen: `Bezeichnung: Wert` pro Feld (Bezeichnung aus Label oder technischem Namen).
- **Nicht** in der Mail: entschlüsselte sensitive Werte, Dateiinhalte (Hinweise wie `[verschlüsselt — bitte im Admin entschlüsseln]` bzw. `[verschlüsselte Datei #N — Download im Admin]`).
- Werte: `wp_strip_all_tags`, Längenlimits pro Zeile.

Details: [`SECURITY.md`](../SECURITY.md) (Mail-Härtung, H4).

---

## Ablauf beim Absenden

1. Einsendung wird validiert und in `{prefix}gfb_submissions` gespeichert.
2. Hook `gfb_after_submission_insert` (optional für Erweiterungen).
3. Wenn `emailNotificationEnabled === true`: `send_notification_mail()` mit Block-Attributen aus `GFB_Plugin::get_form_block_attributes_from_post()` — **nicht** aus `$_POST` (Manipulationsschutz).
4. Kein Versand, wenn Schalter aus oder keine gültigen Empfänger ermittelbar werden.

E-Mail-Versand schlägt **nicht** den Submit-Erfolg fehl: Fehler in `wp_mail` ändern die Weiterleitung des Besuchers nicht.

---

## Block-Attribute (Referenz)

| Attribut | Typ | Default | Bedeutung |
|----------|-----|---------|-----------|
| `emailNotificationEnabled` | `boolean` | `false` | Versand ein/aus |
| `emailRecipients` | `array` | `[]` | Liste Empfänger-E-Mails |
| `emailSubject` | `string` | `""` | Eigener Betreff (optional) |
| `emailFromField` | `string` | `""` | Leer = Admin; `gfb_custom_sender` = eigene Adresse; sonst technischer `name` eines `gfb/field-email` |
| `emailFromCustom` | `string` | `""` | Feste From-Adresse, wenn `emailFromField` = `gfb_custom_sender` |
| `emailFromName` | `string` | `""` | Optionaler From-Anzeigename mit Platzhaltern; leer = Seitentitel |

Verwandt: `formTitle` (Anzeigename) in **Formulareinstellungen**.

---

## Erweiterungen (Hooks)

Nach dem DB-Insert:

```php
do_action( 'gfb_after_submission_insert', $submission_id, $payload, $form_attrs, $post_id, $form_id );
```

Eigene Benachrichtigungen (z. B. CRM) können hier angebunden werden; die eingebaute Mail bleibt unabhängig davon steuerbar über die Block-Einstellungen.

---

## Bekannte Grenzen

- Keine HTML-Mails, keine Anhänge aus File-Feldern.
- Keine Mehrsprachigkeit der Mailtexte pro Formular (Strings über WordPress-i18n der Plugin-Locale).
- `wp_mail` hängt von Host/SMTP-Plugin ab (Zustellbarkeit nicht garantiert).
- Max. 10 Empfänger pro Formular.
