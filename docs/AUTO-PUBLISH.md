# Veröffentlichen: Qualitätsgate automatisch, signiertes Publizieren manuell

Diese Anleitung beschreibt zwei Dinge: das automatische Qualitätsgate bei
einem GitHub-Release und den manuellen, signierten Veröffentlichungsweg auf
`plugins.blitzdonner.ch`. Der Workflow liegt unter
`.github/workflows/publish-update.yml`.

## Warum kein Auto-Publish mehr

Der Update-Server verlangt seit Version 1.4.0 eine gültige Ed25519-Signatur
und lehnt unsignierte Pakete mit HTTP 422 ab. Die Signatur entsteht mit einem
privaten Schlüssel, der bewusst nicht in GitHub Actions liegt. Ein
automatischer POST aus der CI wäre darum unsigniert und würde scheitern.

Die CI prüft deshalb nur noch die Qualität. Das eigentliche, signierte
Veröffentlichen ist ein bewusster manueller Schritt auf dem lokalen Rechner.

## Was die CI macht (Qualitätsgate)

1. Du veröffentlichst auf GitHub ein reguläres Release mit Tag `vX.Y.Z`.
2. GitHub Actions baut das Plugin-ZIP nach der bestehenden Konvention
   (Unterordner `gutenberg-formbuilder/`, ohne Marketing, `graphify-out`,
   `bin/` und `.sh`).
3. Vier Gates laufen. Schlägt eines fehl, bricht der Lauf ab und wird rot:
   - **G-1** – `php -l` über alle `.php`-Dateien.
   - **G-2** – Plugin-Header-Version muss exakt dem Tag (ohne `v`) entsprechen.
   - **G-3** – ZIP-Struktur: genau ein Top-Level-Ordner `gutenberg-formbuilder/`,
     kein Marketing, kein `graphify-out`, kein `bin/`, keine `.sh`, Hauptdatei
     vorhanden.
   - **G-4** – Tag ist gültige SemVer `X.Y.Z`.
4. Am Schluss gibt der Lauf einen Hinweis aus, dass das signierte
   Veröffentlichen manuell erfolgt – mit Verweis auf diese Datei.

Der Workflow schickt **nichts** mehr an den Server und braucht kein
Deploy-Token-Secret.

## Signiertes Veröffentlichen (manueller Schritt)

Voraussetzung: der private Signaturschlüssel und ein gültiges Deploy-Token
liegen lokal vor. Das Deploy-Token wird im Server-Backend erzeugt (siehe
unten) und niemals geloggt.

1. **Version heben.** Plugin-Header-Version in `gutenberg-formbuilder.php` auf
   die neue Version setzen (z.B. `2.8.0`) und committen. Optional ein
   GitHub-Release `v2.8.0` veröffentlichen, damit das Qualitätsgate läuft.
2. **ZIP lokal bauen.** Das Paket nach derselben Konvention wie die CI bauen:
   genau ein Top-Level-Ordner `gutenberg-formbuilder/`, ohne `.git`, `.github`,
   `node_modules`, `bin`, `*.sh`, `graphify-out`, Marketing und Build-Reste.
3. **Signieren.** Das ZIP mit dem Signaturwerkzeug `bd-sign.php` aus dem
   Repo `bd-plugin-updater` (`tools/bd-sign.php`) signieren:

   ```
   php /Pfad/zu/bd-plugin-updater/tools/bd-sign.php sign gutenberg-formbuilder.zip
   ```

   Das Werkzeug gibt die base64-Signatur und die `key_id` aus (erste 16 Hex
   des Public Keys).
4. **Hochladen.** ZIP samt Signatur und `key_id` per Deploy-Token an den
   Publish-Endpunkt senden:

   ```
   curl -sS -X POST \
     "https://plugins.blitzdonner.ch/wp-json/bd-updater/publish/gutenberg-formbuilder" \
     -H "Authorization: Bearer <DEPLOY_TOKEN>" \
     -F "zip=@gutenberg-formbuilder.zip;type=application/zip" \
     -F "version=2.8.0" \
     -F "signature=<BASE64_SIGNATUR>" \
     -F "key_id=<KEY_ID>" \
     -F "changelog=<changelog.txt"
   ```

   Antwortet der Server mit `201` (oder `200` beim erlaubten Republish), ist
   die Version live. `422` bedeutet fehlende oder ungültige Signatur, `409`
   eine bereits ausgelieferte oder zu niedrige Nummer.

Alternativ geht der Upload über das Backend (**Plugin Updater → Plugins →
Version publizieren**). Auch dort sind Signatur und `key_id` jetzt Pflicht –
kein Bypass über das Backend.

## Deploy-Token erzeugen

1. Im WordPress-Backend von `plugins.blitzdonner.ch` zu **Plugin Updater →
   Deploy-Tokens** wechseln.
2. Unter «Neues Deploy-Token erzeugen» eine Bezeichnung eingeben
   (z.B. «formbuilder manuell») und als Plugin-Slug `gutenberg-formbuilder`
   wählen.
3. Auf **Deploy-Token erzeugen** klicken. Das Token wird **nur einmal** im
   Klartext angezeigt – sofort kopieren und sicher ablegen.

Deploy-Tokens sind strikt von den Lizenz-Tokens getrennt und an genau diesen
einen Slug gebunden. Mehrere aktive Tokens pro Slug sind erlaubt (Rotation).

## Wichtige Regeln

- **Pre-Releases und Drafts lösen das Gate nicht aus.** Nur reguläre,
  veröffentlichte Releases.
- **Eine ausgelieferte Versionsnummer ist endgültig.** Wurde eine Version
  bereits an einen Client ausgeliefert, lehnt der Server einen erneuten
  Publish mit HTTP 409 ab. Dann eine höhere Nummer wählen.
- **Unsignierte Pakete werden abgelehnt.** Ohne gültige Signatur antwortet der
  Server mit HTTP 422 – auf beiden Wegen, REST und Backend.
- **Token kompromittiert?** Im Backend unter Deploy-Tokens sofort **Sperren** –
  die Sperre wirkt unmittelbar. Danach ein neues Token erzeugen.
