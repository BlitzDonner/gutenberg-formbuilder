# Formular-Einträge verwalten

Diese Anleitung erklärt, wie Sie eingegangene Formulardaten im WordPress-Backend einsehen, filtern und exportieren.

## Übersicht öffnen

Klicken Sie in der linken Seitenleiste auf **Formular-Einträge** (Icon: Sprechblase). Sie sehen eine Tabelle mit allen eingegangenen Formularen.

Die Tabelle zeigt pro Zeile:

| Spalte | Inhalt |
|---|---|
| **ID** | Laufende Nummer des Eintrags |
| **Datum** | Zeitpunkt der Einsendung |
| **Seite** | Die WordPress-Seite, auf der das Formular steht |
| **Formular** | Name und technische ID des Formulars |
| **Absender** | E-Mail-Adresse und Name (falls ausgefüllt). Verschlüsselte Felder erscheinen als «verschlüsselt» |
| **Aktionen** | Link «Ansehen» für die Detailansicht |

## Filtern und sortieren

Über der Tabelle stehen zwei Dropdown-Menüs:

1. **Formular-Filter** – Wählen Sie «Alle Formulare» oder ein bestimmtes Formular aus der Liste.
2. **Sortierung** – Vier Optionen:
   - Datum: neueste zuerst (Standard)
   - Datum: älteste zuerst
   - Formular: Name A–Z
   - Formular: Name Z–A

Klicken Sie nach der Auswahl auf **Anwenden**.

## Eintrag im Detail ansehen

Klicken Sie in der Tabelle auf **Ansehen**. Die Detailansicht zeigt:

### Metadaten

- **Datum** – Zeitpunkt der Einsendung
- **Seite / Beitrag** – Auf welcher Seite das Formular ausgefüllt wurde
- **Formular-ID** – Technische Kennung des Formulars
- **Formularname** – Der vergebene Name (falls vorhanden)
- **IP-Adresse** – Absender-IP
- **User-Agent** – Browser des Absenders

### Übermittelte Felder

Jedes Feld erscheint mit seinem Label und dem eingegebenen Wert. Drei Sonderfälle:

- **Verschlüsselte Felder** – Erscheinen als «verschlüsselt». Haben Sie die Berechtigung «Entschlüsseln», sehen Sie den Klartext mit dem Hinweis «(entschlüsselt)». Jedes Entschlüsseln wird im Audit-Log protokolliert.
- **Datei-Anhänge** – Zeigen Dateiname, Grösse, Dateityp und einen SHA-256-Fingerabdruck. Darunter steht ein Button **Verschlüsselt herunterladen (Klartext-Stream)** – die Datei wird beim Download entschlüsselt.
- **Links** – URLs erscheinen als klickbare Links.

### Eintrag löschen

Am Ende der Detailansicht steht der Button **Eintrag löschen**. Nach einer Sicherheitsabfrage wird der Eintrag samt zugehöriger Dateien unwiderruflich gelöscht. Das Löschen wird im Audit-Log festgehalten.

Mit **← Zurück zur Übersicht** gelangen Sie zurück zur Liste.

## Einträge exportieren

Der Export-Bereich erscheint unterhalb der Filter, sobald Sie ein bestimmtes Formular ausgewählt haben.

### CSV-Export

1. Wählen Sie im Filter das gewünschte Formular aus und klicken Sie auf **Anwenden**.
2. Optional: Setzen Sie das Häkchen bei **Felder entschlüsseln**, um verschlüsselte Felder im Klartext zu exportieren. Dieser Vorgang wird protokolliert. Im Entschlüsselungsmodus enthält die CSV-Datei zusätzlich die IP-Adressen.
3. Klicken Sie auf **Als CSV exportieren**.

Die CSV-Datei wird direkt heruntergeladen. Sie ist UTF-8-kodiert, verwendet Semikolon als Trennzeichen und lässt sich in Excel oder LibreOffice Calc öffnen.

### ZIP-Export (CSV + Dateien)

Der Button **CSV + Dateien (ZIP)** erscheint nur, wenn das Formular Datei-Upload-Felder hat und die PHP-Erweiterung ZipArchive auf dem Server verfügbar ist.

Der ZIP-Export enthält:

- `eintraege.csv` – Die gleiche CSV wie oben
- `dateien/` – Ein Unterordner pro Absender (benannt nach E-Mail-Adresse), darin die hochgeladenen Dateien im Klartext

## Audit-Log

Unter **Formular-Einträge → Audit-Log** sehen Sie alle sicherheitsrelevanten Ereignisse:

- Wann eine Anfrage eingegangen ist
- Wer Einträge angesehen, entschlüsselt, exportiert oder gelöscht hat
- Datei-Downloads und Exporte
- Änderungen an Einstellungen und Berechtigungen

Jeder Eintrag zeigt Zeitpunkt, Akteur (Benutzername + IP), Aktion, Ziel und Details.

### Integrität prüfen

Am oberen Rand des Audit-Logs steht der Button **Hash-Chain prüfen**. Er prüft, ob die Ereigniskette lückenlos und unverändert ist. Das Ergebnis erscheint als grüner oder roter Banner:

- **Kette intakt** (grün) – Kein Eintrag wurde nachträglich verändert oder gelöscht.
- **Kette gebrochen bei Eintrag #…** (rot) – Ab dieser Stelle stimmt die Prüfsumme nicht mehr. Melden Sie dies Ihrer Agentur.

## Berechtigungen

Nicht alle Funktionen stehen allen Benutzern zur Verfügung. Das Plugin unterscheidet sechs Berechtigungen:

| Berechtigung | Was sie erlaubt |
|---|---|
| **Anfragen sehen** | Einsendungen in der Liste und im Detail anzeigen (verschlüsselte Felder bleiben maskiert) |
| **Entschlüsseln** | Verschlüsselte Felder im Klartext lesen |
| **Löschen** | Einträge löschen |
| **Dateien herunterladen** | Datei-Anhänge herunterladen |
| **Audit-Log** | Audit-Log einsehen und Integrität prüfen |
| **Einstellungen** | Plugin-Einstellungen, Verschlüsselung und Berechtigungen ändern |

Standardmässig hat nur die Rolle «Administrator» alle Berechtigungen. Ihre Agentur kann weitere Rollen freischalten – zum Beispiel für eine Person, die Anfragen lesen, aber nicht entschlüsseln darf.
