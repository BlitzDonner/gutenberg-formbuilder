# Feature-Spezifikation: Submit-Overlay mit Verschluesselungs-Animation (Doppelklick-Schutz)

Plugin: Blitz & Donner Formular · Ziel-Version: 2.8.0 · Autorin: Ripley (Product Owner/UX) · Datum: 2026-06-26
Grundlage fuer: Stark (Bau) --> Neo (Test). Diese Spec definiert das WAS und die Akzeptanzkriterien, nicht die Implementierung.

## 0. Ausgangslage (verifiziert im Code)

Der Submit-Flow ist ein normaler HTML-Submit (kein AJAX). In `assets/frontend.js`, Funktion `initForm()` (Zeile 295), sitzt auf Zeile 384 ein `submit`-EventListener, der vor dem nativen Absenden einen Snapshot der Feldwerte in `sessionStorage` schreibt (`gfb_submit_snapshot:<key>`). Danach laeuft der native `form.submit()` – der Browser schickt die Daten und der Server leitet auf die Dankesseite weiter (`thankYouPageId`).

Problem: Bei grossen Payloads (Datei-Uploads, Verschluesselung) dauert der Absendevorgang merklich. Der User kann in dieser Zeit erneut klicken und das Formular doppelt absenden. Es gibt aktuell keinen Schutz dagegen.

Die CSS-Architektur arbeitet mit vier Stil-Modi ueber `data-gfb-appearance` am `.gfb-form-wrapper`:
- `light` – helle Apple-Optik mit CSS-Variablen (`--gfb-*`)
- `dark` – dunkle Apple-Optik mit CSS-Variablen (`--gfb-*`)
- `auto` – folgt `prefers-color-scheme`, nutzt dieselben Variablen
- `theme` – KEIN Plugin-Stil, erbt komplett vom Website-Theme; nur strukturelle Regeln

Alle drei Apple-Modi (light/dark/auto) teilen sich Tokens wie `--gfb-card`, `--gfb-text`, `--gfb-accent`, `--gfb-border`. Der theme-Modus hat bewusst keine Tokens.

## 1. User Story

Als Formular-Ausfueller moechte ich nach dem Klick auf «Absenden» visuelles Feedback erhalten, dass meine Daten verarbeitet werden, und gleichzeitig daran gehindert werden, das Formular versehentlich ein zweites Mal abzuschicken.

## 2. Funktionsumfang / Scope

### 2.1 Im Scope
- Doppelklick-Schutz: Submit-Button wird sofort disabled, Overlay blockiert das Formular
- Verschluesselungs-Animation als visuelles Feedback waehrend des Absendens
- Kompatibilitaet mit allen vier Stil-Modi
- Accessibility (ARIA)

### 2.2 Bewusst draussen
- Echter Fortschrittsbalken (Uebertragungsdauer ist unbekannt)
- Abbrechen-Funktion (Formulardaten sind bereits unterwegs)
- AJAX-Umbau des Submit-Flows
- Serverseitige Deduplizierung (separates Thema)

## 3. Verhalten im Detail

### 3.1 Ausloesung

Sobald der User den Submit-Button klickt und die native Browser-Validierung besteht (kein `invalid`-Event):

1. Der Submit-Button erhaelt `disabled` und `aria-disabled="true"`
2. Ein Overlay-Element wird ins DOM eingefuegt, positioniert ueber dem gesamten `.gfb-form-wrapper`
3. Die Verschluesselungs-Animation startet
4. Der bestehende Snapshot-Code (Zeile 384–400) laeuft wie bisher
5. `form.submit()` wird nativ ausgeloest – der Browser schickt die Daten parallel zur Animation

### 3.2 Overlay-Aufbau

Das Overlay besteht aus drei Schichten:

```
┌─────────────────────────────────────────────────┐
│  .gfb-form-wrapper  (position: relative)        │
│  ┌───────────────────────────────────────────┐   │
│  │  .gfb-submit-overlay                     │   │
│  │  (position: absolute, inset: 0,          │   │
│  │   z-index: 100, halbtransparent)         │   │
│  │                                          │   │
│  │  ┌─────────────────────────────────────┐  │   │
│  │  │  .gfb-submit-overlay__content      │  │   │
│  │  │  (zentriert, vertikal + horizontal) │  │   │
│  │  │                                    │  │   │
│  │  │  .gfb-submit-overlay__cipher       │  │   │
│  │  │  «Hallo Stefan»                    │  │   │
│  │  │   → morpht zu: █▓▒░●◆■▒░●◆■░●     │  │   │
│  │  │                                    │  │   │
│  │  │  .gfb-submit-overlay__bar          │  │   │
│  │  │  ████████░░░░░░░░░░  (pulsierend)  │  │   │
│  │  │                                    │  │   │
│  │  │  .gfb-submit-overlay__message      │  │   │
│  │  │  «Deine Daten werden verschluesselt│  │   │
│  │  │   und sicher uebermittelt ...»     │  │   │
│  │  └─────────────────────────────────────┘  │   │
│  └───────────────────────────────────────────┘   │
└─────────────────────────────────────────────────┘
```

### 3.3 Verschluesselungs-Animation

Die Cipher-Zeile zeigt einen Klartext (z.B. «Hallo Stefan»), der Zeichen fuer Zeichen von links nach rechts in Chiffre-Zeichen morpht.

- Zeichenpool fuer den Chiffre-Effekt: `█ ▓ ▒ ░ ● ◆ ■ ◼ ▪ ◈ ✦`
- Geschwindigkeit: ca. 80–120 ms pro Zeichen
- Wenn alle Zeichen ersetzt sind, beginnt die Animation von vorn mit einem neuen Klartext-Satz
- Klartext-Rotation (3–4 Saetze, fest codiert):
  1. «Hallo Stefan»
  2. «Formulardaten»
  3. «Sichere Uebertragung»
  4. «Verschluesselt»
- Jedes Chiffre-Zeichen wird pro Durchgang zufaellig aus dem Pool gewaehlt (kein festes Mapping)

### 3.4 Fortschrittsbalken

- Kein echter Prozentwert – die Dauer ist unbekannt
- CSS-Animation: ein gefuellter Bereich faehrt von 0% auf ~85% (ease-out, ca. 4 Sekunden), verharrt dort pulsierend
- Pulsieren: sanftes Auf- und Abschwellen der Breite zwischen 80% und 90% (ease-in-out, ca. 1.5 Sekunden Loop)
- Hoehe: 4px, abgerundete Ecken (`--gfb-radius` oder 4px Fallback)

### 3.5 Statustext

Fester Text: «Deine Daten werden verschluesselt und sicher uebermittelt ...»
- Kein Wechsel, kein Tippen-Effekt – der Text steht still als ruhiger Anker neben der Animation
- Schriftgroesse: `--gfb-body-size` oder `1rem` Fallback

### 3.6 Ende

Das Overlay hat kein programmatisches Ende. Der Server-Redirect (Dankesseite) ersetzt die Seite und damit das Overlay. Es gibt keinen dismiss-Mechanismus.

## 4. Technisches Design

### 4.1 Einfuegepunkt in frontend.js

In `initForm()`, innerhalb des bestehenden `submit`-EventListeners (Zeile 384). Der neue Code kommt **vor** dem bestehenden Snapshot-Code:

```
form.addEventListener('submit', function(e) {
    // NEU: Overlay einblenden + Button disablen
    gfbShowSubmitOverlay(form);

    // BESTEHEND: Snapshot in sessionStorage
    try { ... }
}, true);
```

### 4.2 Neue Funktion: gfbShowSubmitOverlay(form)

Aufgaben:
1. Submit-Button finden (`.gfb-form-wrapper button[type="submit"]` oder letzten `<button>` im Form)
2. Button: `disabled = true`, `aria-disabled = "true"`
3. `.gfb-form-wrapper` erhaelt `position: relative` (falls nicht schon gesetzt)
4. Overlay-DOM erzeugen und an `.gfb-form-wrapper` anhaengen
5. Cipher-Animation starten (eigene Hilfsfunktion `gfbRunCipherAnimation`)

### 4.3 Neue Hilfsfunktion: gfbRunCipherAnimation(cipherEl)

- Nimmt das `.gfb-submit-overlay__cipher`-Element
- Schreibt den aktuellen Klartext zeichenweise rein
- Ersetzt Zeichen fuer Zeichen durch zufaellige Chiffre-Zeichen (setInterval, 80–120ms)
- Nach komplettem Durchlauf: kurze Pause (400ms), dann naechster Klartext

### 4.4 CSS-Struktur in form.css

Neue Regeln am Ende der Datei, in einem eigenen Abschnitt:

```css
/* ============================================================== *
 * Submit-Overlay (Doppelklick-Schutz + Verschluesselungs-Animation)
 * ============================================================== */
```

Alle Selektoren unter `.gfb-form-wrapper .gfb-submit-overlay` – konsistent mit der bestehenden Isolation.

### 4.5 Keine neuen Dateien

Alles lebt in den bestehenden `assets/frontend.js` und `assets/form.css`. Kein neues Skript, kein neues Stylesheet, keine externe Abhaengigkeit.

## 5. Stil-Modi-Kompatibilitaet

### 5.1 Light / Dark / Auto

Diese drei Modi haben Zugriff auf die CSS-Variablen. Das Overlay nutzt:

| Overlay-Element | CSS-Variable | Zweck |
|---|---|---|
| Overlay-Hintergrund | `--gfb-card` mit `opacity: 0.92` | Halbtransparenter Schleier in der Formularfarbe |
| Cipher-Text | `--gfb-text` | Lesbar auf dem Overlay-Hintergrund |
| Fortschrittsbalken (Hintergrund) | `--gfb-border` | Dezente Schiene |
| Fortschrittsbalken (Fuellung) | `--gfb-accent` | Markante Fortschrittsfarbe |
| Statustext | `--gfb-label` | Sekundaere Textfarbe, dezenter als der Cipher |

Bei `auto` greift die bestehende `@media (prefers-color-scheme: dark)`-Regel automatisch – keine Sonderbehandlung noetig.

### 5.2 Theme-Modus

Im Theme-Modus existieren keine `--gfb-*`-Variablen. Das Overlay nutzt neutrale Fallback-Werte:

| Element | Fallback |
|---|---|
| Overlay-Hintergrund | `rgba(255, 255, 255, 0.92)` |
| Cipher-Text | `#1d1d1f` |
| Fortschrittsbalken (Hintergrund) | `#d2d2d7` |
| Fortschrittsbalken (Fuellung) | `#0071e3` |
| Statustext | `#6e6e73` |

Diese Werte sind absichtlich neutral-hell. Ein Dark-Theme mit `theme`-Modus wuerde das Overlay hell anzeigen – das ist akzeptabel, weil der Theme-Modus per Definition keinen Anspruch auf perfekte Abstimmung erhebt.

Alternativ: `@media (prefers-color-scheme: dark)` fuer die Theme-Fallbacks ebenfalls dunkel setzen (Entscheid bei Stark).

## 6. Accessibility

- Overlay-Container: `role="alert"` und `aria-live="assertive"` – Screenreader kuendigt den Zustandswechsel sofort an
- Statustext als `<p>` im Overlay – wird vom Screenreader vorgelesen
- Button: `aria-disabled="true"` zusaetzlich zu `disabled` (Konsistenz)
- Cipher-Animation: `aria-hidden="true"` – rein dekorativ, Screenreader soll sie ignorieren
- Fokus-Trap: nicht noetig, weil das Overlay keine interaktiven Elemente hat und der native Submit die Seite wechselt

## 7. Edge Cases

### 7.1 JavaScript deaktiviert

Ohne JS gibt es kein Overlay. Das Formular funktioniert wie bisher als normaler HTML-Submit. Doppelklick-Schutz entfaellt – das ist akzeptabel, weil auch der gesamte restliche Frontend-Code (Drafts, Snapshots, WebKit-Fixes) JS voraussetzt. Ein serverseitiger Replay-Schutz (HMAC-Token, Anti-Replay) existiert bereits in der Abwehrkette.

### 7.2 Server gibt einen Fehler zurueck (kein Redirect)

Wenn der Server statt eines Redirects zur Dankesseite eine Fehlerseite oder die gleiche Seite mit Fehlermeldung zurueckgibt, ersetzt der Browser die aktuelle Seite trotzdem (es ist ein normaler POST). Das Overlay verschwindet mit dem Seitenwechsel. Kein Handlungsbedarf.

### 7.3 User navigiert weg (Zurueck-Button, Tab schliessen)

Der Browser bricht den laufenden POST ab. Das Overlay verschwindet mit der Navigation. Kein Handlungsbedarf – der Server verwirft unvollstaendige Requests.

### 7.4 Sehr langsame Verbindung (> 30 Sekunden)

Die Cipher-Animation laeuft in Endlosschleife, der Fortschrittsbalken pulsiert. Es gibt kein Timeout und keinen Abbruch-Button – der User kann nur ueber den Browser abbrechen (Tab schliessen, Zurueck). Ein Timeout mit automatischem Entfernen des Overlays waere gefaehrlich: der POST koennte noch laufen, und ein erneuter Klick wuerde die Daten doppelt senden.

### 7.5 Mehrere Formulare auf einer Seite

Jedes Formular hat sein eigenes Overlay. `gfbShowSubmitOverlay(form)` arbeitet relativ zum uebergebenen `form`-Element und dessen `.gfb-form-wrapper`. Kein globaler State, keine Kollision.

### 7.6 Browser-Validierung schlaegt fehl

Wenn die native HTML-Validierung (required, pattern etc.) den Submit blockiert, feuert der `submit`-Event nicht. Das Overlay wird nicht eingeblendet. Kein Handlungsbedarf.

### 7.7 Friendly Captcha aktiv

Der Captcha-Flow laeuft vor dem Submit (Widget-Loesung wird in ein Hidden Field geschrieben). Der Submit-Event feuert erst, wenn das Captcha bestanden ist. Keine Kollision mit dem Overlay.

## 8. Textbasiertes Wireframe

```
┌─────────────────────────────────────────────────────────┐
│                  .gfb-form-wrapper                      │
│  ┌───────────────────────────────────────────────────┐  │
│  │                                                   │  │
│  │   [Name      ]  ░░░░░░░░░░░  (Felder dahinter,   │  │
│  │   [E-Mail    ]  ░░░░░░░░░░░   ausgegraut durch    │  │
│  │   [Nachricht ]  ░░░░░░░░░░░   Overlay)            │  │
│  │   [Datei     ]  ░░░░░░░░░░░                       │  │
│  │                                                   │  │
│  │  ┌─────────────────────────────────────────────┐  │  │
│  │  │         .gfb-submit-overlay                 │  │  │
│  │  │         (halbtransparent darueber)           │  │  │
│  │  │                                             │  │  │
│  │  │                                             │  │  │
│  │  │         █▓▒░●◆■▒░●◆ Stefan                  │  │  │
│  │  │         ^^^^^^^^^^^                         │  │  │
│  │  │         verschluesselt   noch Klartext       │  │  │
│  │  │                                             │  │  │
│  │  │         ████████████████░░░░░░░  (pulsiert)  │  │  │
│  │  │                                             │  │  │
│  │  │         Deine Daten werden verschluesselt   │  │  │
│  │  │         und sicher uebermittelt ...          │  │  │
│  │  │                                             │  │  │
│  │  └─────────────────────────────────────────────┘  │  │
│  │                                                   │  │
│  │   [ Absenden ]  (disabled, hinter dem Overlay)    │  │
│  │                                                   │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

## 9. Akzeptanzkriterien

Das ist erledigt, wenn:

1. **Doppelklick-Schutz:** Nach dem ersten Klick auf «Absenden» ist der Button sofort disabled. Ein zweiter Klick hat keine Wirkung.

2. **Overlay sichtbar:** Ein halbtransparentes Overlay bedeckt den gesamten `.gfb-form-wrapper` und verhindert jede Interaktion mit darunterliegenden Feldern.

3. **Cipher-Animation laeuft:** Im Overlay morphen Klartext-Zeichen von links nach rechts in Chiffre-Zeichen. Die Animation wiederholt sich mit wechselnden Klartexten.

4. **Fortschrittsbalken pulsiert:** Ein Balken faehrt von 0% auf ca. 85% und pulsiert dort. Er zeigt keinen echten Prozentwert.

5. **Statustext steht:** Der Text «Deine Daten werden verschluesselt und sicher uebermittelt ...» ist statisch sichtbar.

6. **Light-Modus:** Overlay nutzt `--gfb-card`, `--gfb-text`, `--gfb-accent` etc. und fuegt sich nahtlos ein.

7. **Dark-Modus:** Overlay nutzt die Dark-Variablen und ist auf dunklem Hintergrund lesbar.

8. **Auto-Modus:** Overlay folgt der Systempraeferenz (hell/dunkel) ohne Sondercode.

9. **Theme-Modus:** Overlay nutzt neutrale Fallback-Farben und bricht das Layout nicht.

10. **Accessibility:** Overlay hat `role="alert"` und `aria-live="assertive"`. Cipher ist `aria-hidden="true"`. Screenreader kuendigt den Zustandswechsel an.

11. **Kein JS:** Ohne JavaScript funktioniert das Formular normal. Kein Overlay, kein Fehler.

12. **Mehrere Formulare:** Auf einer Seite mit zwei Formularen loest jedes sein eigenes Overlay aus, ohne das andere zu beeinflussen.

13. **Captcha-Kompatibilitaet:** Bei aktivem Friendly Captcha erscheint das Overlay erst nach bestandenem Captcha, nicht vorher.

14. **Keine neuen Dateien:** Alles lebt in `assets/frontend.js` und `assets/form.css`.

15. **Keine externen Abhaengigkeiten:** Kein Framework, keine Library, kein CDN.
