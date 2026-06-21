# Auto-Publish: Release veröffentlichen, Server aktualisiert sich

Diese Anleitung beschreibt, wie ein neues GitHub-Release automatisch auf
`plugins.blitzdonner.ch` veröffentlicht wird. Der Workflow liegt unter
`.github/workflows/publish-update.yml`.

## Wie es funktioniert

1. Du veröffentlichst auf GitHub ein reguläres Release mit Tag `vX.Y.Z`.
2. GitHub Actions baut das Plugin-ZIP nach der bestehenden Konvention
   (Unterordner `gutenberg-formbuilder/`, ohne Marketing und `graphify-out`).
3. Vier Gates laufen vorab. Schlägt eines fehl, bricht der Lauf ab – es wird
   nichts gepostet:
   - **G-1** – `php -l` über alle `.php`-Dateien.
   - **G-2** – Plugin-Header-Version muss exakt dem Tag (ohne `v`) entsprechen.
   - **G-3** – ZIP-Struktur: genau ein Top-Level-Ordner `gutenberg-formbuilder/`,
     kein Marketing, kein `graphify-out`, Hauptdatei vorhanden.
   - **G-4** – Tag ist gültige SemVer `X.Y.Z`.
4. Der Workflow schickt das ZIP per `POST` an
   `https://plugins.blitzdonner.ch/wp-json/bd-updater/publish/gutenberg-formbuilder`,
   authentifiziert mit einem Deploy-Token.
5. Der Server prüft das ZIP erneut (volle Validierungskette), vergleicht die
   Header-Version mit der gemeldeten Version und legt die neue Version an.
   Antwortet er mit einem Status ungleich 2xx, wird der Lauf rot.

## Einmalige Einrichtung (drei Schritte)

### a) Deploy-Token im Server-Backend erzeugen

1. Im WordPress-Backend von `plugins.blitzdonner.ch` zu **Plugin Updater →
   Deploy-Tokens** wechseln.
2. Unter «Neues Deploy-Token erzeugen» eine Bezeichnung eingeben
   (z.B. «GitHub Actions formbuilder») und als Plugin-Slug
   `gutenberg-formbuilder` wählen.
3. Auf **Deploy-Token erzeugen** klicken. Das Token wird **nur einmal** im
   Klartext angezeigt – sofort kopieren.

Deploy-Tokens sind strikt von den Lizenz-Tokens getrennt und an genau diesen
einen Slug gebunden. Mehrere aktive Tokens pro Slug sind erlaubt (Rotation).

### b) Token als GitHub-Environment-Secret hinterlegen

1. Im Repo `BlitzDonner/gutenberg-formbuilder` zu **Settings → Environments**
   gehen und ein Environment **`production`** anlegen (falls noch nicht
   vorhanden).
2. Dort unter **Environment secrets** ein Secret **`BD_DEPLOY_TOKEN`** mit dem
   kopierten Token-Klartext anlegen.

Der Workflow liest das Secret ausschliesslich aus diesem Environment. Das Token
wird nie geloggt oder ausgegeben.

### c) Workflow nutzen (Release veröffentlichen)

1. Plugin-Header-Version in `gutenberg-formbuilder.php` auf die neue Version
   setzen (z.B. `2.8.0`) und committen.
2. Auf GitHub ein **reguläres** Release mit Tag `v2.8.0` veröffentlichen
   (kein Draft, kein Pre-Release). Der Release-Text wird als Changelog
   übernommen.
3. Den Lauf unter **Actions** beobachten. Grün = Version ist live. Rot =
   ein Gate oder der Server hat abgelehnt; die Logs nennen den Grund.

## Wichtige Regeln

- **Pre-Releases und Drafts lösen nichts aus.** Nur reguläre, veröffentlichte
  Releases werden publiziert.
- **Eine ausgelieferte Versionsnummer ist endgültig.** Wurde eine Version
  bereits an einen Client ausgeliefert, lehnt der Server einen erneuten Publish
  mit HTTP 409 ab. Dann eine höhere Nummer wählen.
- **Token kompromittiert?** Im Backend unter Deploy-Tokens sofort **Sperren** –
  die Sperre wirkt unmittelbar. Danach ein neues Token erzeugen und das Secret
  ersetzen.

## Noch nicht enthalten (nächster Schritt)

Die **Ed25519-Signatur** des ZIP und ihre serverseitige Prüfung sind der nächste
verbindliche Ausbauschritt (Mittelweg). Bis dahin sichern das Deploy-Token, die
vier Gates, die serverseitige ZIP- und Versionsprüfung sowie das Rate-Limit den
Publish-Pfad ab. Ebenfalls später folgt ein Smoke-Test-Gate.
