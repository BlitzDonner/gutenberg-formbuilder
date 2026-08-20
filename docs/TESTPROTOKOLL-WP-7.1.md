# Testprotokoll WordPress 7.1

**Erhoben:** 20.08.2026 · **Umgebung:** formbuilder.local (Local) · **WordPress:** 7.1 · **PHP:** 8.2.29 · **Theme:** twentytwentyfive · **Sprache:** de_CH
**Geprüfte Plugin-Versionen:** 2.11.2 (Hauptlauf), 2.13.0 (Kernfälle wiederholt)

**Ergebnis:** WordPress 7.1 bricht nichts. 38 Prüfpunkte, 35 bestanden, 3 Befunde ohne Bezug zu 7.1. Kein PHP-Fehler, keine Deprecation-Meldung, kein ungültiger Block.

Ausführliche Fassung mit allen Tabellen: `ausgabe/blitz-donner/formular-plugin/wp71-testprotokoll.html` im ClaudeStation-Vault; öffentlich unter <https://plugins.blitzdonner.ch/plugin/blitz-donner-formular/> im Abschnitt Vertrauensbeleg.

---

## 1 Was sich in 7.1 geändert hat

| Änderung | Folge für das Plugin | Status |
|---|---|---|
| Beitrags-Editor läuft **immer** im iframe, auch ohne Block-Theme | Editor-Skripte müssen das Canvas-Dokument ansprechen; dafür dient seit 2.6 `gfbForEachEditorCanvasDocument` | bestanden |
| Listentabellen: `th.check-column` → `td` | betrifft nur `WP_List_Table`; die Einträge-Liste bringt eigenes Markup mit | nicht betroffen |
| `__next40pxDefaultSize` wirkt nicht mehr | reine Darstellung im Inspector | bestanden |
| jQuery UI 1.14.2 | Plugin lädt kein jQuery UI | nicht betroffen |

## 2 Testaufbau

Drei Seiten mit identischem Feldsatz (Vorname Pflicht, E-Mail, Telefon Pflicht + vertraulich, Datei, Absenden):

| Seite | Formular-ID | `receiptMode` |
|---|---|---|
| GFB 7.1 Test – ohne Bestätigungsmail | `gfb_t1_none` | keine |
| GFB 7.1 Test – Sofort-Bestätigung | `gfb_t2_instant` | `instant` |
| GFB 7.1 Test – Double-Opt-in | `gfb_t3_doi` | `doi` |

Die Einsendungen liefen über ein Skript, das die Seite lädt, Nonce, HMAC-Token und Honeypot ausliest, die geforderten zwei Sekunden wartet und dann absendet – derselbe Weg wie im Browser. Simuliert wurde ausschliesslich die Antwort des externen Friendly-Captcha-Servers (Filter auf `pre_http_request`); der Plugin-Code lief unverändert. Die Testvorrichtung ist entfernt.

## 3 Mail-Varianten

**Ohne Bestätigungsmail:** Einsendung gespeichert, Sprung zum Erfolgsbereich, keine Mail an die ausfüllende Person, eine Betriebs-Benachrichtigung.

**Sofort-Bestätigung:** Beide Sperren vor dem Versand halten – bei abgeschaltetem Spam-Schutz greift `captcha_required`, bei nicht bestandener Prüfung `captcha_unverified`. Nach bestandener Prüfung geht die Mail raus: Betreff mit Domain statt Markenname, vertrauliches Feld nur als «vertraulich gespeichert», durchgehend Feldlabels, Sie-Form, Schlusshinweis für Fremdeintragungen.

**Double-Opt-in:** Link-Mail ohne jeden Feldwert, Linkziel im Frontend (kein `wp-admin`). Der Aufruf der Bestätigungsseite zeigt keine Daten und verlangt einen zweiten Schritt; nach dem Absenden folgen Erfolgsseite, vollständige Empfangsmail mit Klartext der vertraulichen Felder und die zweite Betriebs-Benachrichtigung. Datensatz auf `confirmed` mit Zeitstempel.

> Der Unterschied zwischen beiden Mails ist gewollt: vertrauliche Felder erscheinen im Sofort-Modus nur als Hinweis, im Klartext erst nach bestätigter Adresse (`includes/class-gfb-receipt-mail.php:1661`).

## 4 Angriffe auf den Bestätigungslink

Der Aufruf per GET zeigt in allen Fällen dieselbe neutrale Seite – wer Token durchprobiert, erfährt aus der Antwort nichts. Die Entscheidung fällt beim Absenden.

| Versuch | Ergebnis |
|---|---|
| Gültigen Link zweimal bestätigen | abgelehnt |
| Letztes Zeichen des Tokens verändert | abgelehnt |
| Gültiger Token an fremder Einsendungs-ID | abgelehnt |
| Absenden ohne Nonce | abgelehnt |

Im Prüfprotokoll: vier Einträge `doi_rejected`, einer `doi_confirmed`.

## 5 Abwehr beim Absenden

Pflichtfeld leer → Fehler mit Feldnamen. Honeypot befüllt → `err_spam`, nichts gespeichert. Absenden in unter zwei Sekunden → `err_spam`. Sechste Einsendung innert zehn Minuten → `err_rate`. Spam-Schutz nicht gelöst → `err_captcha`. Unbekanntes Feld im Datensatz → ignoriert, Einsendung läuft.

## 6 Datei-Upload

| Hochgeladen | Ergebnis |
|---|---|
| `ausweis.pdf` mit echtem PDF-Anfang | angenommen |
| `schad.php` | «Dieser Dateityp ist nicht erlaubt.» |
| `getarnt.pdf` mit PHP-Inhalt | «Dateiinhalt passt nicht zur Endung.» |
| `doppelt.pdf.php` | abgewiesen |

Die angenommene Datei beginnt mit `1f e8 a3 b9 62 35 …`, von `%PDF` ist nichts zu finden. Rechte `-rw-------`, Ablage unter `wp-content/.gfb-private/gfb-encrypted/2026/08/`, direkter Aufruf über den Browser: HTTP 404.

## 7 Backend

Einträge-Liste rendert vollständig, Sortierung je Spalte auf- und absteigend, technische Formular-IDs klein und grau. Seite «Texte»: sieben Gruppen, 84 Felder, Anrede Sie, Bezeichnung Domain. Einstellungsseite mit Logo-Ausrichtung und Fusszeilen-Editor. Prüfprotokoll: Hash-Kette über 291 Zeilen ohne Bruch.

**DOI-Ampel**, alle vier Zustände mit sprechendem Text:

| Farbe | Bedeutung | Text |
|---|---|---|
| grau | Formular ohne Bestätigungslink | Kein Bestätigungslink |
| orange | Bestätigung offen | Bestätigung offen |
| rot | Frist verstrichen | Nicht rechtzeitig bestätigt (abgelaufen am …) |
| grün | bestätigt | Bestätigt am … |

## 8 Editor

Geprüft an «Safe Form», die alle Feldtypen enthält und aus dem Editor selbst stammt:

```
Blöcke gesamt:          30
Blocktypen:             22
Ungültige Blöcke:       0
Warnungen im Canvas:    0
Plugin-Stile im iframe: geladen
```

Die eigens für den Test von Hand geschriebenen Seiten zeigten anfangs «unerwarteter oder ungültiger Inhalt» – dort fehlte das `<input>`-Element, das die `save`-Funktion erzeugt. Ein Fehler der Testvorlage, nicht des Plugins. Nebenbei belegt: Das Frontend rendert trotzdem korrekt, weil der Server aus den Blockattributen baut und nicht aus dem gespeicherten HTML.

## 9 Befunde

Alle drei bestehen unverändert auch unter WordPress 7.0 und unter 2.13.0.

### B1 · Ungültige E-Mail-Adresse wird stillschweigend verworfen (mittel)

`sanitize_email()` macht aus einer Eingabe ohne `@` einen Leerstring; die anschliessende Prüfung `'' !== $value && ! is_email( $value )` greift dann nicht mehr (`includes/class-gfb-submit-handler.php:1035` und `:1068`).

Bei einem **optionalen** E-Mail-Feld verschwindet «keine-mail» ohne Meldung und die Einsendung wird als Erfolg quittiert. Belegt an Einsendung 40: In der Backend-Liste steht als Absender «—». Vertippt sich jemand bei einem Bestätigungs-Formular, sieht er die Erfolgsmeldung und bekommt nie eine Mail.

*Vorschlag:* Den Rohwert vor der Bereinigung prüfen und bei nicht leerer, aber ungültiger Eingabe einen Fehler melden.

### B2 · Irreführende Meldung beim Pflicht-E-Mail-Feld (klein)

Dieselbe Ursache. Das Pflichtfeld blockt korrekt, meldet aber «Bitte füllen Sie das Feld E-Mail aus.» – obwohl etwas eingetragen wurde. Geprüft mit «keine-mail» und «a@b». Mit B1 zusammen zu beheben.

### B3 · `{{formTitle}}` im Betreff wird nicht ersetzt (klein)

Der Betreff der Betriebs-Benachrichtigung kennt nur Feldnamen (`{{vorname}}`) und Feldlabels (`{{label_vorname}}`). Ein Formularname als Platzhalter ist nicht vorgesehen, wird stumm zum Leerstring und ergibt «Neue Einsendung ()». Entweder `{{formTitle}}` aufnehmen – dabei die Kleinschreibung durch `sanitize_key()` beachten – oder die verfügbaren Platzhalter beim Eingabefeld auflisten.

## 10 Nachtest mit 2.13.0

Die lokale Umgebung lief auf 2.11.2, das Repository steht auf 2.13.0. Nach dem Hauptlauf wurde die aktuelle Version eingespielt und die Kernfälle wiederholt – ohne Unterschied: alle drei Mail-Varianten, Maskierung im Sofort-Modus, datensparsame Link-Mail, Double-Opt-in bis zur Bestätigung, Editor mit 30 Blöcken ohne ungültigen Block.

Die Neuerungen aus 2.12.0 und 2.13.0 laufen unter 7.1:

| Neuerung | Ergebnis |
|---|---|
| Sprachkennung als BCP-47 | `lang="de-CH"` am Formular |
| `aria-labelledby` an den Feldern | alle vier Felder verweisen auf ihr Label |
| Zentrale Lizenzverwaltung (bdliz 1.1.0) | Abdeckung live geprüft, «lizenziert – Updates aktiv» |
| Update-Client 3.0.0 | Katalog der übrigen Plugins wird geladen |

## 11 Offen

- Durchlauf mit echtem Captcha durch eine Person (die Testseiten stehen bereit).
- Abgelaufener Bestätigungslink mit echtem Klick; für die Ampelfarbe wurde das Ablaufdatum in der Datenbank vorgezogen. Die Ablehnungsprüfungen aus Abschnitt 4 decken denselben Code-Pfad ab.
- Mail-Vorschau am Block – ohne Bezug zu 7.1, deshalb nicht angesehen.
