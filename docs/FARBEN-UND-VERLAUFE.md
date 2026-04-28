# Farben, Transparenz und Verläufe

Kurzdoku zum Verhalten von **Gutenberg Formbuilder** (Ergänzung zum [README](../README.md)).

## Editor (`assets/editor.js`)

- **`PanelColorGradientSettings`:** Wenn die Block-API die Komponente bereitstellt, nutzen Formular- und Feld-Inspector dieselbe Farb-/Verlauf-Steuerung wie der Kern-Editor. Es werden `disableCustomColors: false` und `disableCustomGradients: false` gesetzt, damit eigene Verläufe auch ohne Theme-Gradient-Palette möglich sind.
- **Ein Attribut pro Farbzeile:** WordPress ruft bei Farbwahl `onColorChange(neu)` und direkt danach `onGradientChange()` **ohne Argument** auf (analog beim Verlauf). `mapColorSettingsToGradientSettings` unterdrückt diese Hilfsaufrufe per Flags; „Zurücksetzen“ im ToolsPanel ruft weiterhin leer mit null Argumenten auf und leert den Wert.

## PHP (`includes/class-gfb-plugin.php`)

- **`sanitize_gfb_color()`:** Validiert gespeicherte Werte für Inline-`style` und verwirft unsichere Inhalte (`url()`, `expression()`, …). Unterstützt u. a. Hex (3/4/6/8), `transparent` / `currentColor`, `var(--…)` mit optionalem einfachem Fallback, `rgb`/`hsl` (klassisch und mit `/`-Alpha), CSS-Verläufe (`linear-gradient`, …), `color-mix`, `oklch`, …
- **`gfb_sanitized_attr_is_css_gradient()`:** Wird für den **Formularbereich** genutzt: Ist der gespeicherte Shell-Wert ein Verlauf, erhält der Wrapper die Klassen `gfb-form-shell-gradient--light` bzw. `gfb-form-shell-gradient--dark` (siehe CSS).

## CSS (`assets/form.css`, `assets/gfb-editor.css`)

- Die Karten-Hintergrund-Logik setzt standardmäßig einen **vertikalen** Verlauf aus `--gfb-shell-top` / `--gfb-shell-bottom`, die aus `--gfb-*-form-shell` kommen. Ein **Verlauf** in `--gfb-*-form-shell` darf nicht als Farbstopp in einem äußeren `linear-gradient` stehen (ungültig → wirkt weiß). Mit den Shell-Gradient-Klassen wird stattdessen `background: var(--gfb-light-form-shell)` bzw. `var(--gfb-dark-form-shell)` gesetzt.
- **`color:` / `border-color:`:** Verläufe gelten dort nicht; nutzbar sind Verläufe v. a. bei `background` (z. B. Feldhintergrund, Button-Hintergrund, Formularbereich).
