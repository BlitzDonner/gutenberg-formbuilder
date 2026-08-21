# Testreihe

Prüft «Blitz & Donner Formular» in mehreren WordPress-Fassungen gleichzeitig.
Jede Fassung läuft in einem eigenen Wegwerf-Container, der nach dem Lauf verschwindet.

## Aufruf

```bash
./tests/lauf.sh                 # alt, aktuell und kommend gleichzeitig
./tests/lauf.sh aktuell         # nur eine Umgebung
./tests/lauf.sh alt kommend     # Auswahl
./tests/lauf.sh --behalten      # Container stehen lassen, zum Nachschauen
./tests/lauf.sh --echte-mails   # zusätzlich echte Mails über Hostpoint
```

Im Chat genügt `/formulartest`.

## Umgebungen

| Kennung | Abbild | Geprüfte Fassung |
|---|---|---|
| `alt` | `wordpress:6.6-php8.1-apache` | die Untergrenze aus dem Plugin-Kopf |
| `aktuell` | `wordpress:7.1-php8.3-apache` | die freigegebene Fassung |
| `kommend` | `wordpress:beta`, danach Sprung auf die Nightly | die Fassung von morgen |
| `umstieg` | `wordpress:7.1-php8.3-apache` ohne festen Plugin-Ordner | Wechsel von der letzten Freigabe auf den Arbeitsstand |

Die Kombination WordPress 6.6 mit PHP 7.4 gibt es als fertiges Abbild nicht;
das älteste Abbild für 6.6 bringt PHP 8.1 mit. Die im Plugin-Kopf genannte
PHP-Untergrenze 7.4 ist damit ungeprüft.

## Aufbau

```
tests/
  lauf.sh                    Einstieg: startet die Container, ruft den Läufer
  docker/compose.yml         WordPress, MariaDB, Mailpit, WP-CLI je Umgebung
  docker/mu-plugins/         Mailweg und Teststeuerung (nur im Container)
  fixtures/formulare.php     legt die sechs Testseiten an
  fixtures/dateien/          Prüf-Dateien für den Upload
  laeufer/index.mjs          Steuerung
  laeufer/lib/               Docker, WP-CLI, HTTP, Mailfänger, Bericht
  laeufer/gruppen/           je eine Datei pro Prüfgruppe
  berichte/<Zeitpunkt>/      bericht.html und ergebnisse.json
```

## Prüfgruppen

| Gruppe | Inhalt | Weg |
|---|---|---|
| A | Umgebung, Aktivierung, Tabellen, privater Ordner | Skript |
| B | Block-Editor: Anmeldung der Blöcke, Gültigkeit, Stile im Rahmen, Konsole | Browser |
| C | Entwürfe, Adresse nach dem Absenden | Browser |
| D | Feldtypen im Frontend, Erfolgsbereich, Beschriftungen | Browser |
| E | Alle Fehlerzustände des Absende-Weges | Skript |
| F | Datei-Upload und Abweisungen | Skript |
| G | Verschlüsselung: Hülle, Entschlüsselung, Bindung | Skript |
| H | Betriebsmail, Sofort-Bestätigung, Double-Opt-in | Mail |
| I | Backend: Liste, Filter, Suche, Prüfprotokoll, Einstellungen | Browser |
| J | Rechte: sechs Berechtigungen mit und ohne | Browser |
| K | Sprachen: Deutsch, Englisch, Französisch, Italienisch | Skript |
| L | Datenschutz: Auskunft, Löschung, Vermerk | Skript |
| M | Umstieg von der Vorversion (eigene Umgebung) | Skript |
| N | Lizenz- und Update-Client, Update URI | Skript |
| O | Farben hell und dunkel, Tastatur, schmales Fenster | Browser |
| A (Ende) | Aufräumen beim Löschen des Plugins | Skript |

## Fallen, die beim Bau aufgefallen sind

- **Konstanten in der wp-config.** Das WordPress-Abbild baut `WORDPRESS_CONFIG_EXTRA`
  nicht ein. Ohne `wp config set` läuft das Plugin im Klartext-Weg und die
  Verschlüsselung bliebe ungeprüft grün.
- **Zwei Bremsen.** Fünf Einsendungen pro zehn Minuten und getrennt davon zehn
  Bestätigungsmails pro Stunde und IP (`gfb_rg_*`). Beide Zähler werden vor
  jedem Prüfpunkt geleert, sonst blockt der fünfte Punkt alle folgenden.
- **Feldblöcke speichern HTML.** Von Hand geschriebenes Markup ist im Editor
  immer ungültig. Die Seiten baut deshalb der Editor selbst.
- **Der Honigtopf ist das erste Textfeld.** Wer `input[type="text"]` misst,
  misst ihn und nicht das erste echte Feld.
- **Erscheinungsbild** kennt vier Werte: theme, auto, light, dark. Kein «custom».
- **Fehlercodes.** Datei- und Virenfehler kommen als `err_validation` mit
  Detailtext, nicht als `err_file` oder `err_virus`.
- **`err_duplicate`** meldet zwei Felder mit gleichem Namen im Formular, nicht
  eine doppelt abgeschickte Einsendung. Gegen zweimal Absenden gibt es keine Sperre.
- **Ein Docker-Mount lässt sich nicht löschen.** Deshalb hängt die Umstiegs-
  Umgebung das Projekt nach `/gfb-unbenutzt`, sonst scheitert `plugin install --force`.
- **Update-Erkennung unter WordPress 6.6.** Der erste `wp_update_plugins()`
  nach dem Löschen des Transients liefert eine leere Liste geprüfter Plugins;
  der Update-Client steigt dann aus. Ab WordPress 7.0 ist sie sofort gefüllt.
  Die Prüfung läuft deshalb zwei Durchgänge.
- **Vor dem Aufräumen deaktivieren.** Läuft `uninstall.php`, während das Plugin
  noch aktiv ist, legt es Tabelle und Einstellungen sofort wieder an.

## Was nachgestellt wird

Fremde Dienste antworten im Wegwerf-Container nicht. Nachgestellt wird nur ihre
Antwort, der Plugin-Code läuft unverändert:

- **Friendly Captcha**: `pre_http_request` liefert bestanden, abgelehnt oder «Server weg».
- **Update-Server**: ohne gesetzten Schalter kein Zugriff nach draussen.
- **Absenderadresse**: WordPress bildet sie aus dem Hostnamen, im Container also
  `wordpress@localhost`. Eine Domain ohne Punkt lehnt PHPMailer ab, deshalb
  ersetzt das mu-Plugin genau diesen Standardfall durch `wordpress@gfb-testlauf.test`.
  Dasselbe gilt für den Rückweg. Beides ist damit gesetzt, aber nicht inhaltlich geprüft.

Alle Eingriffe hängen an der Option `gfb_test_steuerung` und sind von Haus aus aus.

## Ein neuer Prüfpunkt

Nummern folgen der abgenommenen Liste in
`ausgabe/blitz-donner/formular-plugin/pruefpunkte.html`.

```js
await s.punkt( 'E24', 'Kurzer Titel', async () => {
    const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
    const e = await absenden( u, f, { vorname: 'Test' } );
    return soll.gleich( e.code, 'err_validation', 'Fehlercode' );
} );
```

Rückgabe `true` heisst bestanden, ein Text ist die Fehlermeldung,
`throw s.uebersprungen( 'Grund' )` färbt den Punkt grau.

## Grenzen

Der Häufigkeits-Schutz erlaubt fünf Einsendungen in zehn Minuten. Vor jedem
Prüfpunkt löscht der Läufer die Zähler, sonst blockte der fünfte Punkt alle
folgenden. Nur E7 zählt selbst hoch.
