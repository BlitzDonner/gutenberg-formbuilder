# Update-Server-Integration

Diese Doku beschreibt, wie der Gutenberg Formbuilder seine Updates vom selbst gehosteten Update-Server `plugins.blitzdonner.ch` bezieht. Sie richtet sich an Entwickler, die den Mechanismus warten oder in ein weiteres Agentur-Plugin übertragen.

## Überblick

Der Formbuilder erscheint **nicht** im offiziellen WordPress-Plugin-Verzeichnis. Updates kommen vom Update-Server von Blitz & Donner. Den Bezug erledigt ein eingebetteter Client: `includes/class-bd-update-client.php` (Klasse `BD_Update_Client`). Er klinkt sich in die WordPress-Standard-Update-API ein, sodass Updates wie bei jedem anderen Plugin im wp-admin erscheinen.

Der Server selbst lebt im separaten Projekt `bd-plugin-updater` (Server-Plugin plus Quelle des Clients). Diese Doku beschreibt nur die Client-Seite im Formbuilder.

## Initialisierung

Die Hauptdatei `gutenberg-formbuilder.php` lädt und startet den Client auf dem `init`-Hook (Priorität 20):

```php
new BD_Update_Client( array(
    'plugin_file' => GFB_PLUGIN_FILE,
    'slug'        => 'gutenberg-formbuilder',
    'server_url'  => 'https://plugins.blitzdonner.ch',
    'version'     => GFB_PLUGIN_VERSION,
    'const_key'   => 'GFB_UPDATE_TOKEN',
) );
```

Der `slug` muss mit dem Slug übereinstimmen, unter dem das Plugin auf dem Server registriert ist.

## Wie der Client arbeitet

Der Client hängt sich in drei WordPress-Filter und einen Admin-Hook:

1. **`pre_set_site_transient_update_plugins`** – Beim periodischen Update-Check fragt der Client den Server-Endpunkt `POST /bd-updater/check/{slug}` ab. Ist die gemeldete Server-Version höher als die installierte (`version_compare`), trägt er einen Update-Eintrag in den Transient ein: neue Version, Download-URL und die erwartete SHA-256-Prüfsumme. Ist keine neuere Version vorhanden, landet das Plugin im `no_update`-Zweig.

2. **`plugins_api`** – Liefert das Detail-Popup («Details anzeigen») mit Name, Version, Mindest-WP/-PHP und Changelog. Die Daten stammen aus derselben Server-Antwort.

3. **`upgrader_pre_download`** – Greift beim eigentlichen Download ein. Der Client lädt das ZIP über `POST /bd-updater/download/{slug}` mit dem Token im `Authorization: Bearer`-Header in eine Temp-Datei. Danach prüft er die **SHA-256-Prüfsumme** gegen den Wert aus dem Update-Transient. Stimmt sie nicht überein, bricht er die Installation ab (`WP_Error`, kein Eintrag ins Dateisystem).

4. **`admin_notices`** – Zeigt bei abgelaufenem oder gesperrtem Token einen dezenten Hinweis: «Lizenz abgelaufen – keine Updates. Das Plugin funktioniert weiterhin normal.»

Innerhalb eines Requests cacht der Client die Server-Antwort, um Mehrfach-Abfragen zu vermeiden.

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

## Sicherheit auf einen Blick

- Token nur im `Authorization: Bearer`-Header, nie in der URL.
- SHA-256-Prüfung nach dem Download; bei Abweichung keine Installation.
- HTTP 403 vom Server bei ungültigem Token führt nur zur Update-Verweigerung, nicht zur Funktionssperre.
- Backend-Token in Option `gfb_update_token` mit `autoload=false`; nie im Klartext gerendert; Konstanten-Vorrang serverseitig im Handler erzwungen; Audit nur mit `token_set`-Flag.

## Verweis auf den Server

Der Server (`plugins.blitzdonner.ch`) lebt im Projekt `bd-plugin-updater`. Dort sind die Endpunkte, die Token-Verwaltung, die Republish-Regel und die Server-Härtung dokumentiert.
