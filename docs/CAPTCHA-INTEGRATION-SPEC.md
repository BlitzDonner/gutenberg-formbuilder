# Feature-Spezifikation: CAPTCHA-Integration (nur Friendly Captcha)

Plugin: Blitz & Donner Formular · Ziel-Version: 2.7.0 · Autorin: Ripley (Product Owner/UX) · Datum: 2026-06-14
Grundlage für: Stark (Bau) → Neo (Test). Diese Spec definiert das WAS und die Akzeptanzkriterien, nicht die Implementierung.

## 0. Ausgangslage (verifiziert im Code)

Scope-Entscheid: Es wird ausschliesslich Friendly Captcha integriert. hCaptcha ist vollständig draussen – kein zweiter Anbieter, keine Anbieterwahl. Das Plugin bietet genau eine CAPTCHA-Konfiguration: Friendly Captcha.

Die bestehende Abwehrkette läuft in `GFB_Submit_Handler::handle()` in dieser Reihenfolge:
Nonce (`wp_verify_nonce`, Zeile 187) → HMAC-Anti-Replay-Token (`GFB_Security::verify_token`, Zeile 195) → dynamischer Honeypot (Zeile 201) → IP-Rate-Limit (`is_rate_limited`, Zeile 206) → danach Schema- und Feldverarbeitung.
Das Formular wird serverseitig in `GFB_Plugin::render_form_block()` gerendert; die versteckten Felder (`gfb_token`, `gfb_nonce`, Honeypot, `gfb_post_id`, `gfb_form_id`, `gfb_instance_id`) stehen im `<form class="gfb-form">` (ab Zeile 744).
Die Admin-Einstellungsseite (`GFB_Admin_Settings`, Slug `gfb-settings`) arbeitet mit einem zentralen POST-Handler `maybe_handle_post()` über das Feld `gfb_settings_action`, geschützt durch `GFB_Capabilities::CAP_MANAGE_SETTINGS` und `check_admin_referer('gfb_settings_action')`. Jede Speicheraktion schreibt `GFB_Audit::record(...)`.
CAPTCHA wird in genau diese Strukturen eingehängt. Es ersetzt nichts. Es ergänzt die Kette als letzte Stufe.

## 1. User Stories

US-1 (Betreiber, Konfiguration):
Als Site-Betreiber möchte ich Friendly Captcha aktivieren und meine Keys (Site-Key + API-Key) eintragen, damit ich Spam mit einem datenschutzfreundlichen EU-Anbieter abwehren kann.

US-2 (Betreiber, Geltungsbereich):
Als Site-Betreiber möchte ich CAPTCHA global oder pro einzelnem Formular aktivieren, damit ich es nur dort einsetze, wo ich Spam erwarte, und sensible Formulare nicht unnötig belaste.

US-3 (Betreiber, Tarif):
Als Site-Betreiber möchte ich, dass sich Gratis- oder Bezahlversion allein aus meinen eingetragenen Keys ergibt, damit das Plugin keine falschen Annahmen über meinen Vertrag trifft.

US-4 (Endnutzer, Privacy):
Als Formular-Ausfüller möchte ich, dass kein Drittanbieter-Skript geladen wird, bevor ich mit dem Formular interagiere, damit meine Daten nicht ohne Anlass an Dritte fliessen (Datensparsamkeit – kein Vorab-Aufruf beim Seitenaufbau).

US-5 (Endnutzer, Funktionsfähigkeit – aktualisiert 2026-06-14):
Als Formular-Ausfüller möchte ich das Formular auch dann absenden können, wenn Friendly Captcha als Dienst gerade nicht erreichbar ist, damit mir eine Störung beim Dienstanbieter den Zugang zur Einreichung nicht verbaut. (Hinweis: Beide Modi verlangen ein bestandenes Captcha. Der frühere Koppelungsverbot-Bezug ist entfallen – das Koppelungsverbot betrifft nur Einwilligungen und ist im consent-losen Betrieb über berechtigtes Interesse gegenstandslos. Die Ausfallsicherung greift nur im Modus `soft` und nur bei nicht erreichbarem Server, siehe D3.)

US-6 (Betreiber, Rechenschaft):
Als datenschutzverantwortlicher Betreiber möchte ich im Audit-Log nachweisen können, welcher Schutz aktiv war und mit welchem Ausgang jede Verifikation endete, damit ich meiner Rechenschaftspflicht nachkomme. (Ein Consent-Gate gibt es nicht mehr – der Betrieb läuft consent-los über berechtigtes Interesse.)

## 2. Funktionsumfang / Scope

### 2.1 Im MVP (v2.7.0)
- Ein Anbieter: Friendly Captcha. Keine Anbieterwahl, keine Radio-Buttons.
- Tarif (Gratis/bezahlt) ergibt sich ausschliesslich aus den eingetragenen Keys. Keine Tarif-Auswahl im UI, keine Tarif-Annahme im Code.
- Admin-Konfiguration auf der bestehenden Seite `gfb-settings` als neuer Abschnitt «Spam-Schutz (CAPTCHA)».
- Geltungsbereich: global an/aus plus Override pro Formular über ein neues Block-Attribut am `gfb/form`-Block (`captchaMode`: `inherit` | `on` | `off`).
- Serverseitiges Rendering des Widget-Containers im Formular (passend zur bestehenden Architektur), Skript-Laden per Lazy-Load nach erster Formular-Interaktion (Datensparsamkeit, kein Vorab-Aufruf beim Seitenaufbau).
- Serverseitige Verifikation des Tokens gegen den siteverify-Endpoint von Friendly Captcha, eingehängt als letzte Stufe der Abwehrkette.
- Erzwingungsmodus pro Installation: `soft` (Default) vs. `strict`.
- Schlanker Datenschutz-Hinweis und kopierbarer Datenschutz-Textbaustein.
- Audit-Log-Einträge für Konfigurationsänderungen und für jeden CAPTCHA-Verifikationsausgang.
- i18n: alle neuen Strings über Textdomain `gutenberg-formbuilder` (de/en/fr/it).

### 2.2 Bewusst draussen (Nicht-Ziele MVP)
- Kein zweiter Anbieter (hCaptcha, reCAPTCHA, Turnstile o. a.). hCaptcha ist mit diesem Scope-Entscheid komplett gestrichen.
- Keine Anbieterwahl im UI und keine anbieterabhängige Verzweigung im Submit-Fluss.
- Kein Consent-Mechanismus. Kein Consent-Gate, keine CMP-Anbindung, kein In-Form-Consent. Der Betrieb läuft consent-los über berechtigtes Interesse (siehe E-neu); das verzögerte Laden bei Interaktion bleibt rein als Datensparsamkeit.
- Keine automatische Bearbeitung der Datenschutzerklärung. Nur kopierbare Vorlage.
- Keine Analytics oder Score-Auswertung über die reine Pass/Fail-Verifikation hinaus.
- Keine clientseitige Verifikation. Die Validierung bleibt vollständig serverseitig.

## 3. Akzeptanzkriterien

Format: nummeriert, testbar. Given/When/Then wo sinnvoll. Neo prüft jede Zeile.

### A. Admin-Konfiguration

A1. Auf der Seite `gfb-settings` existiert ein neuer Abschnitt «Spam-Schutz (CAPTCHA)». Er ist nur sichtbar/bedienbar für Nutzer mit `CAP_MANAGE_SETTINGS`; ohne diese Capability erscheint er nicht und ein POST darauf endet mit HTTP 403.

A2. Der Abschnitt nennt Friendly Captcha als einzigen Anbieter (Beschriftung, kein Auswahl-Steuerelement). Es gibt keine Radio-Buttons zur Anbieterwahl und keinen zweiten Anbieter-Block.

A3. Given CAPTCHA aktiv, When der Betreiber speichert, Then werden die Felder «Site-Key» und «API-Key (Secret)» für Friendly Captcha gespeichert. Der API-Key wird serverseitig gehalten und nie ans Frontend ausgegeben.

A4. Es gibt eine globale Schaltung «CAPTCHA aktiv: Ja/Nein». Sie setzt den Default für Formulare im Modus `inherit`: Bei «Nein» rendert kein `inherit`-Formular ein Widget, bei «Ja» rendern alle `inherit`-Formulare (sofern vollständig konfiguriert). Die globale Schaltung ist kein Hauptschalter über den Pro-Formular-Modi: Ein Formular mit `captchaMode` = `on` zeigt das Widget auch bei global «Nein» (lokales Override, siehe A11), ein Formular mit `captchaMode` = `off` zeigt es nie (siehe A11). Voraussetzung für jedes Rendering bleibt die vollständige Konfiguration (Site-Key UND API-Key); fehlen Keys, greift A5.

A5. Given globale Schaltung «Ja» und es fehlt der Site-Key oder das Secret, When der Betreiber speichert, Then zeigt die Seite eine nicht-blockierende Warnung «CAPTCHA ist aktiv, aber unvollständig konfiguriert – es wird vorerst kein Widget angezeigt», und es wird kein Widget gerendert (Fail-open auf die bestehende Kette).

A6. Der Tarif wird nirgends abgefragt oder angezeigt. Es gibt keine Felder, Texte oder Logik, die «Gratis» oder «bezahlt» voraussetzen. Beide Versionen funktionieren mit demselben Key-Paar-Schema (Site-Key + API-Key).

A7. Es gibt einen Erzwingungsmodus als Radio/Select mit zwei Werten: «Mit Ausnahme bei Serverausfall» = `soft` (vorausgewählt) und «Streng» = `strict`. Default im Auslieferungszustand ist `soft`. Interner Speicher-Schlüssel bleibt `soft`/`strict` (keine Migration); geändert haben sich nur die Bedeutung von `soft` (siehe A7b) und die Anzeigenamen.

Beide Modi verlangen grundsätzlich ein bestandenes Captcha. Der einzige Unterschied liegt im Verhalten, wenn der Friendly-Captcha-Server nicht erreichbar ist (Fremdverschulden, sehr selten):

- «Mit Ausnahme bei Serverausfall» (`soft`): «Das Formular verlangt ein bestandenes Captcha. Nur wenn Friendly Captcha einmal nicht erreichbar ist, lässt sich das Formular trotzdem absenden – damit eine seltene Störung beim Dienst Ihre Formulare nicht blockiert.»
- «Streng» (`strict`): «Ohne bestandenes Captcha wird nicht abgesendet – auch dann nicht, wenn Friendly Captcha gerade gestört ist.»

A7b. Beide Modi lehnen einen Submit ab, wenn das Captcha-Token fehlt (Captcha nicht gelöst) oder ungültig ist. Sie unterscheiden sich allein im Fall «Server nicht erreichbar»: `soft` lässt den Submit dann durch (Ausfallsicherung), `strict` lehnt ihn ab. Die vollständige Wahrheitstabelle steht in Abschnitt D (vor D1).

A8. (Ersatzlos entfernt seit 2026-06-14.) Es gibt kein Consent-Verhalten-Auswahlfeld und keine Option «ohne Consent laden». Der Consent-Mechanismus ist komplett gestrichen (Nutzerentscheidung): kein Consent-Gate, kein Filter `gfb_captcha_consent_granted`, kein Ereignis `gfb-captcha-consent`, keine CMP-Anbindung, kein In-Form-Consent. Der Betrieb ist consent-los über berechtigtes Interesse (siehe E-neu). Das verzögerte Laden bei erster Formular-Interaktion bleibt erhalten, neu allein als Datensparsamkeit begründet (kein Vorab-Aufruf beim Seitenaufbau, siehe B2/B3). Die Modi `soft`/`strict` (A7) sind davon unberührt.

A9. Jedes Speichern im CAPTCHA-Abschnitt schreibt einen Audit-Eintrag über `GFB_Audit::record(...)` mit globalem An/Aus-Status und Erzwingungsmodus. Ein Consent-Status wird nicht mehr protokolliert (Consent-Mechanismus entfällt). Secrets werden im Audit-Log nicht im Klartext gespeichert.

A10. Pro Formular existiert am `gfb/form`-Block ein Attribut `captchaMode` mit Werten `inherit` (Default), `on`, `off`. Im Block-Editor (Inspector) ist es als Auswahl «CAPTCHA für dieses Formular: Von globaler Einstellung übernehmen / Immer an / Immer aus» bedienbar. «Immer an» und «Immer aus» sind echte Overrides: «Immer an» erzwingt das Widget auch bei global «Nein», «Immer aus» unterdrückt es auch bei global «Ja» (Wirksamkeit siehe A11). Unter der Auswahl steht ein kurzer Hilfetext: «Immer an: zeigt den Spam-Schutz auf diesem Formular, auch wenn er global ausgeschaltet ist (Keys müssen eingetragen sein).»

A11. Wirksamkeit pro Formular (Override-Semantik): `captchaMode` entscheidet pro Formular, die globale Schaltung liefert nur den Default für `inherit`. Es gelten drei echte Zustände:

- `off` = nie. Kein Widget, keine Verifikation – egal wie global geschaltet ist.
- `on` = immer an. Das Widget greift auch dann, wenn die globale Schaltung auf «Nein» steht (lokales Override nach oben). Voraussetzung bleibt allein, dass Friendly Captcha vollständig konfiguriert ist (Site-Key UND API-Key gesetzt); fehlen Keys, greift A5 (Warnung, kein Widget, Fail-open).
- `inherit` = folgt der globalen Schaltung. Greift nur, wenn global «Ja» UND vollständig konfiguriert.

Wahrheitstabelle (× = Widget/Verifikation greift, – = greift nicht; «konfiguriert» = Site-Key UND API-Key gesetzt):

| globale Schaltung | Keys konfiguriert | `off` | `inherit` | `on` |
|---|---|---|---|---|
| Ja  | ja   | – | × | × |
| Ja  | nein | – | – | – (A5: Warnung, kein Widget) |
| Nein | ja  | – | – | × |
| Nein | nein | – | – | – (A5: Warnung, kein Widget) |

Kernaussagen für Neo: (1) `off` greift nie. (2) `on` greift immer, sobald Keys vollständig sind – auch bei global «Nein». (3) `inherit` greift nur bei global «Ja» und vollständigen Keys. (4) Ohne vollständige Keys greift nichts (Fail-open, A5), unabhängig vom Modus.

### B. Frontend-Einbindung

B1. Der Widget-Container wird serverseitig in `render_form_block()` ausgegeben, innerhalb des `<form class="gfb-form">`, direkt vor dem Submit-Button-Bereich. Platzierung ist visuell als letztes Element vor dem Absenden erkennbar.

B2. Datensparsamkeit (kein Vorab-Aufruf): Given CAPTCHA für ein Formular aktiv, When die Seite initial lädt, Then ist KEIN Friendly-Captcha-Skript im `<head>` oder im Seiten-Body enthalten und es erfolgt kein Netzwerk-Request an Friendly Captcha (kein Vorab-Ping). Begründung: reine Datensparsamkeit – beim Seitenaufbau wird nichts an Friendly Captcha übermittelt. Dies ist kein Consent-Gate (Consent-Mechanismus entfällt komplett).

B3. Lazy-Load bei Interaktion (Datensparsamkeit): Given CAPTCHA für ein Formular aktiv, When der Nutzer zum ersten Mal in ein Formularfeld klickt oder das erste Feld fokussiert, Then wird das Friendly-Captcha-Skript nachgeladen und das Widget gerendert. Das verzögerte Laden ist eine Datensparsamkeits-Massnahme (kein Aufruf an Friendly Captcha vor der Interaktion), keine Einwilligungs-Schranke. Es gibt keine Consent-Abfrage, keinen Consent-Filter und kein Consent-Ereignis.

B4. Das Widget wird zugänglich beschriftet (Label/aria) und ist per Tastatur erreichbar. Es bricht das bestehende Form-Layout nicht.

B5. Es wird ausschliesslich das offizielle Friendly-Captcha-Skript geladen.

B6. Der Widget-Container trägt den Site-Key. Das Secret/der API-Key wird niemals an das Frontend ausgegeben (Test: HTML-Quelltext enthält das Secret nicht).

### C. Serverseitige Verifikation und Einordnung in die Kette

C1. Die CAPTCHA-Verifikation läuft in `GFB_Submit_Handler::handle()` als letzte Abwehr-Stufe, NACH dem Rate-Limit-Check (nach Zeile 206) und VOR der Schema-/Feldverarbeitung (vor Zeile 211). Reihenfolge final: Nonce → HMAC-Token → Honeypot → Rate-Limit → CAPTCHA → Schema/Felder.

C2. Given CAPTCHA für das Formular aktiv und vollständig konfiguriert, When ein Submit eintrifft, Then wird das vom Frontend gelieferte CAPTCHA-Token serverseitig gegen den siteverify-Endpoint von Friendly Captcha geprüft (exakte URL und Request-Format aus der offiziellen Doku, siehe OP-2 – nicht raten), mit dem serverseitig gespeicherten Secret.

C3. Given die siteverify-Antwort meldet Erfolg, When die übrigen Prüfungen bestanden sind, Then wird der Submit normal weiterverarbeitet.

C4. Given die siteverify-Antwort meldet Misserfolg (ungültiges/abgelaufenes Token), Then wird der Submit in BEIDEN Modi (`soft` und `strict`) abgelehnt und der Nutzer per bestehendem `redirect_with_state(...)`-Muster mit einer am Feld angezeigten Fehlermeldung zur Form zurückgeleitet (neuer Status-Slug, siehe Integrationstabelle). Ein ungültiges Token unterscheidet die Modi nicht – nur «Server nicht erreichbar» tut das (siehe D3/D4).

C5. Es wird nur das übermittelt, was Friendly Captcha technisch braucht: das CAPTCHA-Token, das Secret und – falls vom Anbieter verlangt – die Remote-IP. Keine Formularinhalte, keine sensiblen Felder gehen an den Anbieter.

C6. Jeder Verifikationsversuch erzeugt einen Security-Event über `GFB_Security::log_event('captcha_pass'|'captcha_fail'|'captcha_unreachable', [...])` und – sofern auditrelevant – einen `GFB_Audit::record(...)`. Das Event `captcha_skipped_no_consent` entfällt (kein Consent-Pfad mehr). Das rohe Token wird nicht geloggt.

### D. Fehler- und Fallback-Verhalten

Grundsatz (neu seit 2026-06-14): Beide Modi verlangen ein bestandenes Captcha. Fehlendes Token (Captcha nicht gelöst) und ungültiges Token führen in BEIDEN Modi zur Ablehnung. Nur der Fall «Server nicht erreichbar» unterscheidet die Modi: `soft` lässt dann durch (Ausfallsicherung gegen Fremdstörung), `strict` lehnt auch dann ab.

Wahrheitstabelle (durch = Submit geht durch, abgelehnt = Submit wird abgewiesen):

| Captcha-Ergebnis | Mit Ausnahme bei Serverausfall (`soft`) | Streng (`strict`) |
|---|---|---|
| gültig (siteverify Erfolg) | durch | durch |
| fehlt (Captcha nicht gelöst / kein Token) | abgelehnt | abgelehnt |
| ungültig (siteverify Misserfolg) | abgelehnt | abgelehnt |
| Server nicht erreichbar (Timeout/Netzwerkfehler) | durch | abgelehnt |

Kernaussagen für Neo: (1) «gültig» geht in beiden Modi durch. (2) «fehlt» und «ungültig» werden in beiden Modi abgelehnt. (3) Nur «Server nicht erreichbar» trennt die Modi: `soft` durch, `strict` abgelehnt.

D1. Given das Captcha-Token fehlt (Widget nicht gelöst, kein Token übermittelt), When der Nutzer absendet, Then wird der Submit in BEIDEN Modi (`soft` und `strict`) abgelehnt mit einer klaren, am Feld angezeigten, mehrsprachigen Meldung, die erklärt, dass der Spam-Schutz bestätigt werden muss. Ein Event `captcha_fail` wird protokolliert.

D2. Given das Captcha-Token ist ungültig (siteverify-Misserfolg, ungültig/abgelaufen), When der Nutzer absendet, Then wird der Submit in BEIDEN Modi abgelehnt (wie C4), mit am Feld angezeigter, mehrsprachiger Meldung. Ein Event `captcha_fail` wird protokolliert.

D3. Given der siteverify-Endpoint ist nicht erreichbar (Timeout/Netzwerkfehler) und Modus = `soft` («Mit Ausnahme bei Serverausfall»), Then wird fail-open entschieden: der Submit geht durch (Ausfallsicherung), ein Event `captcha_unreachable` wird protokolliert.

D4. Given der siteverify-Endpoint ist nicht erreichbar und Modus = `strict` («Streng»), Then wird fail-closed entschieden: der Submit wird abgelehnt mit am Feld angezeigter Meldung «Spam-Schutz derzeit nicht verfügbar, bitte später erneut versuchen», Event `captcha_unreachable` wird protokolliert.

D5. Es gibt keinen Fall, in dem ein fehlendes oder ungültiges Token den Submit durchlässt. Der einzige Durchlass ohne bestandenes Captcha ist «Server nicht erreichbar» im Modus `soft` (D3). Test: In `soft` führt ein fehlendes Token (D1) und ein ungültiges Token (D2) zur Ablehnung, nicht zum Durchlass.

D6. Alle Fehlermeldungen folgen dem bestehenden Muster: Status-Slug über `redirect_with_state(...)`, Anzeige über die vorhandene `gfb-notice`-Mechanik bzw. am betroffenen Feld, mehrsprachig über die Textdomain. Keine englischen Roh-Strings, keine Debug-Ausgaben im Frontend.

D7. Das siteverify-Request hat ein definiertes Timeout (Vorgabe: höchstens 5 Sekunden), damit ein hängender Anbieter den Submit nicht unbegrenzt blockiert.

### E. Datenschutz-Pflichten (Vorgaben Harvey, Legal – alle als eigene Kriterien)

Einordnung: Friendly Captcha ist EU-Anbieter, arbeitet nach Proof-of-Work, ohne US-Transfer, ohne Fingerprint, ohne Cookies. Das Datenschutzrisiko ist niedrig. Die Pflichten sind entsprechend schlank gehalten – nicht künstlich aufgebläht.

E1. Privacy by Default – Datensparsamkeit (löst das frühere Consent-Gate ab): Given CAPTCHA aktiv, When die Seite lädt, Then ist das Widget noch nicht geladen, bis der Nutzer mit dem Formular interagiert (erster Feldklick/-fokus). Eager-Laden im `<head>` oder beim Seitenaufbau ist in keinem Fall zulässig. Begründung ist reine Datensparsamkeit, kein Consent-Gate. Es gibt keine Einwilligungs-Prüfung als Vorbedingung (siehe B2/B3).

E2. (Entfernt seit 2026-06-14.) Der frühere Consent-Gate-Hook (Filter/Event `gfb_captcha_consent_granted`) entfällt ersatzlos. Es gibt keinen Consent-Filter, kein Consent-Ereignis und keine CMP-Anbindung. Das verzögerte Laden steuert allein die Formular-Interaktion (Datensparsamkeit).

E3. (Entfernt seit 2026-06-14.) Es gibt keine Option «consent-frei laden» mehr, weil es kein Consent-Gate mehr gibt, das man umgehen könnte. Der Standard- und einzige Betrieb ist consent-los über berechtigtes Interesse (siehe E-neu). Die Datenschutz-Informationspflichten dieses Betriebs sind in E-neu geregelt.

E4. Admin-Hinweisblock (schlank): Der Admin-Abschnitt zeigt einen schlanken Datenschutz-Hinweis mit: (a) IP-Verarbeitung in der EU, kein Drittlandtransfer; (b) kein Fingerprint, keine Cookies (Proof-of-Work); (c) AVV im Tarif enthalten; (d) Datenschutzerklärung ergänzen. Der Block ist informativ, nicht als nicht-wegklickbarer Warnblock ausgeführt.

E5. Kopierbarer Datenschutz-Textbaustein: Das Plugin liefert einen kopierbaren Textbaustein für die Datenschutzerklärung (IP-Verarbeitung in der EU, kein Drittlandtransfer, Proof-of-Work statt Tracking), sichtbar als unverbindliche Vorlage gekennzeichnet («unverbindliche Vorlage, keine Rechtsberatung»).

E6. Audit-Rechenschaft: Im Audit-Log ist nachvollziehbar, ob CAPTCHA aktiv ist und mit welchem Ausgang jede Verifikation endete (erfüllt durch A9 und C6). Ein Consent-Gate-Status entfällt (kein Consent-Mechanismus mehr).

E7. Ausfallsicherung statt Koppelungsverbot: Beide Modi verlangen ein bestandenes Captcha (siehe A7/D). Das frühere Koppelungsverbot-Argument greift im consent-losen Standardbetrieb über berechtigtes Interesse nicht – das Koppelungsverbot betrifft nur Einwilligungen. Der Unterschied der Modi liegt allein im Fall «Server nicht erreichbar»: `soft` lässt dann durch (Ausfallsicherung gegen eine Fremdstörung beim Dienst), `strict` lehnt auch dann ab. Im Admin gibt es keinen Koppelungsverbot-Warnhinweis mehr; das Verfügbarkeitsrisiko von `strict` (bei Serverausfall keine Formulare) ist im Erklärtext zu `strict` benannt (A7).

E8. Datensparsamkeit: Es erfolgt kein Vorab-Ping und keine Datenübertragung an Friendly Captcha vor der Formular-Interaktion (siehe B2/B3 – verzögertes Laden als reine Datensparsamkeit). An den siteverify-Endpoint geht ausschliesslich, was technisch nötig ist (siehe C5).

### E-neu: Consent-loser Betrieb – Informationspflichten

**Kontext und Entscheidung (final, 2026-06-14).** Das Plugin verzichtet komplett auf einen Consent-Schritt für den Besucher. Begründung Harvey: Friendly Captcha löst mangels Cookie/Fingerprint die ePrivacy-Einwilligungspflicht wahrscheinlich nicht aus; der Betrieb über berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO / Art. 31 revDSG) ist tragbar. Der einzige Betriebsmodus ist consent-los: Das Widget lädt verzögert bei erster Formular-Interaktion (Datensparsamkeit, kein Vorab-Aufruf – Kriterium B2/B3 gilt weiter). Der frühere Consent-Mechanismus (Consent-Gate E1–E3, In-Form-Consent Abschnitt 7, Filter `gfb_captcha_consent_granted`, Ereignis `gfb-captcha-consent`, CMP-Anbindung) ist ersatzlos gestrichen und wird nicht gebaut.

**Zwingende Konsequenz (Harvey).** Fällt das Consent-Element komplett weg, steigt die Informationspflicht. Die Datenschutzerklärung muss den vollständigen Baustein tragen, nicht nur die schlanke Drei-Punkte-Fassung aus E5. Dieser Unterabschnitt erweitert deshalb E4 (Admin-Hinweis), E5/E7 (Datenschutz-Textbaustein) und ergänzt einen zweiten Baustein (LIA-Vorlage).

**Bau-Scope für Stark (nur dieser Unterabschnitt):** Punkt 1–4 unten – zwei kopierbare Textbausteine plus der angepasste Admin-Hinweis, dargestellt im einklappbaren Datenschutz-Akkordeon (EN9). Der Code-Kern (Lazy-Load bei Interaktion als Datensparsamkeit, siteverify-Verifikation) ist bereits gebaut und getestet (Abschnitt 0–6) und bleibt unverändert. Es gibt keinen Consent-Modus und kein Consent-Verhalten-Auswahlfeld.

#### E-neu.1 Erweiterter Datenschutz-Textbaustein (löst E5 ab)

Der Admin liefert einen kopierbaren Textbaustein für die Datenschutzerklärung des Betreibers. Er ersetzt die schlanke Drei-Punkte-Fassung aus E5 und trägt alle Pflicht-Inhalte für den consent-losen Betrieb. Firmierung, Adresse und konkrete Speicherdauer übernimmt der Betreiber aus seinem AVV bzw. der Anbieter-Doku – das Plugin erfindet sie nicht, sondern markiert sie als Platzhalter.

Wortlaut des Bausteins (kopierbar, im Admin anzeigbar):

```
Spam-Schutz durch Friendly Captcha

Wir setzen auf unseren Formularen den Dienst Friendly Captcha ein, einen
Dienst der Friendly Captcha GmbH, Deutschland. [Vollständige Firmierung und
Anschrift bitte aus Ihrem Auftragsverarbeitungsvertrag übernehmen.]

Zweck. Friendly Captcha schützt unsere Formulare vor Spam und automatisiertem
Missbrauch (etwa durch Bots).

Verarbeitete Daten. Verarbeitet werden Ihre IP-Adresse sowie technische
Angaben Ihres Geräts, die für den Berechnungsnachweis (Proof-of-Work) nötig
sind. Friendly Captcha setzt dabei keine Cookies, nutzt kein Fingerprinting
und kein Tracking.

Funktionsweise. Statt Ihr Verhalten zu beobachten, lässt Friendly Captcha
Ihren Browser im Hintergrund eine kleine Rechenaufgabe lösen (Proof-of-Work).
Diese Aufgabe ist für Menschen unbemerkbar, für massenhaft automatisierte
Anfragen aber aufwendig – so werden Bots ausgebremst, ohne dass Sie verfolgt
werden.

Rechtsgrundlage. Die Verarbeitung stützt sich auf unser berechtigtes Interesse
am Schutz unserer Formulare vor Missbrauch (Art. 6 Abs. 1 lit. f DSGVO; für die
Schweiz Art. 31 revDSG).

Speicherort. Die Verarbeitung erfolgt auf Servern in der Europäischen Union.
Eine Übermittlung in Drittländer findet nicht statt.

Auftragsverarbeitung. Mit der Friendly Captcha GmbH besteht ein
Auftragsverarbeitungsvertrag nach Art. 28 DSGVO (bzw. Art. 9 revDSG).

Speicherdauer. Die Daten werden nur zur Verifikation der Anfrage verarbeitet
und nicht zur Profilbildung genutzt. [Konkrete Speicherdauer bitte aus der
Dokumentation von Friendly Captcha übernehmen.]

Widerspruchsrecht. Sie haben das Recht, aus Gründen, die sich aus Ihrer
besonderen Situation ergeben, jederzeit gegen diese auf berechtigtem Interesse
beruhende Verarbeitung Widerspruch einzulegen (Art. 21 DSGVO).

Ihre Rechte. Ihnen stehen die Rechte auf Auskunft, Berichtigung und Löschung
zu sowie ein Beschwerderecht bei der zuständigen Aufsichtsbehörde.

(Unverbindliche Vorlage, keine Rechtsberatung. Bitte an Ihre konkrete
Situation anpassen und im Zweifel rechtlich prüfen lassen.)
```

Platzhalter sind im Text als `[…]` ausgewiesen (Firmierung/Anschrift, Speicherdauer). Das Plugin füllt sie nicht automatisch.

#### E-neu.2 Zweiter Baustein – LIA-Vorlage (Legitimate Interest Assessment)

Ein zweiter kopierbarer Textbaustein im Admin: ein schlanker interner Vermerk zur Interessenabwägung, etwa eine halbe Seite. Er hilft dem Betreiber, das berechtigte Interesse dokumentiert nachzuweisen (Rechenschaftspflicht). Vier Punkte nach Harvey.

Wortlaut des Bausteins (kopierbar, im Admin anzeigbar):

```
Interessenabwägung (LIA) – Einsatz von Friendly Captcha

Interner Vermerk zur Dokumentation des berechtigten Interesses
(Art. 6 Abs. 1 lit. f DSGVO / Art. 31 revDSG).

1. Zweck / berechtigtes Interesse
   Wir schützen unsere Web-Formulare vor Spam und automatisiertem Missbrauch.
   Funktionierende Formulare ohne Bot-Flut sind die Grundlage für die
   Kommunikation mit unseren Besucherinnen und Besuchern.

2. Erforderlichkeit / kein milderes Mittel
   Reine serverseitige Massnahmen (Honeypot, Rate-Limit) fangen einfache Bots
   ab, aber nicht gezielten automatisierten Missbrauch. Ein Proof-of-Work-
   Captcha ohne Cookies und ohne Tracking ist das mildeste Mittel, das den
   Schutz spürbar erhöht, ohne das Verhalten der Besucher zu beobachten.

3. Interessenabwägung
   Der Eingriff ist gering: Verarbeitet wird allein die IP-Adresse, es findet
   kein Profiling statt, die Verarbeitung erfolgt in der EU und nur kurz zur
   Verifikation. Besucherinnen und Besucher erwarten, dass Formulare gegen
   Spam geschützt sind. Dem geringen Eingriff steht ein legitimer Schutzbedarf
   gegenüber. Die Interessen der Betroffenen überwiegen nicht.

4. Ergebnis
   Der Einsatz von Friendly Captcha auf berechtigtem Interesse ist zulässig.
   Datum: [TT.MM.JJJJ]
   Verantwortliche Person: [Name / Funktion]

(Unverbindliche Vorlage, keine Rechtsberatung. Bitte an Ihre konkrete
Situation anpassen und im Zweifel rechtlich prüfen lassen.)
```

Platzhalter `[…]` (Datum, verantwortliche Person) bleiben leer und werden vom Betreiber ausgefüllt.

#### E-neu.3 Angepasster Admin-Hinweis (erweitert E4)

Der schlanke Admin-Hinweisblock aus E4 wird um drei Punkte ergänzt. Er bleibt informativ, kein nicht-wegklickbarer Warnblock. Inhalt nach Anpassung:

- (a) IP-Verarbeitung in der EU, kein Drittlandtransfer.
- (b) Kein Fingerprint, keine Cookies (Proof-of-Work).
- (c) **Pflicht zum Abschluss eines Auftragsverarbeitungsvertrags (AVV) mit Friendly Captcha.**
- (d) **Im Standardbetrieb ist keine Besucher-Einwilligung nötig (berechtigtes Interesse) – aber die Datenschutzerklärung muss den vollständigen Textbaustein enthalten.**
- (e) **Empfehlung, den internen LIA-Vermerk auszufüllen und abzulegen (Rechenschaftspflicht).**
- (f) Zwei Schaltflächen: «Datenschutz-Textbaustein anzeigen/kopieren» und «LIA-Vorlage anzeigen/kopieren», beide mit dem Zusatz «unverbindliche Vorlage, keine Rechtsberatung».

#### E-neu.4 Betriebsmodus klarstellen

Es gibt genau einen Betriebsmodus: consent-los mit verzögertem Laden bei erster Formular-Interaktion (Datensparsamkeit). Kriterium B2/B3 gilt – kein Eager-Laden, kein Vorab-Request. Einen Consent-Gate- oder In-Form-Consent-Pfad gibt es nicht; Abschnitt 7 ist verworfen (siehe Status-Hinweis oben in Abschnitt 7).

#### E-neu.5 Akzeptanzkriterien (Gruppe E-neu)

Format wie bisher: nummeriert, testbar, Given/When/Then wo sinnvoll. Neo prüft jede Zeile.

EN1. Im CAPTCHA-Abschnitt der Seite `gfb-settings` existiert eine Schaltfläche «Datenschutz-Textbaustein anzeigen/kopieren». When der Betreiber sie auslöst, Then erscheint der vollständige Baustein aus E-neu.1 als kopierbarer Text (Copy-to-Clipboard funktioniert). Die alte Drei-Punkte-Fassung aus E5 wird nicht mehr angezeigt.

EN2. Der angezeigte Datenschutz-Baustein enthält alle elf Pflicht-Inhalte, jeder einzeln prüfbar: (a) Anbieter «Friendly Captcha GmbH, Deutschland» mit Platzhalter für Firmierung/Anschrift; (b) Zweck Spam-/Missbrauchsabwehr; (c) Datenart IP-Adresse + technische Angaben für Proof-of-Work, ausdrücklich keine Cookies, kein Fingerprinting, kein Tracking; (d) ein erklärender Satz zur Funktionsweise Proof-of-Work statt Tracking; (e) Rechtsgrundlage Art. 6 Abs. 1 lit. f DSGVO / Art. 31 revDSG (berechtigtes Interesse); (f) Speicherort EU, ausdrücklich kein Drittlandtransfer; (g) Hinweis auf AVV nach Art. 28 DSGVO / Art. 9 revDSG; (h) Speicherdauer nur zur Verifikation, keine Profilbildung, mit Platzhalter für die konkrete Dauer; (i) Widerspruchsrecht Art. 21 DSGVO; (j) allgemeine Betroffenenrechte (Auskunft, Berichtigung, Löschung, Beschwerderecht); (k) Kennzeichnung «unverbindliche Vorlage, keine Rechtsberatung».

EN3. Der Baustein erfindet keine Firmenadresse und keine konkrete Speicherdauer. Beide stehen als ausgewiesene Platzhalter `[…]` im Text. Test: Der gerenderte Baustein enthält die zwei `[…]`-Platzhalter und keinen erfundenen Adress- oder Dauer-Wert.

EN4. Im CAPTCHA-Abschnitt existiert eine zweite Schaltfläche «LIA-Vorlage anzeigen/kopieren». When der Betreiber sie auslöst, Then erscheint der LIA-Baustein aus E-neu.2 als kopierbarer Text.

EN5. Der LIA-Baustein enthält alle vier Punkte, je einzeln prüfbar: (1) Zweck/berechtigtes Interesse; (2) Erforderlichkeit/kein milderes Mittel; (3) Interessenabwägung mit den Argumenten geringer Eingriff (nur IP, kein Profiling, EU, kurze Verarbeitung) und Erwartung des Besuchers, dass Formulare gegen Spam geschützt sind; (4) Ergebnis plus Platzhalter für Datum und verantwortliche Person. Der Baustein trägt die Kennzeichnung «unverbindliche Vorlage, keine Rechtsberatung».

EN6. Der Admin-Hinweisblock (E4 erweitert) zeigt nach Anpassung die sechs Punkte (a)–(f) aus E-neu.3, insbesondere die AVV-Pflicht, die LIA-Empfehlung und den Hinweis, dass im Standardbetrieb keine Besucher-Einwilligung nötig ist, die Datenschutzerklärung aber den vollständigen Baustein tragen muss. Der Block ist informativ dargestellt, nicht als nicht-wegklickbarer Warnblock.

EN7. Beide Bausteine sind über die Textdomain `gutenberg-formbuilder` übersetzbar (de/en/fr/it), inklusive der Platzhalter-Hinweise. Kein Roh-String ist hartkodiert ohne i18n.

EN8. Der Betrieb ist consent-los mit Lazy-Load (Datensparsamkeit): Given Default-Auslieferung, When eine Seite mit aktivem CAPTCHA lädt, Then erfolgt kein Vorab-Request an Friendly Captcha (B2/B3 gilt) und es erscheint kein Consent-/In-Form-Element und kein Consent-Verhalten-Auswahlfeld. Test bestätigt, dass der Code-Kern aus Abschnitt 0–6 unverändert greift und keine Consent-Logik vorhanden ist.

EN9. Datenschutz-Bereich als Akkordeon: Given die Seite `gfb-settings` ist geöffnet, When der CAPTCHA-Abschnitt rendert, Then ist der gesamte Datenschutz-Bereich (Hinweisblock aus E-neu.3 plus die beiden Schaltflächen/Textbausteine «Datenschutz-Textbaustein» und «LIA-Vorlage») in einem einklappbaren Akkordeon dargestellt. Standardzustand ist eingeklappt: nur eine Zusammenfassungszeile (z. B. «Datenschutz und Vorlagen») ist sichtbar, der Inhalt ist verborgen. When der Betreiber die Zusammenfassungszeile auslöst (Klick/Tastatur), Then klappt der Bereich auf und zeigt Hinweisblock und Textbausteine; erneutes Auslösen klappt ihn wieder ein. Die Darstellung ist konsistent mit dem ClamAV-Hilfe-Akkordeon (gleiches Bedien- und Erscheinungsmuster). Das Akkordeon ist per Tastatur bedienbar und korrekt ausgezeichnet (z. B. `aria-expanded`).

### F. Bestehende Schutzmechanismen bleiben unangetastet

F1. Honeypot, HMAC-Token, Nonce, Rate-Limit, proxy-gehärtete IP-Ermittlung und serverseitiges Rendering funktionieren nach Einbau der CAPTCHA-Erweiterung unverändert. Bestehende Tests dieser Mechanismen bleiben grün.

F2. Der Filter `gfb_rate_limit_max` und der Event-Hook `gfb_security_event` bleiben funktionsfähig und werden nicht durch CAPTCHA-Code überschrieben.

## 4. Admin-UI-Skizze (Wireframe)

Neuer Abschnitt auf Seite «Sicherheit & Einstellungen» (`gfb-settings`), eingeordnet zwischen «ClamAV» und «Berechtigungen».

```
────────────────────────────────────────────────────────────────────
 Spam-Schutz (CAPTCHA) – Friendly Captcha
────────────────────────────────────────────────────────────────────
 CAPTCHA aktiv          ( ) Nein   (•) Ja
 (global)               Default für alle Formulare auf «übernehmen».
                        Pro Formular im Block überschreibbar: «Immer an»
                        erzwingt den Schutz auch bei global «Nein»,
                        «Immer aus» nimmt ein Formular gezielt aus.

 Anbieter               Friendly Captcha (EU, Proof-of-Work,
                        kein Drittlandtransfer)

 Site-Key   [____________________________]
 API-Key    [____________________________]  (Secret, serverseitig)

 (Kein Consent-Feld – der Betrieb ist consent-los über berechtigtes
  Interesse. Das Widget lädt verzögert bei erster Formular-Interaktion
  rein als Datensparsamkeit.)

 ▸ Datenschutz und Vorlagen                         (eingeklappt)
 ───────────────────────────────────────────────────────────────
   (Akkordeon, Standard eingeklappt – konsistent mit dem
    ClamAV-Hilfe-Akkordeon. Aufgeklappt zeigt es:)

   ℹ Datenschutz-Hinweis (Friendly Captcha)
     • IP-Verarbeitung in der EU, kein Drittlandtransfer
     • Kein Fingerprint, keine Cookies (Proof-of-Work)
     • Pflicht: Auftragsverarbeitungsvertrag (AVV) mit Friendly
       Captcha abschliessen
     • Betrieb consent-los (berechtigtes Interesse) – keine
       Besucher-Einwilligung nötig, aber Datenschutzerklärung muss
       den vollständigen Baustein enthalten
     • Empfehlung: internen LIA-Vermerk ausfüllen und ablegen
     [ Datenschutz-Textbaustein anzeigen / kopieren ]
     [ LIA-Vorlage anzeigen / kopieren ]
     (beide: unverbindliche Vorlage, keine Rechtsberatung)

 Erzwingung   (•) Mit Ausnahme bei Serverausfall   ( ) Streng
   Mit Ausnahme bei Serverausfall:
           Das Formular verlangt ein bestandenes Captcha. Nur wenn
           Friendly Captcha einmal nicht erreichbar ist, lässt sich
           das Formular trotzdem absenden – damit eine seltene
           Störung beim Dienst Ihre Formulare nicht blockiert.
   Streng: Ohne bestandenes Captcha wird nicht abgesendet – auch
           dann nicht, wenn Friendly Captcha gerade gestört ist.

 [ CAPTCHA-Einstellungen speichern ]
────────────────────────────────────────────────────────────────────
```

Es gibt nur einen Anbieter-Block. Keine Umschaltlogik, kein zweiter Block, kein nicht-schliessbarer Warnblock.

Block-Editor (Inspector am `gfb/form`-Block), neue Auswahl:
```
 CAPTCHA für dieses Formular
   (•) Von globaler Einstellung übernehmen   (inherit)
   ( ) Immer an                              (on)
   ( ) Immer aus                             (off)

   Immer an: zeigt den Spam-Schutz auf diesem Formular, auch
   wenn er global ausgeschaltet ist (Keys müssen eingetragen sein).
```

## 5. Technische Integrationspunkte (für Stark)

Andockpunkte im bestehenden Code. Reihenfolge in der Kette ist bindend.

| Bereich | Datei / Stelle | Was andocken |
|---|---|---|
| Settings-Abschnitt UI | `includes/class-gfb-admin-settings.php` → `render_page()` | Neuen Abschnitt «Spam-Schutz (CAPTCHA)» zwischen ClamAV und Berechtigungen rendern, eigenes `<form>` mit `gfb_settings_action = save_captcha`. Nur Friendly-Captcha-Felder, keine Anbieterwahl. |
| Settings speichern | `includes/class-gfb-admin-settings.php` → `maybe_handle_post()` | Neuen `case 'save_captcha':` mit `check_admin_referer('gfb_settings_action')`, Speicherung, `GFB_Audit::record('settings_captcha_saved', 'config', '', [...])`. |
| Capability | bestehend `GFB_Capabilities::CAP_MANAGE_SETTINGS` | Wiederverwenden, keine neue Cap nötig (siehe offene Punkte). |
| Persistenz | WP-Options (analog ClamAV-Settings-Muster) | Ein Options-Eintrag, z. B. `gfb_captcha_settings` (global on/off, Site-Key, API-Key, Erzwingungsmodus). Kein Consent-Feld (Consent-Mechanismus entfällt). Kein `provider`-Feld nötig (nur ein Anbieter); falls Stark eines zur Vorbereitung eines späteren zweiten Anbieters setzen will, fix auf `friendly`. |
| Widget-Render Frontend | `includes/class-gfb-plugin.php` → `render_form_block()` (ab Zeile 744, vor Submit-Bereich) | Widget-Container ausgeben, wenn für dieses Formular aktiv (global + `captchaMode`). Nur Site-Key, nie Secret. |
| Block-Attribut | `blocks/form/block.json` + Editor-Inspector | Neues Attribut `captchaMode` (`inherit`/`on`/`off`), Default `inherit`. |
| Lazy-Load (Datensparsamkeit) | Frontend-Script (passend zur bestehenden Frontend-JS-Struktur) | Friendly-Captcha-Skript erst nach erstem Feld-Fokus laden (Datensparsamkeit). Kein Consent-Filter, kein Consent-Ereignis, keine CMP-Anbindung. |
| Verifikation | `includes/class-gfb-submit-handler.php` → `handle()`, NACH Zeile 206 (Rate-Limit), VOR Zeile 211 (Schema) | siteverify-Aufruf gegen Friendly-Captcha-Endpoint (`wp_remote_post`, Timeout ≤ 5 s); bei Fail je nach Modus `redirect_with_state(...)` mit neuem Status-Slug. |
| Status-Slug / Meldung | `includes/class-gfb-submit-handler.php` → `status_messages()` | Neue Slugs `STATUS_ERR_CAPTCHA` (Token ungültig) und `STATUS_ERR_CAPTCHA_UNREACHABLE` (Anbieter nicht erreichbar), i18n. |
| Security-Event | `GFB_Security::log_event(...)` | Events `captcha_pass`, `captcha_fail`, `captcha_unreachable` (ohne rohes Token). Kein `captcha_skipped_no_consent` (Consent-Pfad entfällt). |
| Audit | `GFB_Audit::record(...)` | Konfig-Speicherung und auditrelevante Verifikationsausgänge, ohne Secret im Klartext. Kein Consent-Gate-Status (entfällt). |
| i18n | Textdomain `gutenberg-formbuilder`, `/languages` | Alle neuen Strings übersetzbar; de/en/fr/it pflegen. |

Bindende Kettenreihenfolge in `handle()`:
1. Nonce (vorhanden, Zeile 187)
2. HMAC-Token (vorhanden, Zeile 195)
3. Honeypot (vorhanden, Zeile 201)
4. Rate-Limit (vorhanden, Zeile 206)
5. CAPTCHA (NEU, direkt danach)
6. Schema- und Feldverarbeitung (vorhanden, ab Zeile 211)

## 6. Offene Punkte / Entscheidungsbedarf fürs Team (nicht für den Nutzer)

OP-1 (Stark): Capability-Slug. Empfehlung: bestehende `CAP_MANAGE_SETTINGS` wiederverwenden, keine neue Cap. Bitte bestätigen, dass kein separater CAPTCHA-Recht-Slug nötig ist.

OP-2 (Stark): Friendly-Captcha-siteverify-Endpoint-URL und genaues Request-Format gegen die aktuelle Anbieter-Doku verifizieren. Endpoint und Parameter nicht hartkodiert raten, sondern aus der offiziellen Doku übernehmen.

OP-3 (Stark): Provider-Abstraktion – schlank halten, nicht über-abstrahieren. Da nur ein Anbieter existiert, ist keine Anbieter-Verzweigung im Submit-Fluss nötig. Empfehlung: die CAPTCHA-Logik in einer eigenen `GFB_Captcha`-Klasse (`get_settings()`/`update_settings()`/`render_widget()`/`verify()`) kapseln – analog `GFB_Clamav` –, sodass ein zweiter Anbieter später hinter `verify()`/`render_widget()` nachrüstbar wäre, ohne `handle()` oder den Submit-Fluss umzubauen. Keine Plugin-Registry, keine Strategy-Schichten im MVP. Bestätigen.

OP-4 (Stark): Settings-Speicherort. Empfehlung: ein WP-Option-Blob `gfb_captcha_settings` analog zum ClamAV-Settings-Muster, gekapselt in der `GFB_Captcha`-Klasse aus OP-3.

OP-5 (HINFÄLLIG seit 2026-06-14). Die Frage nach einem JS-Event-Spiegel für den `gfb_captcha_consent_granted`-Filter entfällt: Der Consent-Mechanismus ist komplett gestrichen. Es gibt keinen Consent-Filter, kein Consent-Ereignis und keine CMP-Anbindung. Stark baut hier nichts.

OP-6 (HINFÄLLIG seit 2026-06-14). Die frühere Strict-Quittung (einmaliger Pflichtdialog zum Quittieren des Koppelungsverbot-Risikos beim Umschalten auf `strict`) entfällt. Begründung: Im consent-losen Standardbetrieb über berechtigtes Interesse ist das Koppelungsverbot gegenstandslos – es betrifft nur Einwilligungen. Damit ist die Begründung der Quittung weggefallen. Das Verfügbarkeitsrisiko von `strict` (bei Serverausfall keine Formulare) ist im Erklärtext zu `strict` (A7) abgedeckt. Stark baut keinen Strict-Quittungsdialog. Siehe auch H5 (ebenfalls hinfällig).

OP-7 (Stark): Verhalten bei aktiver Schaltung und leeren Keys → greift A5 (Warnung, kein Widget). Bestätigen, dass kein Hard-Fail entsteht.

## Das ist erledigt, wenn …

- Der Betreiber CAPTCHA (Friendly Captcha) aktiviert, Keys einträgt, global und pro Formular schaltet (A1–A11).
- Das Widget serverseitig im Formular sitzt, erst nach erster Formular-Interaktion lädt (Datensparsamkeit) und nie eager – ohne jeden Consent-Schritt (B1–B6, E1).
- Der Submit das Token gegen den Friendly-Captcha-siteverify-Endpoint prüft, exakt als 5. Stufe nach dem Rate-Limit (C1–C6).
- Beide Modi ein bestandenes Captcha verlangen: fehlendes und ungültiges Token werden in beiden Modi abgelehnt; nur «Server nicht erreichbar» trennt die Modi (`soft` durch, `strict` abgelehnt), jede Fehlerlage mit am Feld angezeigter, mehrsprachiger Meldung (A7, A7b, C4, D1–D7).
- Alle Datenschutz-Pflichten technisch umgesetzt sind: Admin-Hinweis und beide Textbausteine im einklappbaren Akkordeon (EN9), Audit-Rechenschaft, Ausfallsicherung statt Koppelungsverbot, Datensparsamkeit (E1, E4, E6–E8, E-neu/EN1–EN9). Kein Consent-Gate, kein Hook, kein In-Form-Consent.
- Honeypot, HMAC, Nonce, Rate-Limit und Rendering unverändert grün bleiben (F1–F2).

## 7. VERWORFEN: Situative In-Form-Einwilligung

> **STATUS (2026-06-14): VERWORFEN – WIRD NICHT GEBAUT, KEINE RESERVE.**
> Nutzerentscheidung (final): Der Consent-Mechanismus wird komplett entfernt – aus Oberfläche und Logik. Im consent-losen Betrieb über berechtigtes Interesse ist er sinnlos (Harvey: Friendly Captcha löst mangels Cookie/Fingerprint die ePrivacy-Einwilligungspflicht wahrscheinlich nicht aus). Damit ist dieser gesamte Abschnitt 7 (In-Form-Element, `in_form`-Modus, das dreiwertige «Consent-Verhalten»-Auswahlfeld, der Filter `gfb_captcha_consent_granted`, das Ereignis `gfb-captcha-consent`, jede CMP-Anbindung) **verworfen**. Er ist **keine Reserve** und keine spätere Ausbaustufe. Stark baut nichts aus Abschnitt 7. Neo testet nichts aus Abschnitt 7. Der Inhalt unten bleibt nur als historischer Kontext stehen, damit klar ist, was bewusst gestrichen wurde – er darf nicht umgesetzt werden.
>
> Was zu bauen ist, steht in Abschnitt 3 (A–F), in Abschnitt E-neu (Datenschutz-Bausteine + Admin-Hinweis) und in EN9 (Datenschutz-Akkordeon). Das verzögerte Laden bei Interaktion bleibt als reine Datensparsamkeit (B2/B3), nicht als Einwilligungs-Schranke.

Ziel-Version: 2.7.0 · ergänzt Abschnitt 0–6, ersetzt sie nicht. Diese Erweiterung führt ein kontextbezogenes Bedienelement im Formular ein, über das der Besucher den Spam-Schutz situativ und informiert freigibt – getrennt vom globalen Cookie-Banner. Rechtlich ist diese situative Zustimmung kein Muss (Friendly Captcha löst mangels Cookie/Fingerprint die ePrivacy-Einwilligungspflicht wohl nicht aus; Grundbetrieb über berechtigtes Interesse tragbar), aber ein datenschutzfreundliches Transparenz-Plus. Sie ergänzt die bestehende Lösung als wählbarer Modus, sie ersetzt sie nicht erzwungen.

### 7.1 Problem und Nutzer

Das Problem: Besucher lehnen den globalen Cookie-Banner oft pauschal ab, wollen danach aber per Formular bestellen oder anfragen. Das globale «Nein» blockiert dann auch den harmlosen Spam-Schutz – obwohl der Besucher in diesem konkreten Moment dem Absenden zustimmt. Es fehlt der Ort für eine kontextbezogene, informierte Entscheidung genau dort, wo sie zählt: im Formular.

US-7 (Endnutzer, situative Kontrolle):
Als Formular-Ausfüller möchte ich den Spam-Schutz direkt im Formular aktivieren oder weglassen können, mit klarer Angabe wer, was, wozu, damit ich situativ entscheide, statt von einem pauschalen Banner-Klick abhängig zu sein.

US-8 (Betreiber, Transparenz-Plus):
Als datenschutzbewusster Betreiber möchte ich die Einwilligung situativ im Formular einholen, damit ich Transparenz zeige und Besucher, die den globalen Banner ablehnen, trotzdem absenden können.

### 7.2 Konfigurationsmodell

Entscheid: Die bisherige Checkbox «Friendly Captcha ohne Consent laden» (A8) und die implizite Interaktions-Logik werden in EIN dreiwertiges Auswahlfeld «Consent-Verhalten» zusammengeführt. Begründung:

- Betreiber-Nutzen: Ein Schalter mit drei sich gegenseitig ausschliessenden Werten ist eindeutig. Eine Checkbox neben einer impliziten Lade-Logik erzeugt überlappende, schwer erklärbare Zustände («Checkbox aus, aber lädt nach Klick – Checkbox an, dann lädt sofort»). Ein Auswahlfeld zwingt zu genau einer bewussten Wahl und ist im Audit-Log sauber als ein Wert protokollierbar.
- Endnutzer-Nutzen: Jeder Modus beschreibt ein klares, vorhersehbares Verhalten am Frontend. Kein Mischzustand, keine Überraschung beim Laden.

Auswahlfeld «Consent-Verhalten» (Select, ein Wert), Option-Key z. B. `gfb_captcha_consent_mode`:

| Wert | Beschriftung Admin | Verhalten Frontend |
|---|---|---|
| `on_interaction` (Default) | «Nach Interaktion laden» | Bisheriges Default-Verhalten: Widget lädt nach erstem Feld-Fokus (Lazy-Load), kein In-Form-Element. Entspricht B3. |
| `in_form` | «Situative Zustimmung im Formular» | Neues In-Form-Element. Widget lädt erst nach aktivem Klick im Element. Vor dem Klick kein Friendly-Captcha-Request. |
| `no_consent` | «Ohne Consent laden» | Bisherige Option «ohne Consent laden». Widget darf consent-frei laden (juristisch im Einzelfall zu prüfen, siehe E3). |

Der Modus `soft`/`strict` (A7, Erzwingung) bleibt ein getrennter Schalter. Consent-Verhalten und Erzwingung sind orthogonal: Das Consent-Verhalten regelt, WANN und WIE das Widget freigegeben wird; die Erzwingung regelt, was bei fehlendem/fehlgeschlagenem CAPTCHA beim Absenden passiert. Das In-Form-Element (`in_form`) formuliert seinen Text abhängig vom Erzwingungsmodus (siehe 7.3).

Migration: Eine bestehende Installation mit Checkbox «ohne Consent laden» = AUS wird auf `on_interaction` abgebildet, mit Checkbox = EIN auf `no_consent`. Kein Verhaltensbruch für Bestandsinstallationen.

### 7.3 UX des In-Form-Elements

Platzierung: Das In-Form-Element steht innerhalb des `<form class="gfb-form">`, an derselben Stelle, wo sonst der Widget-Container sitzt (direkt vor dem Submit-Bereich, siehe B1). Reihenfolge: Element zuerst, Widget lädt erst nach Klick. Vor dem Klick existiert nur das Element, kein Friendly-Captcha-Skript und kein Request.

Symmetrie (Harvey-Vorgabe 6): «Aktivieren» und «Nicht aktivieren» werden gleichwertig dargestellt. Zwei gleichrangige Schaltflächen nebeneinander, gleiche Grösse, gleiches Gewicht, keine farblich dominante «Aktivieren»-Taste neben einem versteckten Verzicht. Keine Vorauswahl, kein vorangekreuztes Feld.

#### Zustand A – `soft`-Modus (Einwilligungsformulierung)

```
┌────────────────────────────────────────────────────────────┐
│  Spam-Schutz aktivieren?                                     │
│                                                              │
│  Dieses Formular kann durch Friendly Captcha vor Spam        │
│  geschützt werden. Dabei wird Ihre IP-Adresse verarbeitet    │
│  (Server in der EU, kein Transfer in Drittländer, kein       │
│  Tracking, keine Cookies). Mehr dazu in unserer              │
│  ‣ Datenschutzerklärung.                                     │
│                                                              │
│  Sie können das Formular auch ohne Aktivierung absenden.     │
│  Eine Aktivierung lässt sich jederzeit widerrufen.           │
│                                                              │
│   [  Spam-Schutz aktivieren  ]   [  Ohne Spam-Schutz  ]      │
│        (gleichwertig)                 (gleichwertig)         │
└────────────────────────────────────────────────────────────┘
```

Nach Klick auf «Spam-Schutz aktivieren»: Element klappt in den aktivierten Zustand, Widget lädt (siehe 7.4):

```
┌────────────────────────────────────────────────────────────┐
│  ✓ Spam-Schutz aktiv (Friendly Captcha)   [ Widerrufen ]    │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  [ Friendly-Captcha-Widget lädt hier ]               │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────┘
```

#### Zustand B – `strict`-Modus (Erforderlichkeitsformulierung)

Im `strict`-Modus wird NICHT um freiwillige Zustimmung gebeten (Harvey-Vorgabe 1), weil der Schutz danach erzwungen wird. Das Element formuliert als Hinweis auf Erforderlichkeit, ohne «aktivieren?»-Frage, ohne «Ohne Spam-Schutz»-Option (die es im strict-Modus nicht gibt).

```
┌────────────────────────────────────────────────────────────┐
│  Spam-Prüfung erforderlich                                  │
│                                                              │
│  Zum Absenden dieses Formulars ist eine Spam-Prüfung durch   │
│  Friendly Captcha nötig. Dabei wird Ihre IP-Adresse          │
│  verarbeitet (Server in der EU, kein Transfer in             │
│  Drittländer, kein Tracking, keine Cookies). Mehr dazu in    │
│  unserer ‣ Datenschutzerklärung.                             │
│                                                              │
│   [  Spam-Prüfung starten  ]                                 │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  [ Friendly-Captcha-Widget lädt hier nach Klick ]    │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────┘
```

#### Zustand C – globale Ablehnung liegt vor (neutraler Reaktivierungs-Hinweis)

Liefert die CMP ein globales «Nein» (über den bestehenden Hook, siehe 7.5/E-neu), lädt das Widget nicht automatisch (Harvey-Vorgabe 3). Im Formular erscheint ein neutraler, nicht-drängender Hinweis, der eine NEUE aktive Entscheidung ermöglicht – kein Auto-Override, kein Dark Pattern. Formulierung neutral, ohne Drängen («Sie haben den Spam-Schutz global abgelehnt – nur hier und nur für dieses Formular können Sie ihn aktivieren»). Im `soft`-Modus bleibt der Verzicht gleichwertig sichtbar.

```
┌────────────────────────────────────────────────────────────┐
│  Spam-Schutz ist global deaktiviert                         │
│                                                              │
│  Sie haben den Spam-Schutz seitenweit abgelehnt. Nur für     │
│  dieses Formular und nur jetzt können Sie ihn aktivieren –   │
│  Ihre globale Einstellung bleibt unverändert. Es werden      │
│  IP-Adresse (EU), kein Tracking, keine Cookies verarbeitet.  │
│  ‣ Datenschutzerklärung.                                     │
│                                                              │
│   [  Nur hier aktivieren  ]   [  Ohne Spam-Schutz  ]         │
│        (gleichwertig)              (gleichwertig)            │
└────────────────────────────────────────────────────────────┘
```

Im `strict`-Modus bei globaler Ablehnung entfällt der gleichwertige Verzicht; der Hinweis nennt die Erforderlichkeit neutral und bietet «Nur hier starten» als einzige Aktion, ohne Drängen.

### 7.4 Verhalten / Flow

- Klick auf «Spam-Schutz aktivieren» / «Spam-Prüfung starten» / «Nur hier aktivieren»: Das Element löst das bestehende Custom-Event `gfb-captcha-consent` aus (kein neuer Mechanismus). `assets/captcha.js` reagiert wie bei der bisherigen Lazy-Load-Freigabe und lädt das Friendly-Captcha-Skript und das Widget. Erst ab diesem Klick erfolgt ein Netzwerk-Request an Friendly Captcha.
- Klick auf «Ohne Spam-Schutz» (nur `soft`): Das Element merkt sich den Verzicht für diese Formular-Instanz, lädt kein Widget, kein Request. Der Submit läuft über die bestehende Kette (Nonce, HMAC, Honeypot, Rate-Limit). Verhält sich wie D1 (`captcha_skipped_no_consent`).
- Nicht-Aktivierung ohne Klick (`soft`): Sendet der Nutzer ab, ohne das Element zu bedienen, gilt wie «Ohne Spam-Schutz» – Submit geht durch, Event `captcha_skipped_no_consent`. Kein Zwang zur Bedienung.
- Nicht-Aktivierung (`strict`): Ohne Klick auf «Spam-Prüfung starten» und bestandenes CAPTCHA wird der Submit abgelehnt (wie D2), mit am Feld angezeigter Meldung. Das Element drängt nicht, es informiert nur über die Erforderlichkeit.
- Widerruf (`soft`, aktiviertes Element): Klick auf «Widerrufen» entlädt das Widget aus der Ansicht, setzt das Element in den Ausgangszustand zurück und verwirft ein eventuell schon erzeugtes CAPTCHA-Token für diese Instanz. Ein bereits geladenes Skript muss nicht erneut vom Anbieter abgerufen werden; ein neuer Klick auf «aktivieren» reaktiviert das Widget. Im `strict`-Modus gibt es keinen Widerruf (Schutz ist erforderlich).
- Mehrere Formulare auf einer Seite: Jede Formular-Instanz hat ihr eigenes In-Form-Element und ihren eigenen Zustand. Die Freigabe in Formular A aktiviert nicht das Widget in Formular B.

### 7.5 Akzeptanzkriterien (neue Gruppe G + Datenschutz-Gruppe H)

Format wie bisher: nummeriert, testbar, Given/When/Then. Neo prüft jede Zeile.

#### G. Konfiguration und In-Form-Element

A8-neu. Auf der Seite `gfb-settings` existiert im CAPTCHA-Abschnitt ein Auswahlfeld «Consent-Verhalten» (Select) mit genau drei Werten: «Nach Interaktion laden» (`on_interaction`, Default), «Situative Zustimmung im Formular» (`in_form`), «Ohne Consent laden» (`no_consent`). Die frühere Checkbox «ohne Consent laden» existiert nicht mehr. Given eine Bestandsinstallation mit alter Checkbox = AUS, When migriert wird, Then steht das Auswahlfeld auf `on_interaction`; bei alter Checkbox = EIN auf `no_consent`.

G1. Given `Consent-Verhalten = in_form` und CAPTCHA für das Formular aktiv, When die Seite lädt, Then erscheint das In-Form-Element an der Widget-Position (vor dem Submit-Bereich), und es ist KEIN Friendly-Captcha-Skript geladen und kein Request an Friendly Captcha erfolgt (Prüfung wie B2).

G2. Given `in_form` und Erzwingungsmodus `soft`, When das Element rendert, Then trägt es die Einwilligungsformulierung («Spam-Schutz aktivieren?») und zeigt zwei gleichwertige Schaltflächen «Spam-Schutz aktivieren» und «Ohne Spam-Schutz» ohne Vorauswahl.

G3. Given `in_form` und Erzwingungsmodus `strict`, When das Element rendert, Then trägt es die Erforderlichkeitsformulierung («Spam-Prüfung erforderlich»), zeigt nur die Schaltfläche «Spam-Prüfung starten» und KEINE «Ohne Spam-Schutz»-Option, und bittet nirgends um freiwillige Zustimmung.

G4. Given `in_form`, When der Nutzer auf «Spam-Schutz aktivieren» / «Spam-Prüfung starten» klickt, Then wird das Custom-Event `gfb-captcha-consent` ausgelöst, `assets/captcha.js` lädt das Friendly-Captcha-Skript, das Widget rendert, und erst ab diesem Moment erfolgt der erste Request an Friendly Captcha.

G5. Given `in_form` und `soft`, When der Nutzer auf «Ohne Spam-Schutz» klickt oder ohne Bedienung absendet, Then lädt kein Widget, der Submit geht über die bestehende Kette durch, und ein Event `captcha_skipped_no_consent` wird protokolliert.

G6. Given `in_form` und `soft` und ein aktiviertes Element, When der Nutzer auf «Widerrufen» klickt, Then wird das Widget aus der Ansicht entfernt, ein bereits erzeugtes CAPTCHA-Token für diese Instanz verworfen und das Element steht wieder im Ausgangszustand mit beiden gleichwertigen Schaltflächen.

G7. Given `in_form` und `strict`, When der Nutzer absendet, ohne das CAPTCHA zu bestehen, Then wird der Submit abgelehnt (wie D2) mit am Feld angezeigter, mehrsprachiger Meldung. Im `strict`-Modus existiert keine «Widerrufen»-Schaltfläche.

G8. Given zwei Formulare mit `in_form` auf einer Seite, When der Nutzer das Element in Formular A aktiviert, Then bleibt das Element in Formular B im Ausgangszustand (instanz-isolierter Zustand).

G9. Given `Consent-Verhalten = on_interaction`, When die Seite lädt, Then verhält sich alles wie im bisherigen Default (B3): kein In-Form-Element, Lazy-Load nach erstem Feld-Fokus.

G10. Given `Consent-Verhalten = no_consent`, When die Seite lädt, Then darf das Widget consent-frei laden (bisheriges Verhalten der Checkbox EIN), kein In-Form-Element.

G11. Jedes Speichern des Felds «Consent-Verhalten» schreibt einen Audit-Eintrag über `GFB_Audit::record(...)` mit dem gewählten Wert (`on_interaction`/`in_form`/`no_consent`), ergänzend zu A9.

#### H. Datenschutz-Kriterien In-Form-Element (Harvey-Vorgaben 1–6, je eigen testbar)

H1 (Vorgabe 1 – zwei getrennte Zustände je Modus). Given `in_form`, When `soft`, Then formuliert das Element als Einwilligung («aktivieren?»); When `strict`, Then formuliert es als Erforderlichkeit/Hinweis ohne Einwilligungsbitte. Test: Der `strict`-Text enthält keine Formulierung, die um freiwillige Zustimmung bittet.

H2 (Vorgabe 2 – Pflichtinhalte). Das In-Form-Element trägt in jedem Zustand alle folgenden Angaben: (a) Anbietername «Friendly Captcha»; (b) Zweck «Spam-Abwehr»; (c) Datenart «IP-Adresse, Server in der EU, kein Drittlandtransfer, kein Tracking, keine Cookies»; (d) Link zur Datenschutzerklärung; (e) aktive Schaltfläche OHNE Vorauswahl/vorangekreuztes Feld; (f) Hinweis auf Widerrufbarkeit (im `soft`-Modus). Test: Jeder der sechs Punkte ist im gerenderten Element vorhanden.

H3 (Vorgabe 3 – globale Ablehnung respektieren). Given ein globales «Nein» über das CMP-Signal (bestehender Hook), When `in_form`, Then lädt das Widget nicht automatisch, und das Element zeigt den neutralen Reaktivierungs-Hinweis (Zustand C), der eine neue aktive Entscheidung erlaubt. Test: Kein Auto-Load bei globalem «Nein»; der Hinweistext drängt nicht (keine wertende/abwertende Formulierung), bietet aber eine aktive Schaltfläche. Hinweis: Das Plugin liefert nur den Hook; die konkrete CMP-Signal-Verzahnung ist Sache des Betreibers (siehe OP-9).

H4 (Vorgabe 4 – Soft-Default klar kommunizieren). Given `in_form` und `soft`, Then macht das Element erkennbar, dass das Formular auch ohne Aktivierung absendbar ist (Satz «Sie können das Formular auch ohne Aktivierung absenden» und gleichwertige «Ohne Spam-Schutz»-Schaltfläche). Test: Der Hinweis ist vorhanden und keine Formulierung suggeriert, dass ohne Aktivierung nichts geht.

H5 (HINFÄLLIG seit 2026-06-14 – Strict-Quittung entfällt). Die frühere Strict-Betreiber-Quittung (einmaliger Pflichtdialog beim Umschalten auf `strict`, der das Koppelungsverbot quittieren liess) ist gestrichen. Begründung: Im consent-losen Standardbetrieb über berechtigtes Interesse ist das Koppelungsverbot gegenstandslos (es betrifft nur Einwilligungen); damit ist die Begründung der Quittung weggefallen. Das Verfügbarkeitsrisiko von `strict` ist im Erklärtext zu `strict` (A7) abgedeckt. Stark baut keinen Quittungsdialog; Neo testet keinen. Siehe auch OP-6 (hinfällig).

H6 (Vorgabe 6 – kein Dark Pattern / Symmetrie). Given `in_form` und `soft`, Then sind «aktivieren» und «nicht aktivieren» gleichwertig dargestellt: gleiche Schaltflächengrösse, gleiches visuelles Gewicht, keine farblich dominante «Aktivieren»-Taste, der Verzicht ist nicht versteckt. Test: Beide Schaltflächen sind sichtbar, gleich gross, keine ist als alleinige Primär-Aktion hervorgehoben.

### 7.6 Technische Andockpunkte (für Stark)

Aufbauend auf der bestehenden Architektur. Filter `gfb_captcha_consent_granted` und Event `gfb-captcha-consent` sind bereits vorhanden und werden wiederverwendet – kein neuer Consent-Mechanismus.

| Bereich | Datei / Stelle | Was andocken |
|---|---|---|
| Settings-Feld | `includes/class-gfb-admin-settings.php` → CAPTCHA-Abschnitt | Checkbox «ohne Consent laden» durch Select «Consent-Verhalten» (`gfb_captcha_consent_mode`: `on_interaction`/`in_form`/`no_consent`) ersetzen. Migration der Altwerte (AUS→`on_interaction`, EIN→`no_consent`). |
| Settings speichern | `maybe_handle_post()` → `case 'save_captcha'` | Neuen Wert validieren (Whitelist der drei Keys), persistieren, Audit-Eintrag (G11). |
| ~~Strict-Quittung~~ (entfällt) | – | HINFÄLLIG seit 2026-06-14: keine Strict-Quittung mehr (Koppelungsverbot gegenstandslos im consent-losen Betrieb). Stark baut hier nichts (siehe H5/OP-6). |
| Widget-Render | `GFB_Captcha::render_widget()` (aus OP-3) bzw. `render_form_block()` | Bei `consent_mode = in_form` statt des nackten Widget-Containers das In-Form-Element rendern; Zustand A/B je Erzwingungsmodus, Zustand C bei globalem «Nein». Text-Pflichtinhalte (H2) einsetzen. Widget-Container bleibt leer, bis das Element den Consent auslöst. |
| Frontend-Logik | `assets/captcha.js` | In-Form-Element verdrahten: Klick «aktivieren» → bestehendes Event `gfb-captcha-consent` feuern → Skript/Widget laden. Klick «Ohne Spam-Schutz»/«Widerrufen» → Zustand zurücksetzen, Token verwerfen. Instanz-Isolation pro Formular (G8). |
| Block-Attribut | `blocks/form/block.json` | Kein neues Pflicht-Attribut nötig; das Consent-Verhalten ist global. Optionales Pro-Formular-Override des Consent-Verhaltens ist NICHT im Scope dieser Erweiterung (siehe OP-8). |
| Datenschutzlink | bestehende Privacy-Policy-Page-Option von WordPress | Link zur Datenschutzerklärung im Element aus der WP-Einstellung «Datenschutzseite» beziehen, falls gesetzt; sonst Admin-Hinweis (OP-10). |
| i18n | Textdomain `gutenberg-formbuilder` | Alle neuen Element-Strings (Zustand A/B/C, Schaltflächen) übersetzbar, de/en/fr/it. |

### 7.7 Offene Punkte / Entscheidungsbedarf fürs Team (nicht für den Nutzer)

OP-8 (Ripley/Stark): Pro-Formular-Override des Consent-Verhaltens über ein Block-Attribut – im Scope dieser Erweiterung bewusst NICHT vorgesehen; das Consent-Verhalten bleibt global. Empfehlung: erst nachrüsten, wenn ein Betreiber es real braucht. Bestätigen.

OP-9 (Stark/Harvey): CMP-Signal für globales «Nein» (Zustand C). Das Plugin liefert nur den Hook; wie das globale «Nein» konkret ans In-Form-Element gemeldet wird (Filter-Rückgabe `false` vs. eigenes Signal), muss definiert werden. Empfehlung: bestehenden `gfb_captcha_consent_granted`-Filter und das JS-Event nutzen; ein zusätzliches «explizit abgelehnt»-Signal von einem «noch nicht entschieden»-Signal unterscheiden, damit Zustand C nur bei echtem globalem «Nein» erscheint.

OP-10 (Stark): Quelle des Datenschutz-Links im Element. Empfehlung: WP-Privacy-Policy-Page-Option nutzen; ist keine gesetzt, im Admin warnen, dass der Link fehlt, statt einen toten Link zu rendern.

OP-11 (Neo): Test der Symmetrie (H6) und der Pflichtinhalte (H2) braucht einen DOM-Snapshot-Vergleich. Bestätigen, dass ein gerenderter HTML-Snapshot je Modus als Testartefakt taugt.

### 7.8 Das ist erledigt, wenn …

- Das Auswahlfeld «Consent-Verhalten» die alte Checkbox ablöst, drei Werte bietet und Bestandsinstallationen sauber migriert (A8-neu, G11).
- Bei `in_form` das In-Form-Element vor dem Widget erscheint, das Widget erst nach aktivem Klick lädt und kein Vorab-Request erfolgt (G1, G4).
- `soft` als Einwilligung mit zwei gleichwertigen Schaltflächen, `strict` als Erforderlichkeitshinweis ohne Zustimmungsbitte rendert (G2, G3, H1).
- Globale Ablehnung respektiert wird: kein Auto-Load, neutraler Reaktivierungs-Hinweis mit neuer aktiver Entscheidung (H3).
- Alle sechs Pflichtinhalte im Element stehen, der Soft-Default klar kommuniziert ist, die Strict-Quittung im Audit liegt und keine Schaltfläche dominiert (H2, H4, H5, H6).
- `on_interaction` und `no_consent` das bisherige Verhalten unverändert abbilden (G9, G10).
