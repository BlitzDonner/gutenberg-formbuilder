# SECURITY.md — Gutenberg Formbuilder

Geltungsbereich: Plugin ab Version **2.0** (aktueller Stand siehe `GFB_PLUGIN_VERSION` in `gutenberg-formbuilder.php`).
Dieses Dokument beschreibt das Bedrohungsmodell, die getroffenen Massnahmen, das Krypto-Design und die Konfigurationspunkte für den produktiven Betrieb.

> Wichtig: Das Plugin ist für den Einsatz in **systemkritischen Umgebungen** vorgesehen. Es härtet die eigene Angriffsfläche umfassend, ersetzt aber kein gehärtetes Hosting (HTTPS, aktuelle PHP/WP, gehärteter Webserver, kein PHP-Execution-Recht in `/wp-content/uploads/`).

---

## 0. Architektur in einem Bild

```
                   ┌────────────────────┐
                   │  Browser (Anonym)  │
                   └─────────┬──────────┘
              POST /wp-admin/admin-post.php
                            │  HTTPS, Nonce, HMAC-Token, Honeypot
                            ▼
       ┌───────────────────────────────────────────────────┐
       │  GFB_Submit_Handler                                │
       │   ├─ Nonce + HMAC + Honeypot + Rate-Limit          │
       │   ├─ Schema aus Block-Tree                         │
       │   ├─ Per Feld: Validation + (sensitive→Encrypt)    │
       │   └─ Per File:                                     │
       │       1) static checks (ext, double-ext, finfo)    │
       │       2) ClamAV scan (nur wenn Modus nicht disabled)│
       │       3) GFB_File_Storage::encrypt_and_store()     │
       │            ┌─ random DEK (32 byte)                 │
       │            ├─ AES-256-GCM(content, DEK)            │
       │            ├─ wrap(DEK) with active KEK            │
       │            ├─ write CT to private dir (0600)       │
       │            └─ insert wp_gfb_files row              │
       └───────────────────────────────────────────────────┘
                            │
                  wp_gfb_submissions  ◄── encrypted field envelopes
                  wp_gfb_files        ◄── DEK-wrapped, IV/Tag, sha256
                  wp_gfb_audit        ◄── tamper-evident hash chain

   Admin Download:
       wp-admin/admin-post.php?action=gfb_download&fid=N&_wpnonce=...
       → GFB_Capabilities::CAP_DOWNLOAD_FILES
       → GFB_File_Storage::decrypt_to_string()
       → audit_log(file_download_ok)
       → stream as Content-Disposition: attachment, octet-stream
```

---

## 1. Bedrohungsmodell

| Akteur | Ziel | Kontrolle |
|---|---|---|
| Anonymer Bot/Spammer | Spam-Flood | HMAC-Anti-Replay, dynamischer Honeypot, IP-Rate-Limit |
| Anonymer Angreifer mit File-Feld | RCE durch hochgeladene Datei | Static Validation + optional ClamAV + Speicherung **nicht unter öffentlicher URL** (Default: Dot-Pfad unter `wp-content/`, optional per Filter ausserhalb des Webroots), AES-256-GCM-Verschlüsselung |
| Anonymer Angreifer | Reflektierte XSS / Open-Redirect | Status-Slug-Whitelist, i18n-Texte, `wp_safe_redirect` |
| Anonymer Angreifer | Mail-Header/Body-Injection | CRLF-Strip, `wp_strip_all_tags`, expliziter `Content-Type` |
| Editor mit `unfiltered_html` | Stored XSS in Form-Markup | Dynamisches `render_callback` für ALLE Felder (kein vertrauenswürdiger save()-Output), `wp_kses` als zweite Schicht |
| Site-Admin (DSGVO) | Personenbezug-Mgmt | IP-Pseudonymisierung-Filter, Personal-Data-Exporter/Eraser |
| Server-Admin/Backup-Operator | Liest Disk/Backup mit Submissions | **Per-File AES-256-GCM-Verschlüsselung**, KEK liegt nicht in der DB |
| Diebstahl der DB | Liest Submissions/Files-Metadaten | Files: nur Metadaten in DB, Inhalte verschlüsselt auf Disk; sensitive Felder: Envelope statt Klartext |
| Manipulation von Logs | Audit-Log nachträglich ändern | Hash-Chain über alle Audit-Zeilen, Verifikation per Settings-Seite |
| Internal Threat (Admin missbraucht Konto) | Ungesehener File-Download | Eigene Caps `gfb_view_submissions` ≠ `gfb_decrypt_submissions` ≠ `gfb_download_files`; jeder Download wird im Audit-Log festgehalten |

Nicht im Modell (akzeptierte Restrisiken):

- **Kompromittierter Webserver-Prozess**: Wer den PHP-Prozess übernimmt, kann auf KEK in `wp-config.php` zugreifen → kann entschlüsseln. Das ist die bewusste Eigenschaft des "server-at-rest"-Modells. Wer stärkere Garantien braucht, baut **End-to-End** (Browser-seitige Verschlüsselung gegen einen externen Public-Key, Server sieht nur Ciphertext) — siehe Abschnitt 6.
- **Kompromittiertes Admin-Konto**: Konto mit `gfb_decrypt_submissions` + `gfb_download_files` kann lesen. Erkennung dann über Audit-Log.

---

## 2. Krypto-Design

### 2.1 Algorithmen

- **Symmetrisch**: `aes-256-gcm` (PHP `openssl`), 256-bit Key, 96-bit IV (zufällig pro Operation), 128-bit Auth-Tag.
- **AAD (Additional Authenticated Data)** wird je nach Kontext gesetzt:
  - File-Inhalte: `gfb-file-v1`
  - Feldwerte: `gfb-field-v1|<key_id>|field:<name>` — so kann ein Ciphertext nicht von einem Feld auf ein anderes "umgeschrieben" werden.
  - DEK-Wrapping: `gfb-dek-wrap-v1|<key_id>`
- **Keine** statischen IVs, keine ECB, kein "verify_then_decrypt" (GCM macht beides atomar).

### 2.2 Schlüssel-Hierarchie (envelope encryption)

- **KEK ("Key Encryption Key")** liegt als Konstante in `wp-config.php`:
  ```php
  define( 'GFB_MASTER_KEYS',   '1:base64-32byte-key,2:base64-32byte-key' );
  define( 'GFB_ACTIVE_KEY_ID', '1' );
  ```
  - Mehrere Generationen sind gleichzeitig **lesbar**, aber nur eine ist **active** (verschlüsselt damit).
  - Löscht der Operator einen alten Key, sind die Daten, die noch damit verschlüsselt sind, **unwiederbringlich verloren**. Daher: Re-Wrap zuerst, dann entfernen.
- **DEK ("Data Encryption Key")**: pro Datei (und implizit pro Feld) frisch erzeugt, 32 zufällige Bytes, **wird nie auf Disk im Klartext** abgelegt. DEK wird mit der KEK gewrappt und in `wp_gfb_files.dek_*` gespeichert. Beim Decrypt wird DEK in den RAM unwrappt, benutzt und mit `\0` überschrieben.

### 2.3 Schlüssel-Lifecycle (Key-Rotation)

1. Admin erzeugt einen neuen Key (z. B. via `openssl rand -base64 32`) und ergänzt `GFB_MASTER_KEYS`:
   ```php
   define( 'GFB_MASTER_KEYS',   '1:OLD_KEY_BASE64,2:NEW_KEY_BASE64' );
   define( 'GFB_ACTIVE_KEY_ID', '2' );
   ```
2. Neue Uploads/Felder werden ab sofort mit `2` verschlüsselt; bestehendes bleibt mit `1` lesbar.
3. **Re-Wrap-Cron** (`gfb_rewrap_cron`, stuendlich) verarbeitet 100 Files je Lauf und ersetzt deren `dek_*` durch eine Wrap-Version mit Key `2`. Inhalte selbst werden NICHT entschlüsselt — nur die Schlüsselhülle.
4. Sobald Settings-Seite zeigt: "0 Files mit alter Key-ID", kann Key `1` aus `GFB_MASTER_KEYS` entfernt werden.

### 2.4 Encrypted Fields ("sensitive")

Jedes Field-Block-Attribut hat optional `sensitive: true`. Server-Pfad:

- Beim Submit: `GFB_Crypto::encrypt_field( $value, 'field:<name>' )` → Envelope-Array `{_enc, key_id, iv, tag, ct}` wird im Submission-Payload-JSON abgelegt **statt** des Klartexts.
- Im Admin: nur Benutzer mit Cap `gfb_decrypt_submissions` sehen Klartext. Sonstige sehen `[verschlüsselt]` + Key-ID. Jede Anzeige eines entschlüsselten Datensatzes erzeugt einen `submission_decrypted`-Audit-Eintrag.
- E-Mail-Notifications enthalten **niemals** entschlüsselte Werte — die Mail-Strecke ist im Allgemeinen nicht vertrauenswürdig.

### 2.5 Encrypted Files

Datei-Inhalte werden **niemals** in `wp-content/uploads/` abgelegt.

- Default-Storage: `wp-content/.gfb-private/gfb-encrypted/<yyyy>/<mm>/<storage-id>.bin`. Per Filter `gfb_private_storage_dir` umkonfigurierbar (z. B. `/var/lib/gfb-storage` ausserhalb `wp-content/`).
- Bewusst Dot-Prefix-Verzeichnis: Apache (`mod_dir` Default) und Nginx (`location ~ /\.` Default) blockieren Dotfiles/Dot-Verzeichnisse out-of-the-box mit 403/404. Das hartet das Setup unabhängig vom Webserver-Wissen des Site-Admins.
- Datei-Permissions: `0600`. Plugin-`.htaccess` mit `Require all denied` (Apache). Plugin schreibt einen Nginx-Snippet-Hinweis in das Verzeichnis.
- **Selbsttest**: bei Aktivierung und per Settings-Button «Jetzt prüfen» schreibt das Plugin kurz eine Kanari-Datei, ruft die zugehörige URL per HTTP ab und löscht die Datei wieder. Liefert der Webserver **2xx** (Inhalt erreichbar), gilt der Test als fehlgeschlagen und eine Admin-Notice weist auf das Risiko hin (Wortlaut in den Einstellungen; Hinweis auf `INSTALL.md` / Webserver-Block).
- Originalname wird **nicht** im Pfad geführt (nur in DB).
- Download nur via `?action=gfb_download&fid=...&_wpnonce=...` im wp-admin-Kontext. Jeder Download streamt Klartext und schreibt `file_download_ok` ins Audit-Log.
- Der HTTP-Response des Downloads erzwingt:
  - `Content-Type: application/octet-stream`
  - `Content-Disposition: attachment`
  - `X-Content-Type-Options: nosniff`
  - `Content-Security-Policy: default-src 'none'`

---

## 3. Capability-Modell

| Cap | Bedeutung |
|---|---|
| `gfb_view_submissions` | Submissions-Liste/Detail; verschlüsselte Werte werden maskiert |
| `gfb_decrypt_submissions` | Klartext für verschlüsselte Felder im Admin |
| `gfb_delete_submissions` | Submissions löschen (inkl. zugehöriger verschlüsselter Files) |
| `gfb_download_files` | Verschlüsselte Datei-Anhänge entschlüsselt herunterladen |
| `gfb_view_audit` | Audit-Log lesen |
| `gfb_manage_settings` | Plugin-Einstellungen (ClamAV, Caps, Keys, Audit-Verifikation) |

Default: `administrator` bekommt alle. Konfigurierbar per Settings-Seite (Cap-Matrix Rolle x Cap).

---

## 4. ClamAV-Integration

- Konfiguration über Settings-Seite. Modi: `disabled`, `binary` (z. B. `/usr/bin/clamdscan`), `socket` (z. B. `/var/run/clamd.scan/clamd.sock`).
- **Pflicht-Modus** `require_for_uploads`: Standardmässig **deaktiviert** (opt-in). Auf Hostings ohne ClamAV-Möglichkeit bleibt das Plugin damit benutzbar. Wird der Pflicht-Modus aktiviert und ClamAV ist nicht erreichbar, werden alle Datei-Uploads abgelehnt. Der Button **«Verbindung testen (EICAR-Test)»** prüft die Verkabelung (erwartetes Ergebnis: `infected`).
- Aufruf via `proc_open` mit `escapeshellarg`-Argumenten und Timeout. Bei `infected`-Match wird die Datei **vor** Verschlüsselung verworfen.

---

## 5. Audit-Log

- Eigene Tabelle `wp_gfb_audit` (id, created_at, actor_user, actor_login, actor_ip, action, target_type, target_id, context_json, prev_hash, row_hash).
- `row_hash = sha256( prev_hash || canonical_payload )` → jede nachträgliche Änderung/Löschung bricht die Chain.
- Verifikation: Settings-Seite → Button "Hash-Chain prüfen" → meldet `ok` oder den ersten gebrochenen Eintrag.
- Aktionen, die geloggt werden (Auswahl, Stand Code): `plugin_activated`, `submission_insert`, `submission_deleted`, `submission_decrypted`, `file_encrypted`, `file_av_infected`, `file_download_ok`, `file_download_denied`, `file_download_failed`, `file_deleted`, `clamav_eicar_test`, `settings_clamav_saved`, `settings_caps_saved`, `audit_chain_verified`, `storage_reach_test`, `rewrap_batch`.

---

## 6. Wann reicht "server-at-rest" NICHT?

Bei einem Bedrohungsmodell, in dem der Webserver selbst nicht vertrauenswürdig ist (z. B. Shared Hosting, Compliance-Pflicht: "Provider darf Klartext nie sehen"), ist End-to-End-Verschlüsselung erforderlich:

1. Browser holt einen Public-Key des Empfängers (Konfiguration im Form-Block).
2. Browser erzeugt zufälligen Inhalts-Schlüssel, verschlüsselt Datei mit AES-GCM, verschlüsselt den Inhalts-Schlüssel mit dem Public-Key.
3. Server speichert nur den Ciphertext + die wrapped key.
4. Empfänger lädt das Paket im Admin und entschlüsselt **lokal** mit Private-Key (Browser-Modul oder externe CLI).

Das aktuelle Plugin liefert die Server-Seite (Storage, Rohablage als Ciphertext) bereits. Die fehlenden Bausteine für E2EE sind: Public-Key-Verteilung, Browser-Crypto (libsodium-js / `SubtleCrypto`), ein Frontend-Format (z. B. age oder PGP-ähnlich), und ein Admin-Download-Pfad, der **kein** Decrypt am Server macht.

---

## 7. Webserver-Konfigurationsempfehlungen

- HTTPS Pflicht (`X-Forwarded-Proto`-Awareness für IP-Erkennung). Filter `gfb_trusted_proxies` setzen.
- Apache: keine `php` execution in `wp-content/uploads/` (zusätzlich zur Plugin-`.htaccess`).
- Nginx (Default-Pfad): `location ^~ /wp-content/.gfb-private/ { deny all; return 403; }`. Falls du noch Legacy-Pfade aus älteren Tests hast: `location ^~ /wp-content/uploads-private/ { deny all; return 403; }`.
- Globale Header (HSTS, X-Frame-Options, Referrer-Policy) im Webserver setzen. Plugin-eigene Admin-Seiten setzen zusätzlich CSP/`X-Content-Type-Options`.
- Keine externen Skripte/iframes auf Admin-Seiten — das Plugin sendet eine restriktive CSP.

---

## 8. Reporting

Sicherheitslücken bitte vertraulich melden: **Platzhalter** `security@example.com` (und `SECURITY.txt`) durch die echte Kontaktadresse / Responsible-Disclosure-Policy deines Projekts ersetzen. Für interne Pentests ist `bin/security-selftest.sh` als Smoke-Test gedacht und kann eigene Tests ergänzen.
