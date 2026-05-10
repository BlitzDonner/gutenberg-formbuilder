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
