# Sicherheitskonzept und Begründung

Dieses Dokument erklärt, warum Gutenberg Formbuilder sicher per Konzept ist. Es richtet sich an Sicherheitsverantwortliche in Schweizer Unternehmen, die beurteilen müssen, ob das Plugin für kritische Formulare geeignet ist.

## Kurzfazit

Gutenberg Formbuilder schützt Formulardaten nicht mit einer einzelnen Massnahme. Das Plugin kombiniert serverseitige Validierung, private Dateiablage, starke Verschlüsselung, getrennte Berechtigungen, manipulationsresistente Audit-Logs und gehärtete Admin-Oberflächen.

Der wichtigste Grundsatz lautet: Eingehende Daten gelten nie als vertrauenswürdig. Das Plugin prüft jede Einreichung auf dem Server, rekonstruiert das Formularschema aus dem Block-Tree und verarbeitet nur Daten, die zu diesem Schema passen.

## Sicherheit entsteht im Konzept

Das Plugin verlässt sich nicht auf JavaScript im Browser. Es verlässt sich auch nicht auf gespeichertes Block-HTML aus dem Editor. Beides kann manipuliert, veraltet oder unvollständig sein.

Stattdessen nutzt das Plugin serverseitige Verarbeitung:

- Der Submit-Handler prüft Nonce, HMAC-Token, Honeypot und Rate-Limit.
- Das Formularschema wird serverseitig aus den gespeicherten Blöcken gelesen.
- Jedes Feld wird gegen seinen erwarteten Typ validiert.
- Sensible Felder werden vor dem Speichern verschlüsselt.
- Datei-Uploads durchlaufen mehrere Prüfungen, bevor sie gespeichert werden.

Damit liegt die Sicherheitslogik dort, wo sie hingehört: auf dem Server.

## Schutz der Formularübermittlung

Jede Formularübermittlung wird technisch abgesichert. Das Plugin nutzt mehrere Kontrollen gegen automatisierte Angriffe und manipulierte Requests:

- WordPress-Nonce gegen Cross-Site Request Forgery.
- HMAC-basierte Anti-Replay-Tokens gegen wiederverwendete Requests.
- Dynamischer Honeypot gegen einfache Bots.
- IP-basiertes Rate-Limiting gegen Flooding.
- Whitelist für Statuswerte und sichere Weiterleitungen mit `wp_safe_redirect`.

Diese Massnahmen reduzieren Spam, Replay-Angriffe, manipulierte Redirects und ungültige Formularzustände.

## Serverseitige Feldvalidierung

Das Plugin prüft Eingaben nach Feldtyp. Eine E-Mail muss eine E-Mail sein, eine URL muss ein erlaubtes Schema nutzen, Zahlen müssen Zahlen sein, Optionen müssen zu den definierten Auswahlwerten passen.

Das ist wichtig, weil der Browser keine Sicherheitsgrenze ist. HTML-Attribute wie `required`, `type=email` oder `accept` helfen der Benutzerführung. Sie schützen aber nicht gegen gezielte Requests. Gutenberg Formbuilder erzwingt deshalb alle relevanten Regeln nochmals serverseitig.

## Sensible Felder werden verschlüsselt

Felder können im Editor als sensibel markiert werden. Solche Werte speichert das Plugin nicht im Klartext. Stattdessen speichert es ein verschlüsseltes Envelope-Objekt mit Key-ID, IV, Auth-Tag und Ciphertext.

Nur Benutzer mit der Capability `gfb_decrypt_submissions` dürfen solche Werte im Admin entschlüsseln. Benutzer ohne diese Berechtigung sehen maskierte Werte.

Auch E-Mail-Benachrichtigungen enthalten keine entschlüsselten sensiblen Werte. Das ist bewusst so, weil E-Mail-Transport und Mailboxen oft nicht als sicherer Speicher gelten.

## Datei-Uploads: geprüft, privat, verschlüsselt

Datei-Uploads sind der kritischste Teil eines Formularplugins. Gutenberg Formbuilder behandelt sie deshalb besonders streng.

Vor dem Speichern prüft das Plugin:

- erlaubte Dateiendungen,
- gefährliche oder doppelte Endungen,
- MIME-Typen mit `fileinfo`,
- Dateigrösse,
- optional ClamAV, wenn der Server einen Virenscanner bereitstellt.

Erst danach wird die Datei verschlüsselt und gespeichert.

Die Datei landet nie als normale Datei in `wp-content/uploads/`. Sie erhält keine öffentliche Medien-URL. Standardmässig speichert das Plugin verschlüsselte Dateien unter:

```text
wp-content/.gfb-private/gfb-encrypted/
```

Dieses Dot-Verzeichnis wird von üblichen Apache- und Nginx-Konfigurationen nicht ausgeliefert. Zusätzlich legt das Plugin Schutzdateien an und bietet einen Selbsttest in den Einstellungen. Der Test legt kurz eine harmlose Testdatei ab, ruft sie per HTTP auf und prüft, ob der Webserver den Zugriff verweigert. So erkennt der Betreiber eine Fehlkonfiguration sofort.

## Starke Verschlüsselung

Das Plugin nutzt AES-256-GCM. Dieser Modus verschlüsselt Daten und prüft ihre Integrität in einem Schritt. Manipulierte Ciphertexts werden nicht entschlüsselt.

Für Dateien nutzt das Plugin Envelope Encryption:

- Pro Datei entsteht ein eigener zufälliger Datenschlüssel.
- Dieser Datenschlüssel verschlüsselt den Dateiinhalt.
- Der Datenschlüssel selbst wird mit einem Master-Key verschlüsselt.
- Der Master-Key liegt in `wp-config.php`, nicht in der Datenbank.

Ein Datenbankdiebstahl reicht deshalb nicht aus, um Datei-Inhalte zu lesen. Ein reines Dateisystem-Backup enthält ebenfalls nur verschlüsselte Inhalte.

## Schlüsselrotation

Das Plugin unterstützt mehrere Master-Keys. Ein Key ist aktiv und verschlüsselt neue Daten. Ältere Keys bleiben lesbar, bis alle alten Daten umgestellt sind.

Für Datei-Schlüssel gibt es einen Re-Wrap-Prozess. Dabei wird nur der verschlüsselte Datenschlüssel neu verpackt. Der Dateiinhalt selbst muss nicht vollständig entschlüsselt und neu geschrieben werden.

Das senkt das Risiko bei Schlüsselrotation und macht den Vorgang betrieblich kontrollierbar.

## Rechte werden getrennt

Das Plugin nutzt eigene WordPress-Capabilities. Dadurch muss ein Benutzer nicht automatisch alles dürfen, nur weil er Submissions sehen darf.

Wichtige Berechtigungen sind:

- `gfb_view_submissions`: Einreichungen sehen.
- `gfb_decrypt_submissions`: sensible Felder entschlüsseln.
- `gfb_download_files`: Dateien entschlüsselt herunterladen.
- `gfb_delete_submissions`: Einreichungen löschen.
- `gfb_view_audit`: Audit-Log lesen.
- `gfb_manage_settings`: Sicherheitseinstellungen verwalten.

Diese Trennung unterstützt das Least-Privilege-Prinzip. Eine Datenschutzrolle kann zum Beispiel Audit-Logs prüfen, ohne Dateien herunterladen zu dürfen. Eine Sachbearbeitung kann Einreichungen bearbeiten, ohne Einstellungen zu ändern.

## Audit-Log mit Hash-Chain

Sicherheitsrelevante Aktionen werden protokolliert. Dazu gehören:

- neue Einreichungen,
- gelöschte Einreichungen,
- entschlüsselte Datensätze,
- Datei-Downloads,
- verweigerte Downloads,
- infizierte Datei-Uploads,
- ClamAV-Tests,
- Änderungen an Berechtigungen,
- Key-Re-Wraps.

Das Audit-Log nutzt eine Hash-Chain. Jede Zeile enthält den Hash der vorherigen Zeile. Wird eine alte Zeile verändert oder gelöscht, bricht die Kette. Die Integrität lässt sich in den Plugin-Einstellungen prüfen.

Das schützt nicht davor, dass ein privilegierter Benutzer etwas tut. Es macht solche Aktionen aber nachvollziehbar.

## Schutz vor Stored XSS

Gutenberg-Blöcke speichern HTML im Inhalt. Das kann bei Formularen gefährlich sein, wenn dieses HTML als vertrauenswürdig gilt.

Gutenberg Formbuilder vermeidet diese Annahme. Alle Feldblöcke werden serverseitig über Render-Callbacks erzeugt. Das gespeicherte Block-HTML ist nicht die Vertrauensbasis für das Frontend.

Zusätzlich begrenzt `wp_kses` die erlaubten HTML-Strukturen. Dadurch sinkt das Risiko, dass manipuliertes Block-Markup JavaScript oder gefährliche Attribute ins Frontend bringt.

## Gehärtete Admin- und Download-Pfade

Plugin-eigene Admin-Seiten setzen zusätzliche Sicherheitsheader. Dazu gehören `X-Content-Type-Options` und eine restriktive Content-Security-Policy.

Datei-Downloads laufen nur über einen authentifizierten Admin-Endpunkt mit Nonce und Capability-Prüfung. Der Download-Response setzt:

- `Content-Type: application/octet-stream`,
- `Content-Disposition: attachment`,
- `X-Content-Type-Options: nosniff`,
- `Content-Security-Policy: default-src 'none'`.

Der Browser soll heruntergeladene Inhalte nicht interpretieren. Er soll sie als Anhang behandeln.

## ClamAV als zusätzliche Schutzschicht

ClamAV ist optional. Das ist bewusst so, weil viele Shared-Hosting-Umgebungen keine Installation eines Virenscanners erlauben.

Wenn ClamAV verfügbar ist, kann das Plugin Dateien vor dem Speichern scannen. Unterstützt werden `clamscan`/`clamdscan` als Binary und `clamd` über Unix-Socket. Ein EICAR-Test prüft die Verkabelung.

Für besonders strikte Umgebungen gibt es einen Pflichtmodus. Ist er aktiv und ClamAV fällt aus, werden Datei-Uploads abgelehnt. Ohne Pflichtmodus bleibt das Plugin auch auf Hostings ohne ClamAV nutzbar.

Wichtig: Die Verschlüsselung funktioniert unabhängig von ClamAV.

## Was das Plugin nicht verspricht

Das Plugin schützt Daten auf dem Server und in Backups. Dieses Modell heisst serverseitige Verschlüsselung «at rest».

Wenn ein Angreifer den PHP-Prozess oder den Server vollständig übernimmt, kann er auf den Master-Key in `wp-config.php` zugreifen. Dann kann er Daten entschlüsseln. Dieses Restrisiko ist bei serverseitiger Verschlüsselung grundsätzlich vorhanden.

Wenn selbst der Serverbetreiber oder Hosting-Provider niemals Klartext sehen darf, braucht es echte Ende-zu-Ende-Verschlüsselung. Dann müsste der Browser vor dem Upload verschlüsseln, und der Server dürfte nur Ciphertext speichern.

## Gesamtbeurteilung

Gutenberg Formbuilder ist sicher per Konzept, weil es Angriffe an mehreren Stellen begrenzt:

- vor der Verarbeitung durch Request-Schutz,
- während der Verarbeitung durch serverseitige Validierung,
- beim Speichern durch Verschlüsselung,
- bei Dateien durch private Ablage ohne öffentliche URL,
- im Admin durch getrennte Berechtigungen,
- im Betrieb durch Audit-Logs und Selbsttests,
- im Browser durch sichere Header und Download-Behandlung.

Das Plugin setzt nicht auf Verschleierung. Es setzt auf klare Sicherheitsgrenzen, überprüfbare Kontrollen und nachvollziehbare Betriebsprozesse. Für ein Schweizer Unternehmen bedeutet das: Sicherheitsverantwortliche können die Massnahmen prüfen, testen und in interne Kontrollprozesse einordnen.
