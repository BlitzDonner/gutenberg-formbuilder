# Veröffentlichen: Qualitätsgate automatisch, signiertes Publizieren manuell

Diese Anleitung beschreibt zwei Dinge: das automatische Qualitätsgate bei
einem GitHub-Release und den manuellen, signierten Veröffentlichungsweg auf
`plugins.blitzdonner.ch`. Der Workflow liegt unter
`.github/workflows/publish-update.yml`.

## Der einzige Weg auf eine Website

Ein Plugin wird nie auf einer Website aktualisiert, sondern nur auf dem
Update-Server hinterlegt. Die Installationen holen sich die neue Fassung
selbst. Die Arbeit endet mit dem erfolgreichen Publish auf
`plugins.blitzdonner.ch`.

Die Kette von der Änderung bis zur Auslieferung:

1. **Code ändern.** Version an **beiden** Stellen heben: Plugin-Header und
   die Konstante `GFB_PLUGIN_VERSION`. Changelog schreiben.
2. **Lokal prüfen** – eigene Testumgebung, niemals eine Kundenseite.
   Danach abräumen und den Ausgangszustand herstellen.
3. **Commit und Push** auf `main`.
4. **Tag `vX.Y.Z` setzen und auf GitHub ein Release veröffentlichen.** Erst
   damit laufen die Qualitätsgates (siehe unten).
5. **ZIP bauen** nach der Konvention: genau ein Top-Level-Ordner, ohne
   `.git`, `.github`, `bin`, `tests`, Marketing, `graphify-out`.
6. **Signieren** mit dem Produktivschlüssel (`bd-sign.php`, Ed25519).
7. **Publizieren** per Deploy-Token an den Endpunkt auf
   `plugins.blitzdonner.ch`.
8. **Fertig.** Jede Installation mit gültigem Lizenz-Token zieht selbst nach.

### Was dabei nie passiert

- **Keine Kundenseite anfassen.** Kein `wp plugin update`, `install` oder
  `delete`, kein rsync, kein FTP, kein Einspielen von Hand – weder auf
  Live-Seiten noch auf Staging-Seiten von Kunden.
- **Kein Lizenz-Token setzen.** Eine Installation ohne Token bekommt bewusst
  keine automatischen Updates. Das ist eine Geschäftsentscheidung, keine
  Lücke, die es zu schliessen gilt.
- **Keine Teillieferung.** Es gibt keine Fassung für einen einzelnen Kunden.
  Was veröffentlicht wird, gilt für alle.

### Kontrolle statt Eingriff

Welche Installation welche Fassung fährt, steht im Log des Update-Servers
(`wp_bdpus_log`, Feld `domain`). Bleibt eine Installation zurück, ist das
eine Meldung an Stefan – kein Auftrag, dort einzugreifen.

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
3. Fünf Gates laufen. Schlägt eines fehl, bricht der Lauf ab und wird rot:
   - **G-1** – `php -l` über alle `.php`-Dateien.
   - **G-2** – Plugin-Header-Version muss exakt dem Tag (ohne `v`) entsprechen.
   - **G-2b** – die Konstante `GFB_PLUGIN_VERSION` muss ebenfalls dem Tag
     entsprechen. Der Update-Client meldet dem Server diese Konstante, nicht
     den Header.
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

1. **Version heben – an beiden Stellen.** In `gutenberg-formbuilder.php`
   sowohl die Header-Zeile `Version:` als auch die Konstante
   `GFB_PLUGIN_VERSION` auf die neue Nummer setzen (z.B. `2.8.0`) und
   committen. Danach Tag `v2.8.0` setzen und auf GitHub ein Release
   veröffentlichen, damit die Qualitätsgates laufen.
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
- **Der Publish ist der letzte Schritt.** Danach folgt kein Zugriff auf
  Websites. Siehe «Der einzige Weg auf eine Website» am Anfang dieser Datei.
- **Version an zwei Stellen.** Plugin-Header und `GFB_PLUGIN_VERSION` müssen
  übereinstimmen. Der Update-Client meldet dem Server die Konstante, nicht den
  Header; laufen sie auseinander, bietet der Server nach jeder Installation
  erneut ein Update an. Gate G-2b bricht deshalb ab (Befund 23.08.2026,
  Fassung 2.14.0: 33 Auslieferungen an dieselben sechs Installationen).
