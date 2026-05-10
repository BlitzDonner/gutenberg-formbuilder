# Farben, Transparenz und Verläufe

Kurzdoku zum Verhalten von **Gutenberg Formbuilder** (Ergänzung zum [README](../README.md)).

## Editor (`assets/editor.js`)

- **Technische Feldnamen (`name`):** werden automatisch vergeben (nicht im Inspector editierbar); siehe README → „Einzigartige technische Feldnamen“ (`syncAutoFieldName`).
- **`PanelColorGradientSettings`:** Wenn die Block-API die Komponente bereitstellt, nutzen Formular- und Feld-Inspector dieselbe Farb-/Verlauf-Steuerung wie der Kern-Editor. Es werden `disableCustomColors: false` und `disableCustomGradients: false` gesetzt, damit eigene Verläufe auch ohne Theme-Gradient-Palette möglich sind. Die Panels **„Farben (Feld überschreiben)“** / **„Button & Fokus …“** sind in ein `PanelBody` mit `initialOpen: false` gehüllt, damit sie standardmässig zugeklappt sind (ToolsPanel ignoriert `initialOpen` oft).
- **Ein Attribut pro Farbzeile:** WordPress ruft bei Farbwahl `onColorChange(neu)` und direkt danach `onGradientChange()` **ohne Argument** auf (analog beim Verlauf). `mapColorSettingsToGradientSettings` unterdrückt diese Hilfsaufrufe per Flags; „Zurücksetzen“ im ToolsPanel ruft weiterhin leer mit null Argumenten auf und leert den Wert.

### Feld-Labels (Canvas-Vorschau)

- **`gfbTrimmedFieldLabel(label)`:** Trimmt den gespeicherten Wert; leerer String bedeutet „kein sichtbares Label“.
- **`gfbEditorLabelIfAny(tag, props, labelAttr)`:** Rendert ein Element (z. B. `<label>`) nur, wenn nach dem Trim Text übrig bleibt — ersetzt frühere Platzhalter-Strings („Textfeld“, „E-Mail“, …) in den `edit`-Funktionen der Feldblöcke.
- **Checkbox:** `<label>` umschliesst weiterhin die Checkbox; der Beschriftungstext dahinter entfällt bei leerem `label`.
- **Radio (`gfb/field-radio`):** Im Editor wird ein `<legend>` nur gerendert, wenn das Gruppen-Label (`label`) nach Trim nicht leer ist.
- **Absenden (`gfb/field-submit`):** Unverändert mit Fallback-Text in der Vorschau, wenn der Button-Text leer ist (kein leerer Button).
- Die **`save`‑Ausgabe** (Markup fürs Frontend) wird dadurch nicht geändert; die Anpassung betrifft nur die Editor-Vorschau im Block-Canvas.

## PHP (`includes/class-gfb-plugin.php`)

- **`sanitize_gfb_color()`:** Validiert gespeicherte Werte für Inline-`style` und verwirft unsichere Inhalte (`url()`, `expression()`, …). Unterstützt u. a. Hex (3/4/6/8), `transparent` / `currentColor`, `var(--…)` mit optionalem einfachem Fallback, `rgb`/`hsl` (klassisch und mit `/`-Alpha), CSS-Verläufe (`linear-gradient`, …), `color-mix`, `oklch`, …
- **`gfb_sanitized_attr_is_css_gradient()`:** Wird für den **Formularbereich** genutzt: Ist der gespeicherte Shell-Wert ein Verlauf, erhält der Wrapper die Klassen `gfb-form-shell-gradient--light` bzw. `gfb-form-shell-gradient--dark` (siehe CSS).

## CSS (`assets/form.css`, `assets/gfb-editor.css`)

- Die Karten-Hintergrund-Logik setzt standardmässig einen **vertikalen** Verlauf aus `--gfb-shell-top` / `--gfb-shell-bottom`, die aus `--gfb-*-form-shell` kommen. Ein **Verlauf** in `--gfb-*-form-shell` darf nicht als Farbstopp in einem äusseren `linear-gradient` stehen (ungültig → wirkt weiss). Mit den Shell-Gradient-Klassen wird stattdessen `background: var(--gfb-light-form-shell)` bzw. `var(--gfb-dark-form-shell)` gesetzt.
- **`color:` / `border-color:`:** Verläufe gelten dort nicht; nutzbar sind Verläufe v. a. bei `background` (z. B. Feldhintergrund, Button-Hintergrund, Formularbereich).
