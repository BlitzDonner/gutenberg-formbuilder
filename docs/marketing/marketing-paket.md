# Gutenberg Formbuilder – Marketing-Paket

**Erstellt:** 12. Mai 2026
**Aktualisiert:** 3. Juni 2026 (Stand Version 2.5.0)
**Auftragnehmerin:** Blitz & Donner (Schweiz)
**Produkt:** Gutenberg Formbuilder – sicheres WordPress-Formularsystem nur mit Gutenberg-Blöcken
**Aktuelle Version:** 2.5.0 ([Releases auf GitHub](https://github.com/BlitzDonner/gutenberg-formbuilder/releases))
**Methodik:** Markenzauber (Heldenreise, SEHE, Markenblatt-Karten)

> Diese Datei bündelt alle Marketingbausteine. Sie ist so aufgebaut, dass jeder Block (Landingpage, Folgeseite, Social-Post, Offerte) einzeln herausgenommen und in das jeweilige Medium übertragen werden kann – ohne dass die Tonalität neu gesucht werden muss.

---

## Inhalt

1. [Markenblatt für das Plugin](#markenblatt)
2. [Landingpage (lange Verkaufsseite)](#landingpage)
3. [Folgeseiten pro wichtiges Merkmal](#folgeseiten)
4. [Verkaufs-Übersichtsseite (Kurztexte)](#verkaufsuebersicht)
5. [Social-Media-Posts](#social-media)
6. [Offertvorlage](#offertvorlage)

---

<a id="markenblatt"></a>
# 1. Markenblatt für das Plugin

*Verbindliches Fundament für jede Kommunikation rund um den Gutenberg Formbuilder. Wer einen Text formuliert, muss diese Karten kennen – sonst entsteht eine andere Marke.*

## 1.1 Wegpunkte (7 Karten)

### WEGPUNKT 1 – BEDÜRFNIS

> Wer eine WordPress-Site betreibt, braucht Formulare. Kontakt, Anmeldung, Spende, Bewerbung, Newsletter – irgendwo muss der Mensch auf der anderen Seite eine Spur hinterlassen können. Diese Spur ist heikel: Sie enthält Namen, E-Mails, Anliegen, Anhänge. Was die Site-Verantwortliche eigentlich will, ist einfach: Anfragen empfangen, ohne dass die Daten unterwegs auf zehn fremden Servern Zwischenstation machen – und ohne den Editor neu lernen zu müssen, nur um ein Eingabefeld einzufügen.

### WEGPUNKT 2 – BEDROHUNG

> **Persönlich:** Wenn am Montag herauskommt, dass das Newsletter-Formular Adressen an einen US-Anbieter geleakt hat, sitzt eine Person im Sitzungszimmer und muss erklären, warum. Das ist kein abstraktes Risiko, das ist ein Gespräch.
>
> **Organisatorisch:** Datenpannen kosten DSGVO-Bussen, sie kosten Vertrauen, sie kosten Spenderinnen und Kundinnen – und sie tauchen in jedem nächsten Audit wieder auf. Externe Form-as-a-Service-Anbieter sind ein Lock-in, dessen Preis erst sichtbar wird, wenn die Migration ansteht.
>
> **Unfairness:** Ein einfaches Kontaktformular sollte nicht bedeuten, dass Daten an eine Plattform abfliessen, die damit Geld verdient. Wer Anfragen bekommt, verdient Eigentum daran. Das ist nicht Komfort – das ist Anstand.

### WEGPUNKT 3 – AUSREDEN

> «Das eingebaute Kontaktformular reicht.» – Es reicht nicht, sobald sensible Felder oder Anhänge dazukommen. «Sicherheit ist zu kompliziert.» – Sie ist es, wenn man sie selbst zusammensetzen muss; sie ist es nicht, wenn sie eingebaut ist. «Noch ein Plugin mehr, das Update braucht.» – Stimmt. Dieses Plugin ist dafür dasjenige, das die anderen drei überflüssig macht (Formular + Spam-Schutz + DSGVO-Werkzeug + Anhang-Antivirus). «Wir vertrauen dem grossen Anbieter.» – Vertrauen ist gut. Eine Hash-Chain im Audit-Log ist besser.

### WEGPUNKT 4 – MENTOR

> **Empathie:** Wir bauen seit Jahren WordPress-Sites für kleine Organisationen. Wir wissen, wie es ist, wenn ein Plugin den Editor lähmt, wie es ist, wenn der Datenschutzbeauftragte fragt «Wo liegen die Anhänge eigentlich?», und wie es ist, wenn ein Kunde am Freitagnachmittag ein zusätzliches Pflichtfeld braucht.
>
> **Kompetenz:** Wir haben Verschlüsselung-by-default (AES-256-GCM mit envelope encryption), ein eigenes Berechtigungsmodell, ein tamper-evidentes Audit-Log mit SHA-256-Hash-Chain, ClamAV-Anbindung und ein dokumentiertes Sicherheitskonzept eingebaut. Belegt im Repository, prüfbar im Code, Selbsttest mitgeliefert.
>
> **Gemeinsames Interesse:** Wir glauben, dass das Web kleinen Organisationen gehört, nicht Plattformen. Darum geben wir das Plugin als Werkzeug heraus, nicht als Service mit monatlicher Abhängigkeit. Geld verdienen wir mit Begleitung, nicht mit Datenflüssen.

### WEGPUNKT 5 – PLAN

> **Schritt 1 – Installieren:** Plugin-ZIP herunterladen, in WordPress hochladen, aktivieren. Die Datenbank-Tabellen werden automatisch angelegt.
>
> **Schritt 2 – Schlüssel setzen:** Zwei Konstanten in `wp-config.php` ergänzen (`GFB_MASTER_KEYS` und `GFB_ACTIVE_KEY_ID`, Anleitung mitgeliefert). Damit ist die Verschlüsselung scharfgeschaltet – Felder und Anhänge werden ab sofort verschlüsselt gespeichert.
>
> **Schritt 3 – Formular bauen:** Im Gutenberg-Editor den Block «Formular» einsetzen, Felder hineinziehen, optional einen Erfolgsbereich anlegen. Anfragen erscheinen im Backend unter «Formular-Einträge».
>
> **Jetzt starten:** Das Plugin ist auf [GitHub](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) gratis verfügbar. Wer Begleitung möchte, bucht ein 30-Minuten-Erstgespräch – unverbindlich, ohne Vorbereitung.

### WEGPUNKT 6 – TRANSFORMATION

> Vorher hat die Site-Betreiberin gehofft, dass das Kontaktformular hält und niemand fragt. Nachher kennt sie ihre Datenflüsse. Sie weiss, welche Felder verschlüsselt sind, wer im Team welche Anfragen sehen darf, wann eine Datei heruntergeladen wurde und von wem. Wenn der Datenschutzbeauftragte fragt «Wo liegen die Daten?», dauert die Antwort einen Satz – nicht eine Woche Recherche.

### WEGPUNKT 7 – DAS NEUE LEBEN

> **Äusserlich:** Anfragen kommen rein, landen verschlüsselt in der eigenen Datenbank, sind im Backend filterbar und als CSV oder ZIP (inklusive aller Anhänge) exportierbar. Die zuständige Person wird per E-Mail benachrichtigt. Keine Drittanbieter, keine Tracking-Cookies, kein US-Server im Spiel.
>
> **Innerlich:** Ruhe. Die Site liefert das, was sie soll, und die Verantwortliche schläft ohne offenen Tab im Hinterkopf.
>
> **Grundsätzlich:** Eine kleine Organisation, die ihre Anfragen kontrolliert, gehört sich selbst. Genau so soll das Web sein.

---

## 1.2 Parolen (Botschafts-Karten)

*Wortwörtlich verwendbar. Niemals umformulieren.*

### PAROLE 1 – Bedürfnis

> Anfragen Ihrer Kundschaft gehören Ihnen. Nicht einer Plattform.
>
> **Wörter:** 8 | **Einblendzeit:** 1.6 s ✓
> **Einsatzorte:** Hero-Bereich Landingpage, LinkedIn-Profilzeile, Pitch-Folie

### PAROLE 2 – Bedrohung

> Ein Datenleck im Kontaktformular kostet mehr als die DSGVO-Busse.
>
> **Wörter:** 10 | **Einblendzeit:** 2.0 s ✓
> **Einsatzorte:** Social-Media-Einstieg, Vortragsfolie, Sicherheits-Whitepaper

### PAROLE 3 – Ausrede

> Sicherheit ist eingebaut. Nicht zusammengeklickt.
>
> **Wörter:** 6 | **Einblendzeit:** 1.2 s ✓
> **Einsatzorte:** Feature-Karte, Werbe-Kachel, E-Mail-Signatur

### PAROLE 4 – Mentor

> Wir bauen das Plugin, das wir auf unseren eigenen Sites laufen lassen.
>
> **Wörter:** 12 | **Einblendzeit:** 2.4 s ✓
> **Einsatzorte:** Über-uns-Seite, Pitch-Deck, Konferenz-Slide

### PAROLE 5 – Plan

> Installieren, Schlüssel setzen, Formular bauen – fertig an einem Nachmittag.
>
> **Wörter:** 10 | **Einblendzeit:** 2.0 s ✓
> **Einsatzorte:** Plan-Abschnitt der Landingpage, Offerte, Begleit-E-Mail

### PAROLE 6 – Transformation

> Sie wissen jederzeit, wo Ihre Daten liegen – und wer sie sehen darf.
>
> **Wörter:** 12 | **Einblendzeit:** 2.4 s ✓
> **Einsatzorte:** Testimonial-Seite, Sicherheits-Whitepaper, Behörden-Anfragen

### PAROLE 7 – Empfehlung

> Das Formular im Editor zusammengeklickt. Die Daten beweisbar bei uns.
>
> **Wörter:** 11 | **Einblendzeit:** 2.2 s ✓
> **Einsatzorte:** Referenz-Zitat, Empfehlungsphase, Newsletter-Footer

---

## 1.3 Grenzsteine (Tabu-Karten)

### GRENZSTEIN 1

> **Der Grenzstein:** Wir bauen keinen externen Form-as-a-Service. Daten bleiben auf dem Server der Kundin.
>
> **Stattdessen:** «Wenn Sie eine gehostete Lösung suchen, sind wir die Falschen – wir geben Ihnen das Werkzeug für Ihre eigene Site. Falls Sie Hosting brauchen, empfehlen wir gerne Schweizer Anbieter.»

### GRENZSTEIN 2

> **Der Grenzstein:** Wir bauen kein eigenes Editor-Universum. Es gibt nur Gutenberg-Blöcke.
>
> **Stattdessen:** «Wer einen anderen Editor will, ist mit Plugin X oder Y besser bedient. Wir setzen darauf, dass WordPress-Block-Editor das Standardwerkzeug bleibt – daran richten wir alles aus.»

### GRENZSTEIN 3

> **Der Grenzstein:** Wir leiten keine Daten an Tracking-Tools, Analytics oder Werbenetzwerke weiter.
>
> **Stattdessen:** «Das Plugin lädt im Frontend nichts von externen Domains nach. Wenn Sie Statistik brauchen, machen Sie das mit Ihrem datenschutzkonformen Werkzeug – wir mischen uns nicht ein.»

### GRENZSTEIN 4

> **Der Grenzstein:** Wir versprechen keine «100 % DSGVO-Konformität».
>
> **Stattdessen:** «Konformität entsteht aus Konfiguration plus Prozess plus Verträgen – nicht aus einem Plugin allein. Wir liefern die technischen Bausteine, damit es einfach wird, konform zu betreiben. Den Rest dokumentieren wir in der INSTALL.md.»

### GRENZSTEIN 5

> **Der Grenzstein:** Wir bauen keine kostenpflichtigen «Pro-Features» in das Open-Source-Plugin ein, die das Gratisprodukt absichtlich beschneiden.
>
> **Stattdessen:** «Das Plugin kann, was es kann – vollständig. Was wir verkaufen, ist Begleitung: Setup, Schulung, Wartung, individuelle Erweiterungen. Nicht künstlich verknappte Funktionen.»

---

## 1.4 Überraschungen (Joker-Karten)

### ÜBERRASCHUNG 1

> **Wann:** Eine Person eröffnet ein Issue auf GitHub mit einem konkreten Sicherheitsfund.
>
> **Was:** Persönliche Antwort innerhalb von 48 Stunden mit Dank, Einschätzung und Korrektur-Plan. Bei substanzieller Meldung: Erwähnung im CHANGELOG inkl. Verlinkung des Profils (Opt-in). Optional: Schweizer Schokolade per Post.
>
> **Budget:** bis CHF 30
> **Wer darf:** Stefan, Annette oder Max
> **Wie oft:** Jedes Mal

### ÜBERRASCHUNG 2

> **Wann:** Eine Kundin migriert von einem Drittanbieter (Typeform, Wufoo, JotForm) zu unserem Plugin und nennt es im Erstgespräch.
>
> **Was:** Kostenlose Migrationsstunde – wir helfen beim Übertragen der bestehenden Felder in einen Gutenberg-Block. Keine Vertragsbindung.
>
> **Budget:** 1 Stunde Arbeitszeit
> **Wer darf:** Max
> **Wie oft:** Einmalig pro Kundenbeziehung

### ÜBERRASCHUNG 3

> **Wann:** Sechs Monate nach Setup-Begleitung – unaufgefordert.
>
> **Was:** Persönliche Nachricht (kein Newsletter): «Wie läuft das Formular im Alltag? Audit-Log mal angeschaut?» Wenn die Kundin antwortet, kostenloses 30-Minuten-Gespräch zur Optimierung.
>
> **Budget:** bis CHF 0
> **Wer darf:** Max
> **Wie oft:** Einmalig pro Kundenbeziehung

---

## 1.5 Belege (Beweis-Karten)

### BELEG 1

> **Der Beleg:** «Sensible Felder und alle Datei-Anhänge werden mit AES-256-GCM verschlüsselt. Der Master-Key liegt in `wp-config.php`, nicht in der Datenbank – ein Datenbank-Backup ist ohne Schlüssel nutzlos.»
>
> **Gültig bis:** dauerhaft (Architekturentscheid, dokumentiert in `SECURITY.md`)

### BELEG 2

> **Der Beleg:** «Datei-Anhänge verlassen den Web-Pfad komplett: Speicherung in `wp-content/.gfb-private/`, Modus 0600, kein direkter URL-Zugriff. Download ausschliesslich über einen Endpoint mit Berechtigungs-Prüfung und Audit-Eintrag.»
>
> **Gültig bis:** dauerhaft (siehe `INSTALL.md` und `class-gfb-file-storage.php`)

### BELEG 3

> **Der Beleg:** «Das Audit-Log ist tamper-evident: Jede Zeile enthält den SHA-256-Hash der vorherigen. Manipulation einer einzigen Zeile fällt bei der Verifikation auf.»
>
> **Gültig bis:** dauerhaft (`class-gfb-audit.php`)

### BELEG 4

> **Der Beleg:** «Sechs Capabilities trennen Sehen, Entschlüsseln, Löschen, Datei-Download, Audit-Einsicht und Einstellungen. Eine Mitarbeiterin kann Anfragen sichten, ohne sie entschlüsseln oder Anhänge laden zu können.»
>
> **Gültig bis:** dauerhaft (`class-gfb-capabilities.php`)

### BELEG 5

> **Der Beleg:** «Datei-Uploads werden vor der Speicherung optional mit ClamAV gescannt – als Pflicht oder als ‹wenn vorhanden›. Plugin liefert EICAR-Selbsttest und Timeout-Konfiguration.»
>
> **Gültig bis:** dauerhaft (`class-gfb-clamav.php`)

### BELEG 6

> **Der Beleg:** «Das Plugin lädt im Frontend keine externen Skripte oder Schriften nach. Keine Drittanbieter-Domain, keine Tracking-Pixel, keine Cookies aus Übersee.»
>
> **Gültig bis:** dauerhaft (Architekturentscheid)

### BELEG 7

> **Der Beleg:** «Das Plugin ist Open Source und auf GitHub einsehbar: BlitzDonner/gutenberg-formbuilder. Jede Zeile prüfbar, jeder Release nachvollziehbar im CHANGELOG.»
>
> **Gültig bis:** dauerhaft (Repository-Status)

### BELEG 8

> **Der Beleg:** «Pro Formular lässt sich eine E-Mail-Benachrichtigung beim Absenden einschalten – mit eigenen Empfängern, eigenem Betreff und wählbarem Absender (Admin-Adresse, eine feste Adresse oder ein E-Mail-Feld der Einsendung). Verschlüsselte Werte erscheinen nie im Klartext in der Mail.»
>
> **Gültig bis:** dauerhaft (seit Version 2.3.0, eigener Absender seit 2.4.1; `class-gfb-submit-handler.php`)

### BELEG 9

> **Der Beleg:** «Einsendungen lassen sich im Backend als CSV exportieren (Semikolon, UTF-8, Excel-tauglich) – oder als ZIP, das die CSV und alle hochgeladenen Dateien enthält, je Einsendung in einem Ordner mit der Absender-E-Mail als Name. Der Klartext-Export verschlüsselter Felder ist an eine eigene Berechtigung gebunden und wird im Audit-Log protokolliert.»
>
> **Gültig bis:** dauerhaft (seit Version 2.5.0; `class-gfb-admin-submissions.php`)

---

<a id="landingpage"></a>
# 2. Landingpage

*Lange Verkaufsseite, eine Bildschirmrolle pro Wegpunkt. Reihenfolge folgt der Heldenreise. Texte sind direkt einsetzbar.*

---

## Abschnitt 1 – Hero (Wegpunkt 1: Bedürfnis)

**H1**
> Anfragen Ihrer Kundschaft gehören Ihnen. Nicht einer Plattform.

**Lead (2–3 Sätze)**
> Sie betreiben eine WordPress-Site und brauchen Formulare – Kontakt, Anmeldung, Spende, Bewerbung. Mit dem **Gutenberg Formbuilder** klicken Sie das Formular im Editor zusammen, und alle eingehenden Daten bleiben verschlüsselt auf Ihrem Server. Kein Drittanbieter, kein Tracking, kein US-Server.

**Call-to-Action**
> [Plugin auf GitHub holen](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) – kostenlos. Oder [30-Minuten-Erstgespräch buchen](#kontakt) – unverbindlich.

**Bildidee**
> Screenshot: Block-Editor mit eingefügtem Formular, daneben Backend-Übersicht mit Spalten «Formular» und «Absender». Schlicht, ohne Stockfotos.

---

## Abschnitt 2 – Was auf dem Spiel steht (Wegpunkt 2: Bedrohung)

**H2**
> Ein Datenleck im Kontaktformular kostet mehr als die DSGVO-Busse.

**Drei Karten (visuell als 3-Spalten-Layout)**

**Persönlich**
> Wenn am Montag herauskommt, dass das Newsletter-Formular Adressen leakt, sitzt eine Person im Sitzungszimmer und muss erklären. Das ist kein Risiko, das ist ein Termin im Kalender.

**Organisatorisch**
> Bussen, Vertrauensverlust, Spendenrückgang, Migrationsschmerz. Externe Form-as-a-Service-Anbieter sind ein Lock-in, dessen Rechnung erst beim Wechsel sichtbar wird.

**Grundsätzlich**
> Wer Anfragen empfängt, verdient Eigentum daran. Wer Anfragen sammelt, um sie zu verkaufen, verdient Konkurrenz. Das ist nicht Komfort – das ist Anstand.

---

## Abschnitt 3 – Wir wissen, was Sie zögern lässt (Wegpunkt 3: Ausreden)

**H2**
> Vier Sätze, die wir auch schon gesagt haben – und vier ehrliche Antworten.

| Sie denken | Wir sagen |
| - | - |
| «Das eingebaute Kontaktformular reicht.» | Es reicht, bis das erste sensible Feld dazukommt. Dann reicht es nicht mehr. |
| «Sicherheit ist zu kompliziert.» | Sie ist es, wenn man sie selbst zusammensetzen muss. Sie ist es nicht, wenn sie eingebaut ist. |
| «Noch ein Plugin mehr, das Update braucht.» | Stimmt. Dieses ist dafür dasjenige, das drei andere überflüssig macht: Formular + Spam-Schutz + DSGVO-Werkzeug + Antivirus für Anhänge. |
| «Wir vertrauen dem grossen Anbieter.» | Vertrauen ist gut. Eine Hash-Chain im Audit-Log ist besser. |

---

## Abschnitt 4 – Wer wir sind (Wegpunkt 4: Mentor)

**H2**
> Wir bauen das Plugin, das wir auf unseren eigenen Sites laufen lassen.

**Body**
> Blitz & Donner ist eine Schweizer Kommunikationsagentur. Stefan und Annette Gilgen führen sie seit 30 Jahren gemeinsam, ihr Sohn Max arbeitet seit 8 Jahren mit. Wir bauen WordPress-Sites für kleine Organisationen – und kennen jedes Plugin, das den Editor lähmt, jedes Datenschutz-Audit, das in der falschen Frage hängenbleibt, und jedes Formular-Plugin, das Adressen an einen Drittanbieter leitet, ohne dass es im Datenschutzdokument steht.
>
> Den **Gutenberg Formbuilder** haben wir gebaut, weil wir ihn selbst brauchten. Wir geben ihn als Open-Source-Werkzeug heraus – nicht als Service mit monatlicher Rechnung. Geld verdienen wir mit Begleitung: Setup, Schulung, individuelle Erweiterungen. Nicht mit Datenflüssen.

**Belege (3 Karten)**

> **Verschlüsselung-by-default.** AES-256-GCM mit envelope encryption. Schlüssel in `wp-config.php`, nicht in der Datenbank.

> **Tamper-evident Audit-Log.** SHA-256-Hash-Chain. Jede Manipulation fällt bei der Verifikation auf.

> **Open Source.** Jede Zeile auf GitHub einsehbar. Jeder Release im CHANGELOG nachvollziehbar.

---

## Abschnitt 5 – In drei Schritten (Wegpunkt 5: Plan)

**H2**
> Installieren, Schlüssel setzen, Formular bauen – fertig an einem Nachmittag.

**Schritt 1 – Installieren** *(15 Minuten)*
> Plugin-ZIP von GitHub herunterladen, in WordPress unter «Plugins → Installieren» hochladen, aktivieren. Die Datenbank-Tabellen werden automatisch angelegt. Sie können danach sofort einsteigen.
>
> *Zwischenergebnis: Das Plugin ist aktiv, der Block «Formular» steht im Editor zur Verfügung.*

**Schritt 2 – Schlüssel setzen** *(20 Minuten)*
> Zwei Konstanten in `wp-config.php` ergänzen (`GFB_MASTER_KEYS` und `GFB_ACTIVE_KEY_ID`) – Anleitung in der mitgelieferten `INSTALL.md`. Damit ist die Verschlüsselung scharfgeschaltet. Optional: ClamAV einrichten, falls Sie Datei-Uploads erwarten.
>
> *Zwischenergebnis: Sensible Felder und Datei-Anhänge werden ab dieser Minute verschlüsselt gespeichert.*

**Schritt 3 – Formular bauen** *(eine halbe Stunde, je nach Anzahl Felder)*
> Im Editor den Block «Formular» einfügen, Felder hineinziehen (Text, E-Mail, Datei, Auswahl, Checkbox …), optional einen Erfolgsbereich mit Platzhaltern wie `{{vorname}}` einrichten und die E-Mail-Benachrichtigung an die richtige Adresse aktivieren. Veröffentlichen.
>
> *Endergebnis: Anfragen erscheinen unter «Formular-Einträge», filterbar und sortierbar nach Formular, mit Absenderspalte für E-Mail und Name – exportierbar als CSV oder als ZIP mit allen Anhängen.*

**Wo Sie aussteigen können**
> Nach jedem Schritt. Nichts wird modifiziert, was nicht zum Plugin gehört. Plugin deaktivieren = Stand wie vorher.

**Call-to-Action**
> [Plugin holen (gratis)](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) – oder [30-Minuten-Erstgespräch buchen](#kontakt). Dauer Erstgespräch: 30 Minuten. Vorbereitung: keine. Folgeschritt: optional.

---

## Abschnitt 6 – Wer Sie nachher sind (Wegpunkt 6: Transformation)

**H2**
> Sie wissen jederzeit, wo Ihre Daten liegen – und wer sie sehen darf.

**Body**
> Vorher haben Sie gehofft, dass das Kontaktformular hält und niemand fragt. Nachher kennen Sie Ihre Datenflüsse. Sie sehen im Backend, welche Felder verschlüsselt sind, welche Mitarbeiterin welche Anfrage gesehen hat, wann ein Anhang heruntergeladen wurde und mit welcher Berechtigung. Wenn die Datenschutzbeauftragte fragt «Wo liegen die Daten?», dauert die Antwort einen Satz.

---

## Abschnitt 7 – Das neue Leben (Wegpunkt 7)

**H2**
> Anfragen kommen, bleiben bei Ihnen, lassen sich beweisen.

**Drei Spalten**

**Äusserlich**
> Anfragen landen in der eigenen Datenbank, sind im Backend filterbar (nach Formular, Datum, Absender), als CSV oder als ZIP mit allen Anhängen exportierbar, per DSGVO-Standardexport auskunftsfähig, löschbar mit Audit-Eintrag. Die zuständige Person erhält bei jeder Anfrage eine E-Mail.

**Innerlich**
> Ruhe. Die Site liefert das, was sie soll. Kein offener Tab im Hinterkopf.

**Grundsätzlich**
> Eine kleine Organisation, die ihre Anfragen kontrolliert, gehört sich selbst. Genau so soll das Web sein.

**Schluss-CTA**
> Holen Sie sich das Plugin. Wenn Sie Begleitung wollen – wir sind da.

---

<a id="folgeseiten"></a>
# 3. Folgeseiten – eine pro Hauptmerkmal

*Jede Folgeseite ist eine eigene URL und beantwortet eine konkrete Frage. Aufbau folgt überall demselben Schema: Versprechen → Was es löst → Wie es funktioniert → Belege → CTA zurück zur Landingpage.*

---

## 3.1 Verschlüsselung-by-default

**URL-Slug:** `/verschluesselung`

**H1**
> Sensible Felder und Datei-Anhänge sind verschlüsselt. Bevor sie die Datenbank erreichen.

**Was es löst**
> Wenn jemand die WordPress-Datenbank kopiert (Backup, Hosting-Migration, Hack), bekommt diese Person eine Liste von Datensätzen, deren Inhalt nicht lesbar ist. Datei-Anhänge liegen sowieso ausserhalb des Web-Pfads – und wären selbst auf der Festplatte nur Ciphertext.

**Wie es funktioniert (drei Punkte, technisch korrekt, ohne Jargon)**
> 1. Wir nutzen **AES-256-GCM** – denselben Algorithmus, mit dem Browser HTTPS-Verbindungen absichern. Jeder Wert hat eine eigene Nonce, jede Nutzung eine eigene AAD-Bindung.
> 2. Wir trennen **Master-Key** (in `wp-config.php`, ausserhalb der Datenbank) und **Daten-Key** (verschlüsselt in der Datenbank). Das nennt sich envelope encryption – ein gestohlener Datenbank-Dump ist ohne den Master-Key wertlos.
> 3. **Schlüssel-Rotation** ist eingebaut: neuen Schlüssel hinzufügen, alten als inaktiv markieren, der Cron-Job verpackt alte Daten im Hintergrund auf den neuen Schlüssel um.

**Belege**
> - Konfigurationsanleitung in [`INSTALL.md`](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/INSTALL.md)
> - Sicherheitskonzept in [`SECURITY.md`](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/SECURITY.md) (Threat-Model, AAD-Bindungen, Key-Lifecycle)
> - Code: [`includes/class-gfb-crypto.php`](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/includes/class-gfb-crypto.php)

**Was wir nicht versprechen**
> Wir versprechen keine 100 % DSGVO-Konformität. Wir liefern den Baustein. Den Rest liefert Ihre Organisation (AVV, Verzeichnis, Prozesse).

**CTA**
> [Zur Landingpage zurück](#) · [Plugin holen](https://github.com/BlitzDonner/gutenberg-formbuilder/releases)

---

## 3.2 Datenhoheit & DSGVO

**URL-Slug:** `/datenschutz`

**H1**
> Ihre Daten verlassen die Site nicht. Auch nicht «kurz für die Verarbeitung».

**Was es löst**
> Externe Form-Anbieter heisst: Auftragsverarbeitungsvertrag, Drittlandtransfer, neue Punkte im Verarbeitungsverzeichnis, Cookie-Banner-Update. Wenn die Daten Ihre Site nicht verlassen, fällt das alles weg.

**Wie es funktioniert**
> - Daten landen direkt in der WordPress-Datenbank – derselben, die Sie ohnehin betreiben.
> - **Auskunft & Löschung:** WordPress-Standardhooks `personal_data_exporter` und `personal_data_eraser` sind angeschlossen. Eine Anfrage nach Art. 15 DSGVO erzeugt einen Export inklusive aller Felder (auch verschlüsselter – entschlüsselt für die Auskunft).
> - **IP-Pseudonymisierung** über Filter `gfb_pseudonymize_ip`. Standardmässig wird die IP in voller Länge gespeichert, kann aber pro Site auf Pseudonymisierung umgestellt werden.
> - **Keine Drittanbieter-Skripte** im Frontend. Keine Schriften von Google, kein reCAPTCHA, kein Tracker.

**Belege**
> - DSGVO-Hooks: [`includes/class-gfb-security.php`](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/includes/class-gfb-security.php) (`export_personal_data`, `erase_personal_data`)
> - Spam-Schutz ohne externes Captcha: Honeypot pro Formular-Instanz, HMAC-Anti-Replay-Token, Rate-Limit

**CTA**
> [Sicherheitskonzept lesen](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/SECURITY.md) · [Zur Landingpage](#)

---

## 3.3 Gutenberg-nativ – kein neuer Editor

**URL-Slug:** `/gutenberg-nativ`

**H1**
> Sie können den Block-Editor. Das genügt.

**Was es löst**
> Andere Plugins bringen einen eigenen Drag-and-Drop-Editor mit, eigene Designsprache, eigene Stylesheets. Das bedeutet: doppelt lernen, doppelt warten, doppelt brechen bei WordPress-Updates. Wir setzen ausschliesslich auf Gutenberg-Blöcke.

**Wie es funktioniert**
> - Container-Block **Formular** (`gfb/form`) mit InnerBlocks.
> - Feldblöcke: Text, E-Mail, Telefon, URL, Zahl, Auswahl, Checkbox, Radio, Datum, Uhrzeit, Termin, Bereich, Datei, verstecktes Feld, Absenden.
> - **Erfolgsbereich** (`gfb/form-success`): freie InnerBlocks, die nach erfolgreichem Absenden anstelle des Formulars erscheinen – mit Platzhaltern wie `{{vorname}}`.
> - **Farbmodus** pro Formular: Theme, Auto, Hell, Dunkel – inklusive Verläufe.
> - Standard-WordPress-Tools wie **synchronisierte Muster** und **Template-Parts** funktionieren.

**Was Sie davon haben**
> - Neue Mitarbeitende, die schon WordPress kennen, sind ohne Schulung produktiv.
> - WordPress-Updates brechen das Plugin nicht öfter als andere Blöcke.
> - Theme-Wechsel funktioniert wie bei jedem anderen Block.

**CTA**
> [Live-Demo anfragen](#kontakt) · [Block-Liste in der README](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/README.md)

---

## 3.4 Berechtigungen & Audit-Log

**URL-Slug:** `/audit`

**H1**
> Sie sehen, wer was wann gesehen hat.

**Was es löst**
> «Wer hat letzte Woche die Bewerbungsunterlagen heruntergeladen?» – eine Frage, die in den meisten Plugins keine Antwort hat. Bei uns hat sie eine.

**Wie es funktioniert**
> - **Sechs Berechtigungen** (Capabilities) trennen sauber: Anfragen sehen, Anfragen entschlüsseln, Anfragen löschen, Datei-Anhang herunterladen, Audit-Log einsehen, Plugin-Einstellungen ändern.
> - **Tamper-evident Audit-Log:** Jede Zeile enthält den SHA-256-Hash der vorherigen. Wer eine einzelne Zeile manipuliert, bricht die Kette – und das fällt bei der Verifikation auf Knopfdruck auf.
> - Eingetragene Ereignisse: Submission gelesen, Submission entschlüsselt, Datei heruntergeladen, Submission gelöscht, Plugin-Konfiguration geändert, Verifikation durchgeführt – sowie seit 2.5.0: Einsendungen exportiert (CSV/ZIP), Export mit Entschlüsselung, einzelne Datei exportiert, Export abgewiesen.

**Wofür Sie das brauchen**
> - Datenschutz-Audits beantworten ohne Recherche.
> - Internes Vier-Augen-Prinzip technisch erzwingen, nicht nur per Vereinbarung.
> - Bei Verdacht eines internen Lecks: belegbar nachvollziehen.

**CTA**
> [Zur Landingpage](#) · [Capabilities-Code](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/includes/class-gfb-capabilities.php)

---

## 3.5 Sichere Datei-Uploads (mit ClamAV)

**URL-Slug:** `/uploads`

**H1**
> Bewerbungen, Belege, Spendenbescheinigungen – sicher entgegennehmen.

**Was es löst**
> Datei-Uploads sind die häufigste Angriffsfläche von Formularen. Falsche MIME-Typen, getarnte Endungen, Pfade ausserhalb des erlaubten Bereichs – und wenn etwas Bösartiges durchkommt, liegt es im öffentlichen `wp-content/uploads/` und ist von aussen abrufbar.

**Wie es funktioniert**
> - Dateien werden **niemals** in `wp-content/uploads/` gespeichert, sondern in einem privaten Verzeichnis ausserhalb des Web-Pfads (`wp-content/.gfb-private/`), Modus 0600.
> - **MIME-Whitelist** plus echte Dateityp-Erkennung über `finfo` (nicht nur Endung). Doppelte Endungen werden blockiert.
> - **ClamAV-Integration**: optional als Pflicht, optional als «wenn vorhanden». Modi: `clamscan`, `clamdscan` oder direkter Socket. EICAR-Selbsttest mitgeliefert.
> - **Download nur über Endpoint** mit Berechtigungs-Prüfung – der Endpoint erzwingt `application/octet-stream` und schreibt einen Audit-Eintrag.

**Was Sie nicht mehr tun müssen**
> - Apache/Nginx auf private Verzeichnisse konfigurieren – wir liefern `.htaccess` und Nginx-Vorlage mit.
> - Über sichere Dateinamen nachdenken – wir vergeben zufällige IDs.
> - Bei jeder Datei nachschauen, ob jemand sie heruntergeladen hat – der Audit-Log weiss es.

**CTA**
> [INSTALL.md zu ClamAV](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/INSTALL.md) · [Zur Landingpage](#)

---

## 3.6 Backend-Übersicht & Erfolgsbereich

**URL-Slug:** `/admin`

**H1**
> Anfragen sortieren, filtern, exportieren – im Backend, ohne Umweg.

**Was es löst**
> Wer drei oder vier Formulare auf einer Site betreibt, will Anfragen pro Formular sehen. Wer 200 Anfragen pro Monat bekommt, will nach Absender suchen und sie für die Weiterverarbeitung herausholen können. Beides liefert die Übersicht «Formular-Einträge».

**Wie es funktioniert**
> - **Anzeigename** pro Formular: Sie geben dem Block einen sprechenden Namen («Newsletter Anmeldung») – er erscheint in der Übersicht und im Mail-Betreff.
> - **Filter & Sortierung:** nach Formular (alle Formulare oder einzeln), nach Datum, nach Formularname.
> - **Spalte «Absender»:** zeigt automatisch E-Mail (heuristisch erkannt) und Vor-/Nachname, wenn die entsprechenden Felder existieren.
> - **Spalte «Formular»:** zeigt den Anzeigename plus die technische ID.
> - **Verschlüsselte Felder:** in der Übersicht maskiert; im Detail nur sichtbar für Mitarbeitende mit Entschlüsselungs-Berechtigung – mit Audit-Eintrag.
> - **CSV-Export:** ein Klick lädt alle Einträge des gewählten Formulars als CSV (Semikolon, UTF-8 mit BOM – öffnet in Excel ohne Zeichensalat). Spaltenüberschriften sind die Feld-Labels. Werte mit führendem `=`, `+`, `-` oder `@` werden gegen CSV-Injection entschärft.
> - **ZIP-Export (CSV + Dateien):** wenn das Formular Datei-Uploads hat, lädt ein zweiter Knopf ein ZIP mit der CSV und allen Anhängen – je Einsendung in einem Ordner mit der Absender-E-Mail als Name. Die CSV-Zelle verweist direkt auf die Datei im Archiv, jede Datei ist eindeutig ihrer Zeile zugeordnet.
> - **Klartext nur mit Berechtigung:** Verschlüsselte Felder bleiben im Export maskiert; nur Mitarbeitende mit Entschlüsselungs-Berechtigung können sie aktiv im Klartext exportieren – jeder solche Export wird protokolliert.

**Erfolgsbereich**
> Optional: Statt Weiterleitung auf eine Folgeseite zeigt das Plugin nach erfolgreichem Absenden den Inhalt eines Erfolgsbereich-Blocks an – mit Platzhaltern wie `{{vorname}}` oder `{{label_email}}`. Die technischen Feldnamen für die Platzhalter sind im Inspector editierbar und bleiben über das Speichern stabil. So sieht die Absenderin sofort: «Danke, Max – wir melden uns innert 48 Stunden.»

**CTA**
> [Zur Landingpage](#) · [Releases auf GitHub](https://github.com/BlitzDonner/gutenberg-formbuilder/releases)

---

## 3.7 Mehrsprachigkeit (Schweiz-tauglich)

**URL-Slug:** `/sprachen`

**H1**
> Deutsch, Englisch, Französisch, Italienisch – eingebaut.

**Was es löst**
> Eine Schweizer Site bedient oft drei Landessprachen. Plugins, die nur Englisch sprechen, zwingen die Site-Betreiberin zu Strings-übersetzen-im-Theme. Wir liefern fertige Sprachdateien mit.

**Wie es funktioniert**
> - Quellstrings im Code sind Deutsch.
> - Mitgelieferte Übersetzungen: `de_DE` (Quelle), `en_US`, `fr_FR`, `it_IT`.
> - Locale folgt **Einstellungen → Allgemein → Sprache der Website**. Mehrsprachige Plugins wie Polylang oder WPML können den `locale`-Filter setzen – funktioniert.
> - **Frontend-Texte** (Erfolg, Fehler, Validierung) sind alle übersetzt; Admin-Strings ebenfalls.

**Was wir noch nicht haben**
> - Rumantsch (kommt auf Anfrage).
> - Right-to-left-Sprachen (technisch funktioniert es, optisch nicht durchgetestet).

**CTA**
> [Übersetzung beitragen](https://github.com/BlitzDonner/gutenberg-formbuilder) · [Zur Landingpage](#)

---

## 3.8 E-Mail-Benachrichtigung

**URL-Slug:** `/benachrichtigung`

**H1**
> Eine Anfrage kommt rein – die richtige Person weiss es sofort.

**Was es löst**
> Ein Formular nützt nichts, wenn niemand merkt, dass etwas angekommen ist. Wer bisher täglich ins Backend schauen musste, übersieht Anfragen oder antwortet zu spät. Gleichzeitig sollen sensible Inhalte nicht ungeschützt per Mail herumgeschickt werden.

**Wie es funktioniert**
> - **Pro Formular einschaltbar:** Standardmässig aus. Bei Aktivierung verschickt das Plugin nach jedem erfolgreichen Absenden eine Benachrichtigung.
> - **Empfänger frei wählbar:** ein oder mehrere Adressen; leer bleibt = Admin-Adresse als Fallback.
> - **Betreff mit Platzhaltern:** z. B. «Neue Anfrage von {{vorname}}» – die Werte kommen aus den Feldern der Einsendung.
> - **Absender wählbar:** Admin-Adresse, eine feste eigene Adresse oder ein E-Mail-Feld der Einsendung (damit Antworten direkt an die anfragende Person gehen).
> - **Sicher im Inhalt:** Verschlüsselte Felder und Datei-Anhänge erscheinen nie im Klartext in der Mail – stattdessen ein Hinweis, dass der Eintrag im Backend einzusehen ist.

**Was Sie davon haben**
> - Anfragen landen dort, wo gehandelt wird – im Postfach der zuständigen Person.
> - Keine Drittanbieter-Mailbox, kein Zapier-Zwischenschritt; die Mail geht über Ihr WordPress.
> - Sensible Daten bleiben geschützt, auch wenn die Benachrichtigung breiter verteilt wird.

**CTA**
> [Zur Landingpage](#) · [Doku zur E-Mail-Benachrichtigung](https://github.com/BlitzDonner/gutenberg-formbuilder/blob/main/docs/EMAIL-BENACHRICHTIGUNG.md)

---

<a id="verkaufsuebersicht"></a>
# 4. Verkaufs-Übersichtsseite – Kurztexte

*Falls Blitz & Donner mehrere Plugins/Produkte auf einer Übersichtsseite anbietet (siehe «Unused Media Cleaner»). Drei Längen, je nach Karten-Format wählbar.*

## 4.1 Eine-Zeiler (Tagline)

> **Gutenberg Formbuilder** – Sichere Formulare für WordPress, ohne Drittanbieter.

## 4.2 Karten-Text (40 Wörter)

> **Gutenberg Formbuilder** baut Sie Ihre Formulare direkt im Block-Editor – Anfragen bleiben verschlüsselt auf Ihrer Site, Anhänge ausserhalb des Web-Pfads, jeder Zugriff im Audit-Log. Open Source, Schweizer Herkunft, ohne monatliche Rechnung. Setup an einem Nachmittag.

## 4.3 Mittlerer Text (90 Wörter)

> **Gutenberg Formbuilder** ist ein WordPress-Plugin von Blitz & Donner für sichere Formulare ohne Drittanbieter. Sie bauen das Formular direkt im Block-Editor – alle Anfragen bleiben verschlüsselt auf Ihrer Site, Datei-Anhänge ausserhalb des Web-Pfads, jeder Zugriff im manipulationssicheren Audit-Log. Sechs Berechtigungen trennen Sehen, Entschlüsseln und Datei-Download. ClamAV ist eingebaut. Mitgeliefert in vier Sprachen. Open Source, Schweizer Herkunft, keine monatliche Rechnung. Im Backend filtern Sie Anfragen nach Formular und Absender und exportieren sie als CSV oder als ZIP mit allen Anhängen; bei jeder Anfrage geht eine E-Mail an die zuständige Person. Setup an einem Nachmittag.
>
> [Plugin holen (gratis)](https://github.com/BlitzDonner/gutenberg-formbuilder/releases) · [Mehr erfahren](#)

## 4.4 Vergleichszeile

> **Statt Typeform, JotForm, Wufoo:** Gutenberg Formbuilder – Daten bleiben bei Ihnen, nicht in den USA.

## 4.5 Drei Stichworte (für Plugin-Galerie-Karte)

> Sicher · Gutenberg-nativ · Schweizer Herkunft

---

<a id="social-media"></a>
# 5. Social-Media-Posts

*Geplante Frequenz: 1 Post pro Woche, rotiert durch die SEHE-Phasen. Hashtags zurückhaltend.*

## 5.1 LinkedIn

### Post 1 – SEHE-Phase S (Stoppen) – Bedrohung

> **Anfragen Ihrer Kundschaft gehören Ihnen. Nicht einer Plattform.**
>
> Ein durchschnittliches Schweizer KMU-Kontaktformular schickt heute Adressen an mindestens einen US-Anbieter. Im Datenschutzdokument steht das selten.
>
> Wir haben ein WordPress-Plugin gebaut, das Anfragen verschlüsselt auf der eigenen Site speichert. Open Source. Keine monatliche Rechnung.
>
> 👉 github.com/BlitzDonner/gutenberg-formbuilder
>
> #DSGVO #DataPrivacy #WordPress #SwissTech

### Post 2 – SEHE-Phase E (Entscheiden) – Plan

> **Installieren, Schlüssel setzen, Formular bauen – fertig an einem Nachmittag.**
>
> Drei Schritte, an die Sie sich erinnern, wenn Sie heute Abend WordPress aufmachen:
>
> 1. ZIP von GitHub holen, in WordPress hochladen.
> 2. Zwei Konstanten in `wp-config.php` setzen – Anleitung mitgeliefert.
> 3. Im Block-Editor «Formular» einfügen, Felder hineinziehen, veröffentlichen.
>
> Anfragen kommen verschlüsselt in die Datenbank. Datei-Anhänge ausserhalb des Web-Pfads. Audit-Log eingebaut.
>
> 👉 Link in der Profilbeschreibung.

### Post 3 – SEHE-Phase H (Handeln) – Transformation

> **Sie wissen jederzeit, wo Ihre Daten liegen – und wer sie sehen darf.**
>
> Letzte Woche kam eine Datenschutz-Frage rein:
>
> «Wer hat den Bewerbungsanhang von Frau M. heruntergeladen?»
>
> Antwort: 30 Sekunden im Audit-Log nachschauen. Nicht: drei Tage Hosting-Logs durchwühlen.
>
> Das ist der Unterschied zwischen Hoffen und Wissen.

### Post 4 – SEHE-Phase E (Empfehlen) – Belege

> **Open Source heisst: Sie können prüfen, was wir behaupten.**
>
> Was wir behaupten:
>
> - AES-256-GCM für sensible Felder und alle Anhänge
> - SHA-256-Hash-Chain im Audit-Log
> - Sechs trennbare Berechtigungen
> - ClamAV-Integration mit EICAR-Selbsttest
>
> Was Sie tun können: alles im Code nachlesen.
>
> 👉 github.com/BlitzDonner/gutenberg-formbuilder

---

## 5.2 Mastodon / Twitter / X

### Kurz 1
> Anfragen Ihrer Kundschaft gehören Ihnen. Nicht einer Plattform.
> WordPress-Plugin, Open Source, Schweizer Herkunft.
> 👉 github.com/BlitzDonner/gutenberg-formbuilder

### Kurz 2
> Wer war zuletzt auf der Bewerbungsdatei? Audit-Log: 30 Sekunden.
> Statt Hoffen → Wissen.
> #WordPress #DSGVO

### Kurz 3
> Setup-Plan in einem Tweet:
> 1. ZIP installieren.
> 2. Zwei Konstanten in wp-config.
> 3. Block einsetzen.
> Fertig. Verschlüsselt. Auf Ihrem Server.

---

## 5.3 Instagram

### Post 1 – Karussell (5 Folien)

> **Folie 1 – Hook**
> Wo ist Ihr Kontaktformular eigentlich gerade?
>
> **Folie 2**
> Vermutlich auf einem Server, der nicht Ihrer ist.
>
> **Folie 3**
> Vermutlich mit Cookies, die Sie nicht ausgewählt haben.
>
> **Folie 4**
> Es gibt einen anderen Weg.
>
> **Folie 5 – CTA**
> Gutenberg Formbuilder – sichere Formulare auf Ihrer eigenen Site.
> Link in Bio.

### Post 2 – Reel-Skript (15 Sek)

> [On-Camera, ruhig, direkt]
>
> «Ein Kontaktformular sollte nicht heissen, dass die ganze Welt die Daten liest. Wir haben ein WordPress-Plugin gebaut, das Anfragen verschlüsselt auf Ihrer eigenen Site speichert. Open Source. Schweizer Herkunft. Setup an einem Nachmittag.»
>
> [Schnitt: Screenshot Backend-Übersicht «Formular-Einträge»]

---

<a id="offertvorlage"></a>
# 6. Offertvorlage

*Word-/PDF-Vorlage für individuelle Kunden-Offerten. Texte sind in Bausteinen formuliert – Stundensätze, Pakete und Termine ergänzen je Projekt.*

---

## Briefkopf

```
Blitz & Donner
[Adresse]
[PLZ Ort]
[E-Mail · Telefon]
```

**An:** [Kundinname]
**Datum:** [TT. MMMM JJJJ]
**Betreff:** Offerte – Setup & Begleitung Gutenberg Formbuilder

---

## 1. Einleitung

> Liebe [Vorname]
>
> Sie möchten auf [Sitename] sichere Formulare einsetzen, die alle Anfragen verschlüsselt auf Ihrer eigenen Site speichern – ohne Drittanbieter, ohne monatliche Rechnung, ohne Cookie-Banner-Zusatz.
>
> Diese Offerte zeigt Ihnen, wie wir das in drei klar abgegrenzten Schritten umsetzen. Sie können nach jedem Schritt aussteigen, ohne etwas verloren zu haben.

## 2. Was wir machen – drei Schritte

### Schritt 1 – Installation & Verschlüsselung scharfschalten

> **Aufwand:** [3 Stunden] – pauschal CHF [···]
>
> **Was passiert:**
> - Plugin auf Ihrer Site installieren und aktivieren
> - Master-Key generieren und in `wp-config.php` hinterlegen (Schweizer Hosting empfohlen)
> - Datenbank-Schemas anlegen (passiert automatisch beim Aktivieren)
> - Falls vorhanden: ClamAV-Anbindung konfigurieren oder mit Ihrem Hoster abklären
>
> **Was Sie danach haben:**
> - Funktionierendes Plugin auf Ihrer Site
> - Verschlüsselung aktiv für sensible Felder und alle Anhänge
> - Berechtigungen verteilt (wer darf sehen, entschlüsseln, löschen, herunterladen)
> - Erstes Demo-Formular zur Abnahme

### Schritt 2 – Ihre Formulare bauen

> **Aufwand:** [1–4 Stunden je nach Anzahl Formulare] – Stundenansatz CHF [···]
>
> **Was passiert:**
> - Wir bauen mit Ihnen die benötigten Formulare im Block-Editor (Kontakt, Newsletter, Bewerbung, Spende, …)
> - Pflichtfelder, Validierung, Erfolgsbereich, E-Mail-Benachrichtigung an die richtige Adresse
> - Anbindung an Ihre Theme-Farben (Hell/Dunkel)
>
> **Was Sie danach haben:**
> - Die vereinbarten Formulare live auf Ihrer Site
> - Backend-Übersicht «Formular-Einträge» mit Filter und Sortierung
> - Eine kurze Schulungs-Notiz für Ihr Team

### Schritt 3 – Übergabe & Schulung

> **Aufwand:** [2 Stunden] – pauschal CHF [···]
>
> **Was passiert:**
> - 90-Minuten-Schulung mit Ihrem Team (vor Ort oder per Videocall)
> - Wir zeigen: neues Formular anlegen, Anfragen prüfen, Anhänge laden, Audit-Log lesen, DSGVO-Auskunft generieren
> - Übergabe-Dokument mit den drei wichtigsten Befehlen für Ihren Hoster (Backup-Schlüssel, Schlüsselrotation, ClamAV-Update)
>
> **Was Sie danach haben:**
> - Ihr Team kann das Plugin selbst bedienen
> - Sie sind unabhängig – kein Wartungsvertrag nötig

## 3. Optional – Wartungsbegleitung (kein Muss)

> **Aufwand:** [···] Stunden pro Quartal – CHF [···] / Quartal
>
> Wir prüfen einmal pro Quartal: Plugin-Updates, neue WordPress-Versionen, Schlüsselrotation, Audit-Log-Verifikation. Ergebnis ist ein Kurzbericht (1 Seite) mit Empfehlungen.
>
> Sie können diese Begleitung jederzeit beenden.

## 4. Was Sie bekommen – auf einer Karte

| Bereich | Inhalt |
| - | - |
| **Plugin** | Gutenberg Formbuilder (Open Source, dauerhaft kostenlos, MIT/GPL) |
| **Sicherheit** | AES-256-GCM-Verschlüsselung, ClamAV, sechs Berechtigungen, Hash-Chain-Audit |
| **Datenort** | Ihre WordPress-Datenbank, Ihre Festplatte, kein Drittanbieter |
| **Editor** | Gutenberg-Blöcke – kein neues Werkzeug zu lernen |
| **Sprachen** | Deutsch, Englisch, Französisch, Italienisch |
| **Lizenz Plugin** | Open Source, kein Lock-in |
| **Begleitung** | Stundenbasiert, jederzeit kündbar |

## 5. Was Sie nicht bekommen

> Wir sind ehrlich darüber, was nicht zur Offerte gehört:
>
> - **Hosting** – wir empfehlen gerne Schweizer Anbieter, betreiben aber keinen eigenen Server für Sie.
> - **Datenschutzdokumentation** – wir liefern die technischen Bausteine, das Verarbeitungsverzeichnis erstellt Ihre DSB.
> - **Garantierte 100 % DSGVO-Konformität** – Konformität entsteht aus Konfiguration, Prozess und Verträgen. Wir liefern, was technisch dazu beiträgt.

## 6. Plan & Zeitfenster

> **Schritt 1:** ab Auftragsbestätigung in [···] Arbeitstagen
> **Schritt 2:** im Anschluss, parallel mit Ihrem Content
> **Schritt 3:** nach Abnahme der Formulare
>
> Gesamtprojekt: typischerweise [10–15] Arbeitstage Kalenderzeit.

## 7. Investition gesamt

| Position | Aufwand | Betrag CHF |
| - | - | - |
| Schritt 1 – Installation & Verschlüsselung | pauschal | [···] |
| Schritt 2 – Formulare bauen | [···] h × CHF [···] | [···] |
| Schritt 3 – Übergabe & Schulung | pauschal | [···] |
| **Total exkl. MwSt.** | | **[···]** |
| MwSt. 8.1 % | | [···] |
| **Total inkl. MwSt.** | | **[···]** |
|  |  |  |
| Optional: Wartungsbegleitung | [···] h / Quartal | [···] / Quartal |

## 8. Warum wir

> - **30 Jahre Schweizer Kommunikationsagentur** (Stefan & Annette Gilgen, Sohn Max).
> - **Wir bauen das Plugin, das wir auf unseren eigenen Sites laufen lassen.**
> - **Open Source.** Sie können jeden Tag wechseln, wir geben Ihnen aktiv das Werkzeug dazu.
> - **Kein Lock-in, keine monatliche Plattformgebühr.**

## 9. Nächster Schritt

> Wenn diese Offerte für Sie passt, antworten Sie mit «Auftragsbestätigung erteilt» – wir melden uns innert 2 Arbeitstagen mit dem Kalender-Termin für Schritt 1.
>
> Wenn Sie Rückfragen haben, antworten Sie einfach auf diese E-Mail oder rufen Sie an: [Telefon].
>
> Diese Offerte ist 30 Tage gültig.

---

*Mit freundlichen Grüssen*

> Stefan Gilgen / Annette Michel Gilgen / Max Gilgen
> Blitz & Donner
> [Datum]
