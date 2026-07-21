# Integrations-Hooks für Dritt-Plugins

Blitz & Donner Formular stellt drei neutrale Hooks bereit, mit denen Dritt-Plugins (z. B. eine CRM- oder Marketing-Anbindung) Einsendungen DOI- und einwilligungsbewusst weiterverarbeiten. Das Plugin selbst enthält keine Anbindungs-Logik. Die Filter geben ausschliesslich boolesche bzw. Status-Werte heraus – nie Feldinhalte.

## Referenz

| Hook | Signatur | Semantik |
|---|---|---|
| `gfb_doi_cleared` (Filter) | `apply_filters( 'gfb_doi_cleared', false, $submission_id )` → `bool` | Ist die Übermittlung aus DOI-Sicht freigegeben? `true`, wenn das Formular der Einsendung **keinen** DOI-Modus hat (keine Bestätigungspflicht) **oder** die Adresse bestätigt ist (`confirmed`). `false` bei offener/abgelaufener Bestätigung, unbekannter ID und beim konservativen Randfall «DOI-Formular ohne gespeicherten Token». |
| `gfb_doi_status` (Filter) | `apply_filters( 'gfb_doi_status', '', $submission_id )` → `string` | Differenzierter Zustand: `''` (unbekannte ID), `none` (kein DOI), `pending` (offen, Ablauf künftig oder unbekannt), `confirmed`, `expired` – dieselbe Zustandslogik wie die DOI-Ampel im Backend (UTC-Zeitpfad der Engine). |
| `gfb_transfer_consent` (Filter) | `apply_filters( 'gfb_transfer_consent', false, $submission_id )` → `bool` | `true` **nur**, wenn am Formular ein Einwilligungs-Feld designiert ist (Block-Inspector «Datenweitergabe (Integrationen)», Attribut `consentField`, ein `gfb/field-checkbox`) **und** die Einsendung dort angekreuzt ist (`'1'`). Kein designiertes Feld → `false` (Erlaubnis nie implizit). Vertraulich gespeicherte Werte werden für diese Abfrage **nicht** entschlüsselt → `false`. |
| `gfb_doi_confirmed` (Action) | `do_action( 'gfb_doi_confirmed', $submission_id, $form_id, $post_id )` | Feuert **genau einmal** beim erfolgreichen Bestätigungs-Klick (atomarer Compare-and-swap; nach dem Statuswechsel, vor der Voll-Quittung). Der Trigger-Zeitpunkt für aufgeschobene Übermittlungen – ohne ihn müssten Dritt-Plugins pollen. |

## Beispiel

```php
// Beim Bestätigungs-Klick aufgeschoben übermitteln:
add_action( 'gfb_doi_confirmed', function ( $submission_id, $form_id, $post_id ) {
    if ( apply_filters( 'gfb_doi_cleared', false, $submission_id )
        && apply_filters( 'gfb_transfer_consent', false, $submission_id ) ) {
        // an CRM übermitteln …
    }
}, 10, 3 );
```

## Klarstellung

Bei Formularen **ohne** DOI-Modus ist `gfb_doi_cleared` sofort `true` – dort gibt es keine Bestätigungspflicht. Wer **ausschliesslich** nach einer bestätigten Adresse übermitteln will (auch bei Nicht-DOI-Formularen nie), prüft zusätzlich `'confirmed' === apply_filters( 'gfb_doi_status', '', $submission_id )`.

Die Einwilligung ist von der DOI-Frage getrennt: `gfb_doi_cleared` sagt «Adresse geklärt», `gfb_transfer_consent` sagt «Person hat der Weitergabe zugestimmt». Für eine Übermittlung an Dritte gehören beide Prüfungen zusammen (siehe Beispiel).
