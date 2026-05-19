# INSTALL.md — Gutenberg Formbuilder

## 1. Voraussetzungen

- WordPress 6.6+ (entspricht `Requires at least` im Plugin-Header)
- PHP 7.4+ (8.2+ empfohlen)
- PHP-Erweiterungen: `openssl` (mit AES-256-GCM, in modernem PHP/OpenSSL Standard), `mbstring`, `fileinfo`
- Optional, dringend empfohlen: `clamav`/`clamdscan` auf dem Host
- HTTPS-Erreichbarkeit der Site

## 2. Master-Key in `wp-config.php` hinterlegen (Pflicht)

Vor der ersten Aktivierung müssen zwei Konstanten **oberhalb der `/* That's all, stop editing! ... */`-Zeile** in `wp-config.php` gesetzt sein:

```php
// 1) Schlüssel-Material. Format: "id:base64-32byte-key,id2:..."
define( 'GFB_MASTER_KEYS', '1:REPLACE_ME_BASE64_32BYTE_KEY' );

// 2) Welcher Key ist aktiv (verschlüsselt neue Daten).
define( 'GFB_ACTIVE_KEY_ID', '1' );
```

Einen frischen, kryptografisch zufälligen Key kannst du auf der Kommandozeile erzeugen:

```bash
openssl rand -base64 32
# Beispielausgabe (DEINER wird anders sein!):
# 9zJq3xR6N4HnBcZ3Wd8sTm/2Yp1f2vQK8RkM5w4ZxNg=
```

> ⚠️ Geht der Schlüssel verloren, sind alle damit verschlüsselten Daten unwiederbringlich verloren. Schlüssel **ausserhalb des Backups der DB** sicher aufbewahren (z. B. Passwort-Manager, Hardware-Vault).

Nach Aktivierung zeigt die Plugin-Einstellungsseite (Formular-Einträge → Sicherheit) den Status. Wenn die Konstanten fehlen, wird das gross und rot angezeigt und Datei-Uploads werden blockiert.

## 3. Aktivierung

1. Plugin in `wp-content/plugins/gutenberg-formbuilder/` ablegen (oder via Symlink).
2. WP-Admin → Plugins → "Gutenberg Formbuilder" aktivieren.
3. Prüfen unter Formular-Einträge → Sicherheit:
   - Verschlüsselung: aktiv (gruener Status)
   - ClamAV: konfiguriert oder bewusst deaktiviert
   - Capability-Matrix: ggf. weiteren Rollen Caps zuweisen

Bei Aktivierung werden automatisch erstellt:
- `wp_gfb_submissions` (Submissions)
- `wp_gfb_files` (Datei-Metadaten)
- `wp_gfb_audit` (Audit-Log)
- Verzeichnis `wp-content/.gfb-private/gfb-encrypted/` (Dot-Verzeichnis: wird von Apache- und Nginx-Standardkonfigurationen automatisch geblockt; zusätzlich `.htaccess` `Require all denied`).
- Cron-Job `gfb_rewrap_cron` (stuendlich, für Key-Rotation)

## 4. Webserver-Konfiguration

Default-Storage liegt in einem **Dot-Verzeichnis** (`.gfb-private/`). Apache- und Nginx-Standardkonfigurationen blockieren Dotfiles/Dot-Verzeichnisse out-of-the-box mit 403/404 — der Schutz wirkt also bereits ohne weitere Eingriffe. **Dennoch** wird empfohlen, einen expliziten Block hinzuzufügen, falls die Default-Konfig manipuliert wurde:

### Apache
```apache
<DirectoryMatch "^.*/wp-content/\.gfb-private/.*$">
    Require all denied
</DirectoryMatch>
```

### Nginx
```nginx
location ^~ /wp-content/.gfb-private/ {
    deny all;
    return 403;
}
```

### Verifikation
In WP-Admin → Formular-Einträge → Sicherheit findest du die Sektion «Sind hochgeladene Dateien wirklich privat?». Klicke auf «Jetzt prüfen». Zeigt der Test ein Problem, liefert dein Webserver Dateien aus dem privaten Ordner aus. Korrigiere das sofort mit einem der Snippets oben oder frage den Hoster. Der Test läuft auch beim Aktivieren des Plugins.

## 5. ClamAV einrichten (optional)

> Hinweis: ClamAV ist eine **zusätzliche Schutzschicht und kein Muss**. Auf den
> meisten Shared-Hostings ist ClamAV nicht installierbar. Die
> Datei-Verschlüsselung des Plugins funktioniert davon unabhängig. Nur
> einrichten, wenn der Hoster es zulässt.

### macOS / Linux (clamav-daemon)
```bash
# Debian/Ubuntu
sudo apt install clamav clamav-daemon
sudo systemctl enable --now clamav-daemon
sudo freshclam   # Signaturen aktualisieren

# Pfade prüfen
which clamdscan
ls -l /var/run/clamd.scan/clamd.sock
```

In WP: Formular-Einträge → Sicherheit → "ClamAV":
- Modus: `binary` und Pfad `/usr/bin/clamdscan`, **oder**
- Modus: `socket` und Pfad `/var/run/clamd.scan/clamd.sock`
- Speichern → "EICAR-Test ausführen" → muss `infected` zurückgeben.

### Pflicht für Uploads (opt-in)
Standardmässig **aus**. Erst aktivieren, nachdem du oben einen Modus
konfiguriert UND mit "Verbindung testen (EICAR-Test)" verifiziert hast, dass
ClamAV antwortet. Sobald aktiv und der Scanner fällt aus, werden alle
Datei-Uploads abgelehnt — sicher, aber härter für den Betrieb.

## 6. Capabilities zuweisen

Default: `administrator` darf alles. Beispiele für feinere Rollenverteilung:

- **Datenschutz-Beauftragte/r**: nur `gfb_view_submissions` + `gfb_view_audit`. Sieht Submissions in maskierter Form, kann Audit-Log einsehen, kann nicht entschlüsseln/löschen.
- **Sachbearbeitung**: `gfb_view_submissions` + `gfb_decrypt_submissions` + `gfb_download_files`. Löscht nicht.
- **Backup-Verantwortliche/r**: keine Plugin-Caps. Backup berührt nur Ciphertext + DB.

## 7. Key-Rotation (regelmässig empfohlen)

1. Neuen Key erzeugen (`openssl rand -base64 32`).
2. `wp-config.php`:
   ```php
   define( 'GFB_MASTER_KEYS',   '1:OLD_KEY,2:NEW_KEY' );
   define( 'GFB_ACTIVE_KEY_ID', '2' );
   ```
3. Site neu laden. Neue Files werden mit `2` verschlüsselt.
4. Re-Wrap-Cron läuft stuendlich automatisch und reverpackt alte DEKs. Per Settings-Seite kann ein Lauf manuell ausgeloest werden.
5. Wenn die Settings-Seite "0 Files mit alter Key-ID" zeigt, kann `1` aus `GFB_MASTER_KEYS` entfernt werden.

> ⚠️ Lösche nie einen Key, mit dem noch Daten verschlüsselt sind.

## 8. Backup-Strategie

- DB-Backup: enthält nur Ciphertext (Files-Inhalte sind nicht in der DB).
- File-System-Backup von `wp-content/.gfb-private/` (bzw. dem per Filter `gfb_private_storage_dir` gesetzten Verzeichnis): enthält nur Ciphertext.
- **Schlüssel separat sichern** — in einem Passwort-Manager oder Vault, NICHT im DB-/Filesystem-Backup. Beides zusammen wäre wieder ein "Single Point of Compromise".

## 9. Was passiert beim Upgrade von 1.x auf 2.0?

Beim Aktivieren der 2.0-Version:
- Vorhandene Submissions in `wp_gfb_submissions` bleiben unverändert (keine rückwirkende Verschlüsselung).
- Neue Submissions werden gemaess Block-Attribut `sensitive` und/oder File-Feld behandelt.
- Bestehende Forms im Editor: alle Felder nutzen ab sofort dynamische Renderer (PHP). Das im Block-Comment gespeicherte alte HTML wird ignoriert.
- Wenn ein Form ein File-Feld enthielt, ist 2.0 strenger: Crypto muss konfiguriert sein, sonst werden neue Uploads mit klarer Fehlermeldung abgelehnt. ClamAV-Pflicht ist nur dann hart, wenn sie in den Einstellungen explizit aktiviert wird (Default: aus).

## 10. Mehrsprachige Frontend-Meldungen

Öffentliche Hinweise nach dem Absenden (Erfolg, Validierung, Datei abgelehnt usw.) laufen über WordPress-Übersetzungen (**Textdomain** `gutenberg-formbuilder`).

- Mitgelieferte Dateien: `languages/gutenberg-formbuilder-en_US.mo` (und `.po`), ebenso `fr_FR`, `it_IT`. Weitere Sprachen: z. B. Kopie einer `.po` unter passendem Dateinamen (`gutenberg-formbuilder-en_GB.mo`) oder ein Werkzeug wie **Loco Translate**.
- Die Locale richtet sich in der Regel nach **Einstellungen → Allgemein → Sprache der Website**. Mehrsprachige Plugins (Polylang, WPML, …) können den Filter `locale` setzen — dann greifen dieselben Dateien, sobald die Locale passt.
- **Hinweis:** `admin-post.php` kann bei **eingeloggten** Benutzern die **Profil-Sprache** statt nur der Seitensprache verwenden (WordPress-Standard).
- `.mo` aus `.po` erzeugen (lokal, falls `gettext` installiert): `msgfmt -c -o languages/gutenberg-formbuilder-en_GB.mo languages/gutenberg-formbuilder-en_GB.po`

## 11. Erfolgsbereich und Platzhalter (Redaktion)

Keine Server-Konfiguration nötig; Kurzüberblick für Inhalte nach erfolgreichem Absenden:

- **Erfolgsbereich** (`gfb/form-success`) nur **innerhalb** des Blocks **Formular** einfügen. Wenn **keine** Folgeseite im Formularblock gewählt ist, erscheint nach erfolgreichem Senden **dieser Inhalt statt des Formulars** (sonst wie bisher Hinweiszeile bzw. Redirect).
- **Platzhalter:** `{{feldname}}` = übermittelter Wert (technischer Name des Feldes, siehe Block-Inspector unter den Feldern). Optional `{{label_feldname}}` = Bezeichnung aus dem Schema. Datei-Felder im Erfolgstext: Wert erscheint als **`[Datei]`** (kein Dateiname aus dem Snapshot).
- **Technik:** Werte kommen aus einem **sessionStorage**-Snapshot beim Absenden (gleicher Browser-Tab). Nach einem Reload ohne erneutes Absenden sind die Platzhalter nicht befüllbar.
- **Platzhalter-Hilfe** (`gfb/token`): Dropdown der **Feldnamen** fügt per Klick einen Absatz mit `{{feldname}}` ein (nur Editor, kein Frontend-Markup).

## 12. E-Mail-Benachrichtigung (Redaktion)

Konfiguration am Block **Formular** → Panel **E-Mail-Benachrichtigung** (keine separate Plugin-Seite).

1. **E-Mail nach Absenden senden** aktivieren (Standard ist aus).
2. **Empfänger:** Tokens per `FormTokenField`; nur gültige E-Mail-Adressen. Beim neuen Formular und nach Verlassen eines leeren Feldes erscheint die **Admin-E-Mail** der Website als Voreinstellung. Ohne Tokens beim Speichern versendet das System ebenfalls an die Admin-E-Mail.
3. **Betreff** optional; Platzhalter `{{feldname}}` / `{{label_feldname}}` wie im Erfolgsbereich.
4. **Absender-E-Mail:** Admin-E-Mail oder ein E-Mail-Feld aus dem Formular.
5. **Absendername** (optional): leer = Seitentitel; Platzhalter `{{feldname}}` / `{{label_feldname}}` wie beim Betreff.

Ausführlich (Versandlogik, Sicherheit, Attribute): [`docs/EMAIL-BENACHRICHTIGUNG.md`](docs/EMAIL-BENACHRICHTIGUNG.md) und [`README.md`](README.md#formular-e-mail-benachrichtigung).
