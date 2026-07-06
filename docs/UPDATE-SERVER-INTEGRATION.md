# Update-Server-Integration

Diese Doku beschreibt, wie der Blitz & Donner Formular seine Updates vom selbst gehosteten Update-Server `plugins.blitzdonner.ch` bezieht. Sie richtet sich an Entwickler, die den Mechanismus warten oder in ein weiteres Agentur-Plugin übertragen.

## Überblick

Der Formbuilder erscheint **nicht** im offiziellen WordPress-Plugin-Verzeichnis. Updates kommen vom Update-Server von Blitz & Donner. Den Bezug erledigt ein eingebetteter Client: `includes/class-bd-update-client.php` (Klasse `BD_Update_Client`). Er klinkt sich in die WordPress-Standard-Update-API ein, sodass Updates wie bei jedem anderen Plugin im wp-admin erscheinen.

Der Server selbst lebt im separaten Projekt `bd-plugin-updater` (Server-Plugin plus Quelle des Clients). Diese Doku beschreibt nur die Client-Seite im Formbuilder.

## Initialisierung

Die Hauptdatei `gutenberg-formbuilder.php` lädt und startet den Client auf dem `plugins_loaded`-Hook (Priorität 1, damit der Filter `pre_set_site_transient_update_plugins` registriert ist, bevor WordPress den Transient befüllt):

```php
new BD_Update_Client( array(
    'plugin_file' => GFB_PLUGIN_FILE,
    'slug'        => 'gutenberg-formbuilder',
    'server_url'  => 'https://plugins.blitzdonner.ch',
    'version'     => GFB_PLUGIN_VERSION,
    'option_key'  => 'gfb_update_token',
    'const_key'   => 'GFB_UPDATE_TOKEN',
) );
```

Der `slug` muss mit dem Slug übereinstimmen, unter dem das Plugin auf dem Server registriert ist.

## Wie der Client arbeitet

Der Client hängt sich in drei WordPress-Filter und einen Admin-Hook:

1. **`pre_set_site_transient_update_plugins`** – Beim periodischen Update-Check fragt der Client den Server-Endpunkt `POST /bd-updater/check/{slug}` ab. Ist die gemeldete Server-Version höher als die installierte (`version_compare`), trägt er einen Update-Eintrag in den Transient ein: neue Version, Download-URL und die erwartete SHA-256-Prüfsumme. Ist keine neuere Version vorhanden, landet das Plugin im `no_update`-Zweig.

2. **`plugins_api`** – Liefert das Detail-Popup («Details anzeigen») mit Name, Version, Mindest-WP/-PHP und Changelog. Die Daten stammen aus derselben Server-Antwort.

3. **`upgrader_pre_download`** – Greift beim eigentlichen Download ein. Der Client lädt das ZIP über `POST /bd-updater/download/{slug}` mit dem Token im `Authorization: Bearer`-Header in eine Temp-Datei. Danach prüft er zuerst die **SHA-256-Prüfsumme** gegen den Wert aus dem Update-Transient, anschliessend die **Ed25519-Signatur** (siehe Abschnitt «Signaturprüfung»). Schlägt eine der beiden Prüfungen fehl, bricht er die Installation ab (`WP_Error`, kein Eintrag ins Dateisystem).

4. **`admin_notices`** – Zeigt bei abgelaufenem oder gesperrtem Token einen dezenten Hinweis: «Lizenz abgelaufen – keine Updates. Das Plugin funktioniert weiterhin normal.» Fehlt die PHP-Erweiterung libsodium, erscheint zusätzlich eine Notice, die erklärt, warum automatische Updates deaktiviert sind.

Innerhalb eines Requests cacht der Client die Server-Antwort, um Mehrfach-Abfragen zu vermeiden.

**Ohne Token (seit 2.9.4):** Der Client fragt den Server auch ohne hinterlegtes Token an – der `Authorization`-Header wird nur gesendet, wenn ein Token vorhanden ist. Der Server entscheidet: Als frei markierte Plugins (z.B. Blitz & Donner PDF) liefern tokenlos aus, lizenzpflichtige wie der Formbuilder antworten mit 403. Wichtig, weil die Klasse `BD_Update_Client` von allen B&D-Plugins einer Installation geteilt wird (die zuerst geladene Kopie gewinnt): Eine veraltete Kopie hier würde tokenfreie Plugins derselben Installation blockieren.

## Token via wp-config-Konstante

Das Token gehört **nicht** in den Plugin-Code. Es wird pro Kundeninstallation in `wp-config.php` gesetzt:

```php
define( 'GFB_UPDATE_TOKEN', 'das-token-vom-server' );
```

Der Client liest das Token aus dieser Konstante (`const_key` => `GFB_UPDATE_TOKEN`). Übertragen wird es immer im `Authorization`-Header, nie als URL-Parameter. Das Token erzeugt der Update-Server; es wird dort nur einmal im Klartext angezeigt.

## Token im Backend hinterlegen (Alternative)

Wer keinen Zugriff auf `wp-config.php` hat, trägt das Token im Backend ein: unter **Formular-Einträge → Sicherheit** im Abschnitt **«Lizenz / Updates»**. Der Client liest es dann aus der Option `gfb_update_token` (`option_key` => `gfb_update_token`, gespeichert mit `autoload=false`).

**Vorrang:** Die Konstante `GFB_UPDATE_TOKEN` hat immer Vorrang. Ist sie gesetzt, ignoriert der Handler jede Backend-Eingabe, das Feld erscheint deaktiviert und der DB-Wert wird durch ein Speichern nicht überschrieben. Die Konstante bleibt der empfohlene Weg, weil sie nicht in der Datenbank liegt.

Das Feld ist ein Passwort-Feld; das gespeicherte Token wird nie im Klartext ausgegeben. Leeres Absenden behält den vorhandenen Wert. Der Knopf **«Token testen»** prüft das hinterlegte Token sofort gegen den Server, unabhängig vom Update-Takt; das Token steht dabei nie in der URL, sondern nur im `Authorization`-Header. Im Audit-Log wird beim Speichern nur das Flag `token_set: yes/no` festgehalten, nie das Token selbst.

## Verhalten bei ungültigem Token (GPL-Grenze)

Bei abgelaufenem, gesperrtem oder fehlendem Token verweigert der Client **nur die Updates** und zeigt einen Hinweis. Er deaktiviert das Plugin **niemals** und schaltet keine Funktion ab. Es gibt **keinen Killswitch**. Das Plugin bleibt unter der GPL voll funktionsfähig. Diese Grenze ist bewusst gesetzt und darf nicht aufgeweicht werden.

## Signaturprüfung (Pflicht)

Seit Version 2.7.3 prüft der eingebettete Client **verpflichtend** eine Ed25519-Signatur jedes Pakets. Der Schalter `REQUIRE_SIGNATURE` in `class-bd-update-client.php` steht fest auf `true` (Pflicht-Modus).

Ablauf: Nach der SHA-256-Prüfung liest der Client die mitgelieferte Signatur (base64) aus dem Update-Transient und prüft sie mit `sodium_crypto_sign_verify_detached` gegen die eingebettete Liste akzeptierter Public Keys (`ACCEPTED_KEYS`). Stimmt die Signatur mit einem vertrauten Schlüssel überein, läuft die Installation weiter; sonst bricht sie ab.

Verweigert wird das Update in folgenden Fällen – jeweils nur das Update, **kein Killswitch**, das Plugin bleibt voll funktionsfähig:

- Das Paket liefert keine Signatur.
- Die Signatur ist im Format ungültig oder passt zu keinem vertrauten Schlüssel.
- Die PHP-Erweiterung libsodium fehlt (zusätzlich erscheint eine Admin-Notice).

**Schlüssel-Inhaber:** Der Produktiv-Key gehört Blitz & Donner / Stefan (erzeugt am 21.06.2026), Key-ID `8337dfb76e01b82d`. Die eingebettete Liste `ACCEPTED_KEYS` muss mit der Server-Liste (`class-bdpus-keys.php` im Projekt `bd-plugin-updater`) konsistent bleiben.

**Folge für die Veröffentlichung:** Ab 2.7.3 muss **jede** neue Version signiert publiziert werden. Ein unsigniertes Paket lässt sich auf einer 2.7.3-Installation nicht mehr installieren. Das Signieren und Publizieren ist Aufgabe des Update-Servers (Projekt `bd-plugin-updater`).

## Sicherheit auf einen Blick

- Token nur im `Authorization: Bearer`-Header, nie in der URL.
- SHA-256-Prüfung nach dem Download; bei Abweichung keine Installation.
- Ed25519-Signaturprüfung (Pflicht) nach der SHA-256-Prüfung; bei fehlender oder ungültiger Signatur sowie bei fehlendem libsodium keine Installation.
- HTTP 403 vom Server bei ungültigem Token führt nur zur Update-Verweigerung, nicht zur Funktionssperre.
- Backend-Token in Option `gfb_update_token` mit `autoload=false`; nie im Klartext gerendert; Konstanten-Vorrang serverseitig im Handler erzwungen; Audit nur mit `token_set`-Flag.

## Verweis auf den Server

Der Server (`plugins.blitzdonner.ch`) lebt im Projekt `bd-plugin-updater`. Dort sind die Endpunkte, die Token-Verwaltung, die Republish-Regel und die Server-Härtung dokumentiert.

## Changelog

- **2.7.3** – Ed25519-Signaturprüfung als Pflicht im eingebetteten Client (`REQUIRE_SIGNATURE = true`). Jedes Paket muss eine gültige Signatur eines vertrauten Schlüssels (`ACCEPTED_KEYS`) mitliefern; sonst bricht das Update ab. Fehlt libsodium, wird das Update verweigert und eine Admin-Notice erklärt die Ursache. Kein Killswitch – das Plugin bleibt funktionsfähig. Ab dieser Version müssen neue Versionen signiert publiziert werden.
