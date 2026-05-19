<?php
/**
 * Serverseitige Renderer für alle gfb/field-* Blöcke (K3 Variante A).
 *
 * Vorteil gegenüber JS-`save()`-Output:
 *   - Wir kontrollieren das HTML zu 100 % in PHP. Keinerlei Vertrauen in
 *     Block-Markup-Comments oder Edit-Capabilities von Editoren.
 *   - Field-Output kann jederzeit zentral gehärtet werden.
 *
 * Migration:
 *   - Bestehende Posts haben evtl. JS-erzeugtes HTML im Block-Comment-Body.
 *     Da WP für dynamische Blöcke (mit render_callback) das gespeicherte
 *     innerHTML ignoriert, ist dieser Body irrelevant.
 *   - Editor-`save`-Funktionen werden parallel auf `return null` gesetzt
 *     (siehe assets/editor.js); für Bestand bleiben sie via deprecated[]
 *     valide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Field_Renderer {

	/**
	 * Mapping Block-Name => Renderer-Methode.
	 *
	 * @return array<string,string>
	 */
	private static function map() {
		return array(
			'gfb/field-text'     => 'render_text',
			'gfb/field-email'    => 'render_email',
			'gfb/field-textarea' => 'render_textarea',
			'gfb/field-select'   => 'render_select',
			'gfb/field-checkbox' => 'render_checkbox',
			'gfb/field-submit'   => 'render_submit',
			'gfb/field-number'   => 'render_number',
			'gfb/field-tel'      => 'render_tel',
			'gfb/field-url'      => 'render_url',
			'gfb/field-date'     => 'render_date',
			'gfb/field-time'     => 'render_time',
			'gfb/field-datetime' => 'render_datetime',
			'gfb/field-radio'    => 'render_radio',
			'gfb/field-hidden'   => 'render_hidden',
			'gfb/field-range'    => 'render_range',
			'gfb/field-file'     => 'render_file',
		);
	}

	/**
	 * Hauptdispatch — wird vom register_block_type-Callback aufgerufen.
	 *
	 * @param string              $block_name Block-Name (z. B. gfb/field-text).
	 * @param array<string,mixed> $attrs      Block-Attribute.
	 * @return string HTML.
	 */
	public static function render( $block_name, $attrs ) {
		$map = self::map();
		if ( ! isset( $map[ $block_name ] ) ) {
			return '';
		}
		$method = $map[ $block_name ];
		return self::$method( is_array( $attrs ) ? $attrs : array() );
	}

	/* ============================================================== *
	 * Helper
	 * ============================================================== */

	/**
	 * Ein paar Attribute liefern wir konsistent in allen Inputs aus.
	 *
	 * @param array<string,mixed> $attrs Attribute.
	 * @return array{name:string,label:string,placeholder:string,required:bool,sensitive:bool}
	 */
	private static function common( array $attrs ) {
		return array(
			'name'        => isset( $attrs['name'] ) ? sanitize_key( (string) $attrs['name'] ) : '',
			'label'       => isset( $attrs['label'] ) ? (string) $attrs['label'] : '',
			'placeholder' => isset( $attrs['placeholder'] ) ? (string) $attrs['placeholder'] : '',
			'required'    => ! empty( $attrs['required'] ),
			'sensitive'   => ! empty( $attrs['sensitive'] ),
		);
	}

	/**
	 * Wrapper-DIV mit Block-Klassen + optionaler "Vertraulich"-Pille.
	 *
	 * @param string $field_class Field-spezifische Klasse.
	 * @param string $inner       Inner-HTML (bereits escaped/sicher).
	 * @param array  $common      Output von self::common().
	 * @param string $label_html  Optional vorgerendertes Label-HTML.
	 * @return string
	 */
	private static function wrap( $field_class, $inner, array $common, $label_html = '' ) {
		$cls   = 'gfb-field ' . sanitize_html_class( $field_class );
		$attrs = '';
		if ( $common['sensitive'] ) {
			$attrs = ' data-gfb-sensitive="1"';
		}
		$pill = $common['sensitive']
			? '<span class="gfb-pill gfb-pill-sensitive" aria-label="' . esc_attr__( 'Wird verschlüsselt gespeichert', 'gutenberg-formbuilder' ) . '">'
				. esc_html__( 'verschlüsselt', 'gutenberg-formbuilder' )
				. '</span>'
			: '';
		return '<div class="' . esc_attr( $cls ) . '"' . $attrs . '>'
			. $label_html
			. $pill
			. $inner
			. '</div>';
	}

	/**
	 * Label-HTML, oder leer wenn label leer/whitespace.
	 *
	 * @param string $name  Feldname (id).
	 * @param string $label Labeltext.
	 * @return string
	 */
	private static function label( $name, $label ) {
		$txt = trim( (string) $label );
		if ( '' === $txt || '' === $name ) {
			return '';
		}
		return '<label for="' . esc_attr( $name ) . '">'
			. esc_html( $txt )
			. '</label>';
	}

	/**
	 * Nur für date/time/datetime-local: gültiger HTML-Default oder leer.
	 *
	 * @param string $type date|time|datetime-local
	 * @param mixed  $raw  Block-Attribut defaultValue.
	 * @return string Escapbare Roh-Zeichenkette oder ''.
	 */
	private static function sanitize_html_datetime_default( $type, $raw ) {
		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return '';
		}
		if ( 'date' === $type ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ? $s : '';
		}
		if ( 'time' === $type ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s ) ? $s : '';
		}
		if ( 'datetime-local' === $type ) {
			$s = preg_replace( '/\s+/', 'T', $s, 1 );
			return preg_match( '/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s ) ? $s : '';
		}
		return '';
	}

	/**
	 * HTML-pattern (Regex) für date/time/datetime-local aus Einstellungen → Allgemein.
	 *
	 * @param string $type date|time|datetime-local
	 * @return string Regex ohne Delimiter oder leer.
	 */
	private static function site_html_pattern_for_input_type( $type ) {
		if ( 'date' === $type ) {
			return self::php_date_format_to_html_pattern( (string) get_option( 'date_format', 'Y-m-d' ) );
		}
		if ( 'time' === $type ) {
			return self::php_date_format_to_html_pattern( (string) get_option( 'time_format', 'H:i' ) );
		}
		if ( 'datetime-local' === $type ) {
			$date_f = (string) get_option( 'date_format', 'Y-m-d' );
			$time_f = (string) get_option( 'time_format', 'H:i' );
			return self::php_date_format_to_html_pattern( $date_f . ' ' . $time_f );
		}
		return '';
	}

	/**
	 * Placeholder-Maske passend zum pattern (z. B. d.m.Y → dd.mm.yyyy).
	 *
	 * @param string $type date|time|datetime-local
	 * @return string
	 */
	private static function site_html_format_placeholder_for_input_type( $type ) {
		if ( 'date' === $type ) {
			return self::php_date_format_to_placeholder_mask( (string) get_option( 'date_format', 'Y-m-d' ) );
		}
		if ( 'time' === $type ) {
			return self::php_date_format_to_placeholder_mask( (string) get_option( 'time_format', 'H:i' ) );
		}
		if ( 'datetime-local' === $type ) {
			$date_f = (string) get_option( 'date_format', 'Y-m-d' );
			$time_f = (string) get_option( 'time_format', 'H:i' );
			return self::php_date_format_to_placeholder_mask( $date_f . ' ' . $time_f );
		}
		return '';
	}

	/**
	 * PHP-Datumsformat (date_format/time_format) in HTML-pattern-Regex.
	 *
	 * @param string $php_format Formatzeichenkette aus get_option().
	 * @return string
	 */
	private static function php_date_format_to_html_pattern( $php_format ) {
		$map = array(
			'd' => '\\d{2}',
			'j' => '\\d{1,2}',
			'D' => '[A-Za-z]{3}',
			'l' => '[A-Za-z]+',
			'S' => '(st|nd|rd|th)',
			'F' => '[A-Za-z]+',
			'M' => '[A-Za-z]{3}',
			'm' => '\\d{2}',
			'n' => '\\d{1,2}',
			'Y' => '\\d{4}',
			'y' => '\\d{2}',
			'a' => '(am|pm)',
			'A' => '(AM|PM)',
			'g' => '\\d{1,2}',
			'G' => '\\d{1,2}',
			'h' => '\\d{2}',
			'H' => '\\d{2}',
			'i' => '\\d{2}',
			's' => '\\d{2}',
			'e' => '[A-Za-z_\\/]+',
			'O' => '[+-]\\d{4}',
			'P' => '[+-]\\d{2}:\\d{2}',
			'T' => '[A-Za-z]{1,5}',
		);
		return self::php_date_format_map_tokens( $php_format, $map, true );
	}

	/**
	 * PHP-Datumsformat in Placeholder-Maske (d→dd, m→mm, Y→yyyy, …).
	 *
	 * @param string $php_format Formatzeichenkette aus get_option().
	 * @return string
	 */
	private static function php_date_format_to_placeholder_mask( $php_format ) {
		$map = array(
			'd' => 'dd',
			'j' => 'd',
			'D' => 'ddd',
			'l' => 'dddd',
			'S' => '',
			'F' => 'MMMM',
			'M' => 'MMM',
			'm' => 'mm',
			'n' => 'm',
			'Y' => 'yyyy',
			'y' => 'yy',
			'a' => 'am',
			'A' => 'AM',
			'g' => 'h',
			'G' => 'H',
			'h' => 'hh',
			'H' => 'HH',
			'i' => 'mm',
			's' => 'ss',
			'e' => 'TZ',
			'O' => '+0000',
			'P' => '+00:00',
			'T' => 'TZ',
		);
		return self::php_date_format_map_tokens( $php_format, $map, false );
	}

	/**
	 * @param string               $php_format PHP-Formatstring.
	 * @param array<string,string> $map        Zeichen → Ersatz.
	 * @param bool                 $quote_rest Nicht gemappte Zeichen für Regex escapen.
	 * @return string
	 */
	private static function php_date_format_map_tokens( $php_format, array $map, $quote_rest ) {
		$out = '';
		$len = strlen( $php_format );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $php_format[ $i ];
			if ( '\\' === $ch && $i + 1 < $len ) {
				$lit = $php_format[ $i + 1 ];
				$out .= $quote_rest ? preg_quote( $lit, '/' ) : $lit;
				++$i;
				continue;
			}
			if ( isset( $map[ $ch ] ) ) {
				$out .= $map[ $ch ];
				continue;
			}
			$out .= $quote_rest ? preg_quote( $ch, '/' ) : $ch;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $attrs Block-Attribute.
	 * @param string              $type  HTML-input-type (date, email, …).
	 * @param string              $field_class CSS-Klasse für das Wrapper-DIV.
	 * @return string HTML.
	 */
	private static function render_text_like( array $attrs, $type, $field_class ) {
		$c     = self::common( $attrs );
		if ( '' === $c['name'] ) {
			return '';
		}
		$attr  = ' type="' . esc_attr( $type ) . '"';
		$attr .= ' name="' . esc_attr( $c['name'] ) . '"';
		$attr .= ' id="' . esc_attr( $c['name'] ) . '"';
		if ( '' !== $c['placeholder'] ) {
			$attr .= ' placeholder="' . esc_attr( $c['placeholder'] ) . '"';
		}
		if ( in_array( $type, array( 'date', 'time', 'datetime-local' ), true ) ) {
			$dv = self::sanitize_html_datetime_default( $type, $attrs['defaultValue'] ?? '' );
			$attr .= ' data-gfb-has-default="' . ( '' !== $dv ? '1' : '0' ) . '"';
			if ( '' !== $dv ) {
				$attr .= ' value="' . esc_attr( $dv ) . '"';
			}
			$pattern = self::site_html_pattern_for_input_type( $type );
			if ( '' !== $pattern ) {
				$attr .= ' pattern="' . esc_attr( $pattern ) . '"';
			}
			if ( '' === $c['placeholder'] ) {
				$format_ph = self::site_html_format_placeholder_for_input_type( $type );
				if ( '' !== $format_ph ) {
					$attr .= ' placeholder="' . esc_attr( $format_ph ) . '"';
				}
			}
		}
		if ( 'date' === $type ) {
			foreach ( array( 'min', 'max' ) as $dk ) {
				if ( empty( $attrs[ $dk ] ) ) {
					continue;
				}
				$mv = sanitize_text_field( (string) $attrs[ $dk ] );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $mv ) ) {
					$attr .= ' ' . esc_attr( $dk ) . '="' . esc_attr( $mv ) . '"';
				}
			}
		}
		if ( $c['required'] ) {
			$attr .= ' required';
		}
		if ( ! empty( $attrs['autocomplete'] ) ) {
			$attr .= ' autocomplete="' . esc_attr( sanitize_key( (string) $attrs['autocomplete'] ) ) . '"';
		}
		if ( ! empty( $attrs['minlength'] ) ) {
			$attr .= ' minlength="' . (int) $attrs['minlength'] . '"';
		}
		if ( ! empty( $attrs['maxlength'] ) ) {
			$attr .= ' maxlength="' . (int) $attrs['maxlength'] . '"';
		}
		$inner = '<input' . $attr . ' />';
		return self::wrap( $field_class, $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	/* ============================================================== *
	 * Konkrete Renderer
	 * ============================================================== */

	public static function render_text( $a )     { return self::render_text_like( $a, 'text',     'gfb-field-text' ); }
	public static function render_email( $a )    { return self::render_text_like( $a, 'email',    'gfb-field-email' ); }
	public static function render_tel( $a )      { return self::render_text_like( $a, 'tel',      'gfb-field-tel' ); }
	public static function render_url( $a )      { return self::render_text_like( $a, 'url',      'gfb-field-url' ); }
	public static function render_date( $a )     { return self::render_text_like( $a, 'date',     'gfb-field-date' ); }
	public static function render_time( $a )     { return self::render_text_like( $a, 'time',     'gfb-field-time' ); }
	public static function render_datetime( $a ) { return self::render_text_like( $a, 'datetime-local', 'gfb-field-datetime' ); }

	public static function render_textarea( $a ) {
		$c = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$rows = isset( $a['rows'] ) ? (int) $a['rows'] : 4;
		$attr  = ' name="' . esc_attr( $c['name'] ) . '"';
		$attr .= ' id="' . esc_attr( $c['name'] ) . '"';
		$attr .= ' rows="' . max( 1, $rows ) . '"';
		if ( '' !== $c['placeholder'] ) {
			$attr .= ' placeholder="' . esc_attr( $c['placeholder'] ) . '"';
		}
		if ( $c['required'] ) {
			$attr .= ' required';
		}
		if ( ! empty( $a['maxlength'] ) ) {
			$attr .= ' maxlength="' . (int) $a['maxlength'] . '"';
		}
		$inner = '<textarea' . $attr . '></textarea>';
		return self::wrap( 'gfb-field-textarea', $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	public static function render_select( $a ) {
		$c = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$opts  = self::option_lines( isset( $a['options'] ) ? (string) $a['options'] : '' );
		$attr  = ' name="' . esc_attr( $c['name'] ) . '" id="' . esc_attr( $c['name'] ) . '"';
		if ( $c['required'] ) {
			$attr .= ' required';
		}
		$inner = '<select' . $attr . '>';
		// Optional Placeholder als erste Option.
		if ( '' !== $c['placeholder'] ) {
			$inner .= '<option value="" disabled selected>' . esc_html( $c['placeholder'] ) . '</option>';
		}
		foreach ( $opts as $opt ) {
			$inner .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
		}
		$inner .= '</select>';
		return self::wrap( 'gfb-field-select', $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	public static function render_radio( $a ) {
		$c = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$opts   = self::option_lines( isset( $a['options'] ) ? (string) $a['options'] : '' );
		$layout = isset( $a['optionsLayout'] ) ? sanitize_key( (string) $a['optionsLayout'] ) : 'column';
		$opts_class = 'gfb-radio-options' . ( 'row' === $layout ? ' gfb-radio-options--row' : '' );
		$inner        = '<div class="' . esc_attr( $opts_class ) . '">';
		$idx          = 0;
		foreach ( $opts as $opt ) {
			$id  = $c['name'] . '_' . $idx;
			$req = ( $c['required'] && 0 === $idx ) ? ' required' : '';
			$inner .= '<div class="gfb-radio-row">'
				. '<input type="radio" name="' . esc_attr( $c['name'] ) . '" value="' . esc_attr( $opt ) . '" id="' . esc_attr( $id ) . '"' . $req . ' />'
				. ' <label for="' . esc_attr( $id ) . '">' . esc_html( $opt ) . '</label>'
				. '</div>';
			++$idx;
		}
		$inner .= '</div>';

		$fs_attrs = '';
		if ( $c['sensitive'] ) {
			$fs_attrs = ' data-gfb-sensitive="1"';
		}
		$pill = $c['sensitive']
			? '<span class="gfb-pill gfb-pill-sensitive" aria-label="' . esc_attr__( 'Wird verschlüsselt gespeichert', 'gutenberg-formbuilder' ) . '">'
				. esc_html__( 'verschlüsselt', 'gutenberg-formbuilder' )
				. '</span>'
			: '';
		$legend = '' !== trim( $c['label'] )
			? '<legend>' . esc_html( trim( $c['label'] ) ) . '</legend>'
			: '';

		return '<fieldset class="gfb-field gfb-field-radio"' . $fs_attrs . '>'
			. $legend
			. $pill
			. $inner
			. '</fieldset>';
	}

	public static function render_checkbox( $a ) {
		$c = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$inner = '<label for="' . esc_attr( $c['name'] ) . '">'
			. '<input type="checkbox" name="' . esc_attr( $c['name'] ) . '" id="' . esc_attr( $c['name'] ) . '" value="1"' . ( $c['required'] ? ' required' : '' ) . ' />'
			. ' ' . esc_html( $c['label'] )
			. '</label>';
		return self::wrap( 'gfb-field-checkbox', $inner, $c, '' );
	}

	public static function render_number( $a ) {
		$c     = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$attr  = ' type="number"';
		$attr .= ' name="' . esc_attr( $c['name'] ) . '" id="' . esc_attr( $c['name'] ) . '"';
		foreach ( array( 'min', 'max', 'step' ) as $k ) {
			if ( isset( $a[ $k ] ) && '' !== (string) $a[ $k ] ) {
				$attr .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $a[ $k ] ) . '"';
			}
		}
		if ( '' !== $c['placeholder'] ) {
			$attr .= ' placeholder="' . esc_attr( $c['placeholder'] ) . '"';
		}
		if ( $c['required'] ) {
			$attr .= ' required';
		}
		$inner = '<input' . $attr . ' />';
		return self::wrap( 'gfb-field-number', $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	public static function render_range( $a ) {
		$c       = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$min     = isset( $a['min'] ) ? (string) $a['min'] : '0';
		$max     = isset( $a['max'] ) ? (string) $a['max'] : '100';
		$step    = isset( $a['step'] ) ? (string) $a['step'] : '1';
		$default = isset( $a['defaultValue'] ) ? (string) $a['defaultValue'] : $min;
		$attr    = ' type="range"';
		$attr   .= ' name="' . esc_attr( $c['name'] ) . '" id="' . esc_attr( $c['name'] ) . '"';
		$attr   .= ' min="' . esc_attr( $min ) . '"';
		$attr   .= ' max="' . esc_attr( $max ) . '"';
		$attr   .= ' step="' . esc_attr( $step ) . '"';
		$attr   .= ' value="' . esc_attr( $default ) . '"';
		$inner   = '<div class="gfb-range-row">'
			. '<input' . $attr . ' />'
			. '<output class="gfb-range-value" for="' . esc_attr( $c['name'] ) . '">' . esc_html( $default ) . '</output>'
			. '</div>';
		return self::wrap( 'gfb-field-range', $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	public static function render_hidden( $a ) {
		$c     = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		// Hidden braucht kein Label im Frontend. Wert ist Attribut hiddenValue.
		$value = isset( $a['hiddenValue'] ) ? (string) $a['hiddenValue'] : '';
		$inner = '<input type="hidden" name="' . esc_attr( $c['name'] ) . '" value="' . esc_attr( $value ) . '" />';
		return self::wrap( 'gfb-field-hidden', $inner, $c, '' );
	}

	public static function render_file( $a ) {
		$c     = self::common( $a );
		if ( '' === $c['name'] ) {
			return '';
		}
		$accept   = isset( $a['accept'] ) ? (string) $a['accept'] : '';
		$max_mb   = isset( $a['maxSizeMb'] ) ? max( 1, (int) $a['maxSizeMb'] ) : 8;
		$attr     = ' type="file" name="' . esc_attr( $c['name'] ) . '" id="' . esc_attr( $c['name'] ) . '"';
		if ( '' !== $accept ) {
			$attr .= ' accept="' . esc_attr( $accept ) . '"';
		}
		if ( $c['required'] ) {
			$attr .= ' required';
		}
		$hint   = sprintf( esc_html__( 'Datei wird verschlüsselt gespeichert (max. %d MB).', 'gutenberg-formbuilder' ), $max_mb );
		$inner  = '<input' . $attr . ' />'
			. '<small class="gfb-help">' . $hint . '</small>';
		// Bei Files setzen wir das Sensitive-Pill IMMER, weil der Storage immer verschlüsselt.
		$c['sensitive'] = true;
		return self::wrap( 'gfb-field-file', $inner, $c, self::label( $c['name'], $c['label'] ) );
	}

	public static function render_submit( $a ) {
		$text = isset( $a['label'] ) ? trim( (string) $a['label'] ) : '';
		if ( '' === $text ) {
			$text = __( 'Formular absenden', 'gutenberg-formbuilder' );
		}
		return '<div class="gfb-field gfb-field-submit"><div class="wp-block-button is-style-default"><button type="submit" class="wp-block-button__link wp-element-button">'
			. esc_html( $text )
			. '</button></div></div>';
	}

	/**
	 * @param string $raw Mehrzeilige Optionen.
	 * @return array<int,string>
	 */
	private static function option_lines( $raw ) {
		$out = array();
		foreach ( explode( "\n", (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
