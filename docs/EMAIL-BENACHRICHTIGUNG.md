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

**Hinweis:** Wenn die From-Adresse aus einem Besucher-Feld kommt, kann der Versand je nach SPF/DMARC des Hosts fehlschlagen oder als Spam eingestuft werden. Für zuverlässige Zustellung oft Admin-E-Mail als From und ggf. später Reply-To-Erweiterung prüfen.

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
