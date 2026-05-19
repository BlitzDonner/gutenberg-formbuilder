<?php
/**
 * Core plugin bootstrap and block registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Plugin {

	/** @var string Option: Safari/WebKit Datums-Fallback (Textfelder statt native Picker). */
	const OPTION_WEBKIT_DATETIME_FALLBACK = 'gfb_webkit_datetime_fallback';

	/**
	 * Safari/WebKit: date/time/datetime-local durch Text + pattern ersetzen?
	 * Standard: an (historisches Verhalten). In Sicherheit → Einstellungen abschaltbar.
	 *
	 * @return bool
	 */
	public static function is_webkit_datetime_fallback_enabled() {
		$raw     = get_option( self::OPTION_WEBKIT_DATETIME_FALLBACK, '1' );
		$enabled = ( '1' === (string) $raw || true === $raw );
		return (bool) apply_filters( 'gfb_webkit_datetime_fallback', $enabled );
	}

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'block_categories_all', array( __CLASS__, 'register_block_category' ), 999, 2 );
		add_action( 'init', array( 'GFB_Submit_Handler', 'maybe_upgrade_submissions_db' ), 0 );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_for_redirect_query' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_post_gfb_submit', array( 'GFB_Submit_Handler', 'handle' ) );
		add_action( 'admin_post_nopriv_gfb_submit', array( 'GFB_Submit_Handler', 'handle' ) );
		GFB_Admin_Submissions::boot();
	}

	/**
	 * Zwei Block-Kategorien: „Formular“ (Container) und „Formularfelder“ (alle Feld-Blöcke).
	 *
	 * @param array<int,array<string,mixed>> $categories Bestehende Kategorien.
	 * @param mixed                            $context Editor-Kontext.
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_block_category( $categories, $context = null ) {
		$our_slugs = array( 'gfb', 'gfb-form', 'gfb-fields' );
		$out       = array();
		foreach ( $categories as $cat ) {
			if ( isset( $cat['slug'] ) && in_array( $cat['slug'], $our_slugs, true ) ) {
				continue;
			}
			$out[] = $cat;
		}
		$out[] = array(
			'slug'  => 'gfb-form',
			'title' => __( 'Formular', 'gutenberg-formbuilder' ),
			'icon'  => null,
		);
		$out[] = array(
			'slug'  => 'gfb-fields',
			'title' => __( 'Formularfelder', 'gutenberg-formbuilder' ),
			'icon'  => null,
		);

		return $out;
	}

	/**
	 * Register scripts/styles.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_script(
			'gfb-editor',
			GFB_PLUGIN_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-core-data' ),
			GFB_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'gfb-editor',
			'gfbEditorAssets',
			array(
				'editorCanvasFormStylesUrl' => GFB_PLUGIN_URL . 'assets/form.css',
				'editorChromeStylesUrl'     => GFB_PLUGIN_URL . 'assets/gfb-editor.css',
				'version'                   => GFB_PLUGIN_VERSION,
				'adminEmail'                => sanitize_email( (string) get_option( 'admin_email' ) ),
			)
		);

		wp_register_script(
			'gfb-frontend',
			GFB_PLUGIN_URL . 'assets/frontend.js',
			array(),
			GFB_PLUGIN_VERSION,
			true
		);

		// Strings statt bool: wp_localize_script castet Werte zu Strings (false → "").
		wp_localize_script(
			'gfb-frontend',
			'gfbFrontendConfig',
			array(
				'webkitDateTimeFallback' => self::is_webkit_datetime_fallback_enabled() ? '1' : '0',
			)
		);

		wp_register_style(
			'gfb-form',
			GFB_PLUGIN_URL . 'assets/form.css',
			array(),
			GFB_PLUGIN_VERSION
		);
	}

	/**
	 * Lädt gfb-frontend auf Folgeseiten ohne Formularblock (IndexedDB-Entwurf per URL löschen).
	 *
	 * @return void
	 */
	public static function enqueue_frontend_for_redirect_query() {
		if ( is_admin() ) {
			return;
		}
		if ( empty( $_GET['gfb_status'] ) || empty( $_GET['gfb_draft_key'] ) ) {
			return;
		}
		if ( 'success' !== sanitize_key( wp_unslash( $_GET['gfb_status'] ) ) ) {
			return;
		}
		wp_enqueue_script( 'gfb-frontend' );
	}

	/**
	 * Canvas-Styles: `form.css` + schmales `gfb-editor.css` per editor.js in den Block-Iframe.
	 * `gfb-editor` lädt über block.json → editorScript.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
	}

	/**
	 * Register block metadata.
	 *
	 * @return void
	 */
	public static function register_blocks() {
		register_block_type(
			GFB_PLUGIN_DIR . 'blocks/form',
			array(
				'render_callback' => array( __CLASS__, 'render_form_block' ),
			)
		);

		register_block_type(
			GFB_PLUGIN_DIR . 'blocks/form-success',
			array(
				'render_callback' => array( __CLASS__, 'render_form_success_block' ),
			)
		);

		register_block_type(
			GFB_PLUGIN_DIR . 'blocks/token',
			array(
				'render_callback' => static function () {
					return '';
				},
			)
		);

		// Alle Field-Blöcke werden serverseitig gerendert (K3 Variante A).
		// HTML-Output kommt aus GFB_Field_Renderer; das im Editor `save()`
		// erzeugte Markup wird nur für deprecated-Validierung ungültiger
		// Altbestand benutzt (Backwards-Compat).
		$field_blocks = array(
			'gfb/field-text', 'gfb/field-email', 'gfb/field-textarea',
			'gfb/field-select', 'gfb/field-checkbox', 'gfb/field-submit',
			'gfb/field-number', 'gfb/field-tel', 'gfb/field-url',
			'gfb/field-date', 'gfb/field-time', 'gfb/field-datetime',
			'gfb/field-radio', 'gfb/field-hidden', 'gfb/field-range',
			'gfb/field-file',
		);
		foreach ( $field_blocks as $name ) {
			$slug = substr( $name, strlen( 'gfb/' ) );
			register_block_type(
				GFB_PLUGIN_DIR . 'blocks/' . $slug,
				array(
					'render_callback' => static function ( $attrs ) use ( $name ) {
						return GFB_Field_Renderer::render( $name, is_array( $attrs ) ? $attrs : array() );
					},
				)
			);
		}
	}

	/**
	 * Erscheinungsmodus: Theme (Standard), Automatisch, Hell, Dunkel.
	 *
	 * @param mixed $value Raw attribute.
	 * @return string theme|auto|light|dark
	 */
	private static function sanitize_appearance_mode( $value ) {
		$v = is_string( $value ) ? sanitize_key( $value ) : 'theme';
		if ( in_array( $v, array( 'theme', 'auto', 'light', 'dark' ), true ) ) {
			return $v;
		}
		return 'theme';
	}

	/**
	 * Klammern in einem CSS-Wert prüfen (eine Ebene reicht für typische Farb-/Verlaufsfunktionen).
	 *
	 * @param string $s Wert.
	 * @return bool
	 */
	private static function gfb_css_parentheses_balanced( $s ) {
		$d   = 0;
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '(' === $c ) {
				++$d;
				if ( $d > 80 ) {
					return false;
				}
			} elseif ( ')' === $c ) {
				--$d;
				if ( $d < 0 ) {
					return false;
				}
			}
		}
		return 0 === $d;
	}

	/**
	 * Gefährliche CSS-Fragmente ausschliessen (kein url(), kein expression etc.).
	 * M3: zusätzlich Newlines und Semikolons, um Style-Property-Injection zu unterbinden.
	 *
	 * @param string $s Wert.
	 * @return bool True wenn unsicher.
	 */
	private static function gfb_css_value_has_blocked_tokens( $s ) {
		if ( preg_match( '/\burl\s*\(|expression\s*\(|@import|javascript\s*:/i', $s ) ) {
			return true;
		}
		foreach ( array( '<', '>', '`', '\\', ';', "\r", "\n", "\t", "\0" ) as $bad ) {
			if ( false !== strpos( $s, $bad ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Erlaubt eine begrenzte Länge, ausgewogene Klammern und keine blockierten Token.
	 * M3: Default-Limit auf 512 reduziert (vorher 4096).
	 *
	 * @param string $value Wert.
	 * @param int    $max_len Maximale Zeichenlänge.
	 * @return string Bereinigter Wert oder leer.
	 */
	private static function gfb_sanitize_functional_css_color( $value, $max_len = 512 ) {
		if ( strlen( $value ) > $max_len ) {
			return '';
		}
		if ( self::gfb_css_value_has_blocked_tokens( $value ) ) {
			return '';
		}
		if ( ! self::gfb_css_parentheses_balanced( $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Farbwert / Verlauf für style="--var: …" (Block-Editor: Hex, Alpha, Verläufe, moderne Farbräume).
	 *
	 * @param mixed $value Raw attribute.
	 * @return string Sicherer CSS-Wert oder leer.
	 */
	private static function sanitize_gfb_color( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$lower = strtolower( $value );
		if ( 'transparent' === $lower ) {
			return 'transparent';
		}
		if ( 'currentcolor' === $lower ) {
			return 'currentColor';
		}
		$hex = sanitize_hex_color( $value );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}
		// 8-stelliges Hex (#RRGGBBAA), von Color-Pickern üblich — sanitize_hex_color lehnt das ab.
		if ( preg_match( '/^#([A-Fa-f0-9]{8})$/', $value ) ) {
			return $value;
		}
		// 4-stelliges Hex (#RGBA)
		if ( preg_match( '/^#([A-Fa-f0-9]{4})$/', $value ) ) {
			return $value;
		}
		// var(--name) oder var(--name, Fallback) — Fallback rekursiv prüfen.
		if ( preg_match( '/^var\s*\(\s*(--[a-zA-Z0-9\-]+)\s*\)$/i', $value, $vm ) ) {
			return 'var(' . $vm[1] . ')';
		}
		if ( preg_match( '/^var\s*\(\s*(--[a-zA-Z0-9\-]+)\s*,\s*(.+)\)$/is', $value, $vm2 ) ) {
			$inner = self::sanitize_gfb_color( trim( $vm2[2] ) );
			if ( '' === $inner ) {
				return '';
			}
			return 'var(' . $vm2[1] . ', ' . $inner . ')';
		}
		// rgb() / rgba() — klassisch mit Komma oder modern mit Leerzeichen und / Alpha.
		if ( preg_match( '/^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^rgba?\(\s*[\d.]+%?(?:\s+[\d.]+%?){2}(?:\s*\/\s*[\d.]+%?)?\s*\)$/i', $value ) ) {
			return $value;
		}
		// hsl() / hsla() — klassisch oder mit / Alpha.
		if ( preg_match( '/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(,\s*[\d.]+\s*)?\)$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^hsla?\(\s*[\d.]+\s+[\d.]+%\s+[\d.]+%(?:\s*\/\s*[\d.]+%?)?\s*\)$/i', $value ) ) {
			return $value;
		}
		// Verläufe und Farbfunktionen mit geklammertem Inhalt (kein url()).
		$prefixes = array(
			'linear-gradient',
			'radial-gradient',
			'conic-gradient',
			'repeating-linear-gradient',
			'repeating-radial-gradient',
			'repeating-conic-gradient',
			'color-mix',
			'oklch',
			'oklab',
			'hwb',
			'lch',
			'lab',
		);
		foreach ( $prefixes as $pfx ) {
			$plen = strlen( $pfx ) + 1;
			if ( strlen( $value ) > $plen && 0 === strcasecmp( substr( $value, 0, $plen ), $pfx . '(' ) ) {
				$ok = self::gfb_sanitize_functional_css_color( $value );
				return '' !== $ok ? $ok : '';
			}
		}
		return '';
	}

	/**
	 * Prüft, ob ein Attributwert nach Sanitization ein CSS-Bildverlauf ist (Shell-Hintergrund).
	 *
	 * @param mixed $raw Roher Attributwert.
	 * @return bool
	 */
	private static function gfb_sanitized_attr_is_css_gradient( $raw ) {
		$s = self::sanitize_gfb_color( is_string( $raw ) ? $raw : '' );
		if ( '' === $s ) {
			return false;
		}
		return (bool) preg_match(
			'/^(?:linear|radial|conic|repeating-linear|repeating-radial|repeating-conic)-gradient\s*\(/i',
			$s
		);
	}

	/**
	 * CSS-Variablen für das Formular-Wrapper-Element (Theme-Farben).
	 *
	 * @param array<string,mixed> $attributes Block-Attribute.
	 * @return string Semikolon-getrennte Deklarationen ohne abschliessendes Semikolon am Ende (für style=").
	 */
	private static function build_form_inline_color_style( $attributes ) {
		$light_map = array(
			'colorLabel'       => '--gfb-light-label',
			'colorText'        => '--gfb-light-text',
			'colorPlaceholder' => '--gfb-light-placeholder',
			'colorFieldBg'     => '--gfb-light-bg',
			'colorBorder'      => '--gfb-light-border',
			'colorFocus'       => '--gfb-light-border-focus',
			'colorButtonBg'    => '--gfb-light-submit-bg',
			'colorButtonText'  => '--gfb-light-submit-text',
			'colorFormShell'   => '--gfb-light-form-shell',
		);
		$dark_map  = array(
			'darkColorLabel'       => '--gfb-dark-label',
			'darkColorText'        => '--gfb-dark-text',
			'darkColorPlaceholder' => '--gfb-dark-placeholder',
			'darkColorFieldBg'     => '--gfb-dark-bg',
			'darkColorBorder'      => '--gfb-dark-border',
			'darkColorFocus'       => '--gfb-dark-border-focus',
			'darkColorButtonBg'    => '--gfb-dark-submit-bg',
			'darkColorButtonText'  => '--gfb-dark-submit-text',
			'darkColorFormShell'   => '--gfb-dark-form-shell',
		);
		$parts     = array();
		foreach ( $light_map as $attr => $var ) {
			if ( empty( $attributes[ $attr ] ) ) {
				continue;
			}
			$c = self::sanitize_gfb_color( $attributes[ $attr ] );
			if ( '' !== $c ) {
				$parts[] = $var . ':' . $c;
			}
		}
		foreach ( $dark_map as $attr => $var ) {
			if ( empty( $attributes[ $attr ] ) ) {
				continue;
			}
			$c = self::sanitize_gfb_color( $attributes[ $attr ] );
			if ( '' !== $c ) {
				$parts[] = $var . ':' . $c;
			}
		}
		return implode( ';', $parts );
	}

	/**
	 * @param array<string,mixed> $attributes Block-Attribute.
	 * @return bool Ob mindestens eine Hell- oder Dunkelfarbe gesetzt ist.
	 */
	/**
	 * Attribute aus dem gespeicherten Block mit denen aus dem Render-Callback zusammenführen
	 * (manchmal fehlen Defaults bzw. einzelne Keys im dynamischen Render).
	 *
	 * @param array<string,mixed> $attributes Vom Renderer übergebene Attribute.
	 * @param WP_Block|null       $block      Block-Instanz.
	 * @return array<string,mixed>
	 */
	private static function merge_form_render_attributes( $attributes, $block ) {
		$attrs = is_array( $attributes ) ? $attributes : array();
		if ( $block instanceof WP_Block && ! empty( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ) {
			return array_merge( $block->parsed_block['attrs'], $attrs );
		}
		return $attrs;
	}

	/**
	 * Klassen + Layout-CSS für den InnerBlocks-Bereich (vertikaler Block-Abstand / blockGap).
	 *
	 * @param array<string,mixed> $attributes Gemergte Block-Attribute.
	 * @param WP_Block|null       $block      Block-Instanz.
	 * @return array{classes:array<int,string>,style_css:string}
	 */
	private static function build_form_inner_fields_layout( $attributes, $block ) {
		$classes = array( 'gfb-form-fields' );
		if ( function_exists( 'wp_unique_prefixed_id' ) ) {
			$classes[] = wp_unique_prefixed_id( 'gfb-form-fields-' );
		} else {
			$classes[] = 'gfb-form-fields-' . wp_generate_password( 8, false, false );
		}
		$unique_class = end( $classes );

		$used_layout = array(
			'type'         => 'flex',
			'orientation' => 'vertical',
		);
		if ( ! empty( $attributes['layout'] ) && is_array( $attributes['layout'] ) ) {
			$used_layout = array_merge( $used_layout, $attributes['layout'] );
		}
		if ( empty( $used_layout['type'] ) ) {
			$used_layout['type'] = 'flex';
		}

		$gap_value = null;
		if ( ! empty( $attributes['style']['spacing']['blockGap'] ) ) {
			$gap_value = $attributes['style']['spacing']['blockGap'];
		}
		if ( is_array( $gap_value ) ) {
			foreach ( $gap_value as $gk => $gv ) {
				if ( $gv && preg_match( '%[\\\\(&=}]|/\\*%', (string) $gv ) ) {
					$gap_value[ $gk ] = null;
				}
			}
		} elseif ( $gap_value && preg_match( '%[\\\\(&=}]|/\\*%', (string) $gap_value ) ) {
			$gap_value = null;
		}

		$global_settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
		$theme_block_gap = isset( $global_settings['spacing']['blockGap'] ) ? $global_settings['spacing']['blockGap'] : null;
		$has_block_gap   = isset( $theme_block_gap ) || ( null !== $gap_value );

		$block_type = null;
		if ( $block instanceof WP_Block && $block->block_type instanceof WP_Block_Type ) {
			$block_type = $block->block_type;
		} else {
			$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'gfb/form' );
		}
		$should_skip_gap = $block_type
			? wp_should_skip_block_supports_serialization( $block_type, 'spacing', 'blockGap' )
			: false;

		$fallback_gap = '0.5em';
		if ( $block_type && isset( $block_type->supports['spacing']['blockGap']['__experimentalDefault'] ) ) {
			$fallback_gap = (string) $block_type->supports['spacing']['blockGap']['__experimentalDefault'];
		}

		$block_spacing = null;
		if ( ! empty( $attributes['style']['spacing'] ) && is_array( $attributes['style']['spacing'] ) ) {
			$block_spacing = $attributes['style']['spacing'];
		}

		$style_css = '';
		if ( function_exists( 'wp_get_layout_style' ) ) {
			$style_css = wp_get_layout_style(
				'.' . $unique_class,
				$used_layout,
				$has_block_gap,
				$gap_value,
				$should_skip_gap,
				$fallback_gap,
				$block_spacing
			);
		}

		return array(
			'classes'   => $classes,
			'style_css' => is_string( $style_css ) ? $style_css : '',
		);
	}

	private static function form_has_any_custom_colors( $attributes ) {
		$keys = array(
			'colorLabel',
			'colorText',
			'colorPlaceholder',
			'colorFieldBg',
			'colorBorder',
			'colorFocus',
			'colorButtonBg',
			'colorButtonText',
			'colorFormShell',
			'darkColorLabel',
			'darkColorText',
			'darkColorPlaceholder',
			'darkColorFieldBg',
			'darkColorBorder',
			'darkColorFocus',
			'darkColorButtonBg',
			'darkColorButtonText',
			'darkColorFormShell',
		);
		foreach ( $keys as $k ) {
			if ( ! empty( $attributes[ $k ] ) && '' !== self::sanitize_gfb_color( $attributes[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render form wrapper and status state.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content Inner content.
	 * @param WP_Block $block Block object.
	 * @return string
	 */
	public static function render_form_block( $attributes, $content, $block ) {
		$attributes = self::merge_form_render_attributes( $attributes, $block );
		$form_id    = ! empty( $attributes['formId'] ) ? sanitize_key( $attributes['formId'] ) : '';
		if ( ! $form_id ) {
			return '';
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			global $post;
			$post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
		}

		$action = esc_url( admin_url( 'admin-post.php' ) );

		// Stabiler Entwurf-Schlüssel pro Block-Instanz (IndexedDB), nicht pro PHP-Render (sonst Löschen/Restore falsch).
		$instance_id = isset( $attributes['blockInstanceId'] ) ? sanitize_key( (string) $attributes['blockInstanceId'] ) : '';
		if ( '' === $instance_id ) {
			$instance_id = '0';
		}
		$key = $post_id . ':' . $form_id . ':' . $instance_id;
		$draft_enabled   = ! isset( $attributes['draftEnabled'] ) || (bool) $attributes['draftEnabled'];
		$restore_mode    = isset( $attributes['restoreMode'] ) ? sanitize_key( (string) $attributes['restoreMode'] ) : 'auto';
		$draft_ttl_days  = isset( $attributes['draftTtlDays'] ) ? absint( $attributes['draftTtlDays'] ) : 7;
		$show_draft_reset = ! isset( $attributes['showDraftReset'] ) || (bool) $attributes['showDraftReset'];

		if ( $draft_ttl_days < 1 ) {
			$draft_ttl_days = 1;
		}
		if ( $draft_ttl_days > 30 ) {
			$draft_ttl_days = 30;
		}

		$appearance = self::sanitize_appearance_mode( isset( $attributes['appearanceMode'] ) ? $attributes['appearanceMode'] : 'theme' );

		wp_enqueue_script( 'gfb-frontend' );
		/* Immer laden: bei „Theme + eigene Farben“ verbinden die Regeln Inline-Variablen mit den Feldern; bei Hell/Dunkel/Auto über Tokens am Wrapper. */
		wp_enqueue_style( 'gfb-form' );

		// H5: Status + Detail aus URL nur über Whitelist; Texte serverseitig.
		$status      = isset( $_GET['gfb_status'] ) ? sanitize_key( wp_unslash( $_GET['gfb_status'] ) ) : '';
		$status_form = isset( $_GET['gfb_form'] ) ? sanitize_key( wp_unslash( $_GET['gfb_form'] ) ) : '';
		$status_code = isset( $_GET['gfb_code'] ) ? sanitize_key( wp_unslash( $_GET['gfb_code'] ) ) : '';
		$status_msg  = '';
		if ( in_array( $status, array( 'success', 'error' ), true ) && $status_form === $form_id ) {
			if ( '' !== $status_code ) {
				$status_msg = GFB_Submit_Handler::status_message_for( $status_code );
			}
			if ( '' === $status_msg ) {
				$status_msg = ( 'success' === $status )
					? __( 'Danke! Das Formular wurde erfolgreich gesendet.', 'gutenberg-formbuilder' )
					: __( 'Beim Absenden ist ein Fehler aufgetreten.', 'gutenberg-formbuilder' );
			}
			// Optionaler Detailtext (URL-Whitelist); nur serverseitig erzeugte Slugs.
			$detail_codes = array( 'err_validation', 'err_file', 'err_external', 'err_crypto' );
			if ( 'error' === $status && $status_code && in_array( $status_code, $detail_codes, true ) && isset( $_GET['gfb_detail'] ) ) {
				$detail = sanitize_text_field( wp_unslash( $_GET['gfb_detail'] ) );
				$detail = mb_substr( $detail, 0, 500 );
				if ( '' !== $detail ) {
					$status_msg .= ' ' . $detail;
				}
			}
		} else {
			// Status nicht zu unserem Formular oder ungültig -> kein Notice anzeigen.
			$status = '';
		}

		$inner_blocks_parsed = array();
		if ( $block instanceof WP_Block && ! empty( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] ) ) {
			$inner_blocks_parsed = $block->parsed_block['innerBlocks'];
		}
		$split_inner         = self::split_form_inner_blocks( $inner_blocks_parsed );
		$field_only_blocks   = $split_inner['fields'];
		$success_only_blocks = $split_inner['success'];
		$thank_you_page_id   = isset( $attributes['thankYouPageId'] ) ? absint( $attributes['thankYouPageId'] ) : 0;
		$is_same_page_success = ( 'success' === $status && $status_form === $form_id && $thank_you_page_id < 1 );
		$show_success_panels  = $is_same_page_success && ! empty( $success_only_blocks );

		$has_file_field = self::parsed_blocks_contain_block( $field_only_blocks, 'gfb/field-file' );

		$wrapper_classes   = array( 'gfb-form-wrapper' );
		$form_color_style = self::build_form_inline_color_style( $attributes );
		/* Theme + eigene Farben: form.css bindet --gfb-light-* / --gfb-dark-* an die Felder (siehe .gfb-form-colors-custom). */
		if ( 'theme' === $appearance && self::form_has_any_custom_colors( $attributes ) ) {
			$wrapper_classes[] = 'gfb-form-colors-custom';
		}
		if ( 'theme' !== $appearance ) {
			if ( self::gfb_sanitized_attr_is_css_gradient( $attributes['colorFormShell'] ?? '' ) ) {
				$wrapper_classes[] = 'gfb-form-shell-gradient--light';
			}
			if ( self::gfb_sanitized_attr_is_css_gradient( $attributes['darkColorFormShell'] ?? '' ) ) {
				$wrapper_classes[] = 'gfb-form-shell-gradient--dark';
			}
		}
		$wrapper_attr_args = array(
			'class'               => implode( ' ', $wrapper_classes ),
			'data-gfb-appearance' => $appearance,
			'data-gfb-form-id'    => $form_id,
			'data-gfb-key'        => $key,
		);
		if ( $show_success_panels ) {
			$wrapper_attr_args['data-gfb-await-tokens'] = '1';
			$labels_for_map = array();
			foreach ( self::get_form_schema_from_post( $post_id, $form_id ) as $row ) {
				if ( ! empty( $row['name'] ) ) {
					$labels_for_map[ (string) $row['name'] ] = isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : '';
				}
			}
			if ( ! empty( $labels_for_map ) ) {
				$wrapper_attr_args['data-gfb-label-map'] = wp_json_encode(
					$labels_for_map,
					JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				);
			}
		}
		if ( '' !== $form_color_style ) {
			$wrapper_attr_args['style'] = $form_color_style;
		}
		$wrapper_attrs = get_block_wrapper_attributes( $wrapper_attr_args, $block );

		$inner_fields = self::build_form_inner_fields_layout( $attributes, $block );
		$inner_class  = implode( ' ', $inner_fields['classes'] );

		// H1+H3: Honeypot-Feldname pro Instanz + HMAC-Token, der den Honeypot-Namen mitbindet.
		$hp_field  = GFB_Security::honeypot_field_name( $post_id, $form_id, $instance_id );
		$gfb_token = GFB_Security::create_token( $post_id, $form_id, $instance_id, $hp_field );

		// K3: Inner-Block-Markup vor Ausgabe streng filtern, damit selbst manipulierte
		// Block-Comments keine Skripte/Attribute einschleusen können.
		$fields_serialized = '';
		foreach ( $field_only_blocks as $fb ) {
			$fields_serialized .= serialize_block( $fb );
		}
		$fields_html = '' !== $fields_serialized ? do_blocks( $fields_serialized ) : '';
		$safe_content = self::ksesed_inner_blocks_html( $fields_html );
		if ( '' === trim( $safe_content ) && '' !== trim( (string) $content ) && empty( $inner_blocks_parsed ) ) {
			$safe_content = self::ksesed_inner_blocks_html( (string) $content );
		}

		$success_serialized = '';
		foreach ( $success_only_blocks as $sb ) {
			$success_serialized .= serialize_block( $sb );
		}
		$safe_success = '';
		if ( '' !== $success_serialized ) {
			$safe_success = self::ksesed_success_inner_html( do_blocks( $success_serialized ) );
		}

		$show_notice = ( $status && $status_msg && ! $show_success_panels );

		ob_start();
		if ( $show_success_panels ) :
			?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="gfb-form-success-output" data-gfb-success-root="1">
				<?php echo $safe_success; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses (Erfolgs-Whitelist) ?>
			</div>
		</div>
			<?php
		else :
			?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $show_notice ) : ?>
				<div class="gfb-notice gfb-notice-<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $status_msg ); ?>
				</div>
			<?php endif; ?>
			<form class="gfb-form" method="post" action="<?php echo $action; ?>" data-gfb-key="<?php echo esc_attr( $key ); ?>" data-gfb-webkit-datetime-fallback="<?php echo esc_attr( self::is_webkit_datetime_fallback_enabled() ? '1' : '0' ); ?>" lang="<?php echo esc_attr( determine_locale() ); ?>"<?php echo $has_file_field ? ' enctype="multipart/form-data"' : ''; ?>>
				<input type="hidden" name="gfb_token" value="<?php echo esc_attr( $gfb_token ); ?>" />
				<input type="hidden" name="gfb_instance_id" value="<?php echo esc_attr( $instance_id ); ?>" />
				<input type="text" name="<?php echo esc_attr( $hp_field ); ?>" value="" tabindex="-1" autocomplete="off" class="gfb-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" />
				<?php wp_nonce_field( 'gfb_submit_' . $form_id . '_' . $post_id, 'gfb_nonce' ); ?>
				<input type="hidden" name="action" value="gfb_submit" />
				<input type="hidden" name="gfb_post_id" value="<?php echo esc_attr( $post_id ); ?>" />
				<input type="hidden" name="gfb_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
				<input type="hidden" name="gfb_draft_key" value="<?php echo esc_attr( $key ); ?>" />
				<input type="hidden" name="gfb_draft_enabled" value="<?php echo esc_attr( $draft_enabled ? '1' : '0' ); ?>" />
				<input type="hidden" name="gfb_draft_mode" value="<?php echo esc_attr( $restore_mode ); ?>" />
				<input type="hidden" name="gfb_draft_ttl_days" value="<?php echo esc_attr( (string) $draft_ttl_days ); ?>" />
				<?php if ( $draft_enabled && $show_draft_reset ) : ?>
					<p class="gfb-draft-tools">
						<button type="button" class="gfb-draft-reset-button"><?php esc_html_e( 'Entwurf löschen', 'gutenberg-formbuilder' ); ?></button>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $inner_fields['style_css'] ) ) : ?>
					<style><?php echo $inner_fields['style_css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS aus wp_get_layout_style() ?></style>
				<?php endif; ?>
				<div class="<?php echo esc_attr( $inner_class ); ?>">
					<?php echo $safe_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- über wp_kses() vorgefiltert ?>
				</div>
			</form>
		</div>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Strenge KSES-Whitelist für das von Field-Block-`save()` produzierte HTML.
	 *
	 * Verteidigung gegen Stored XSS, falls jemand mit unfiltered_html-Capability
	 * (oder bei kuenftigen WP-Kses-Bypass-Bugs) Block-Markup direkt manipuliert.
	 *
	 * @param string $html Roh-HTML aus do_blocks() der InnerBlocks.
	 * @return string Gefiltertes HTML.
	 */
	private static function ksesed_inner_blocks_html( $html ) {
		$attr_global = array(
			'class'    => true,
			'id'       => true,
			'style'    => true,
			'data-*'   => true,
			'tabindex' => true,
			'aria-*'   => true,
			'role'     => true,
			'hidden'   => true,
			'title'    => true,
			'lang'     => true,
			'dir'      => true,
		);
		$attr_form_input = array_merge(
			$attr_global,
			array(
				'name'         => true,
				'value'        => true,
				'placeholder'  => true,
				'required'     => true,
				'readonly'     => true,
				'disabled'     => true,
				'autocomplete' => true,
				'autofocus'    => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'minlength'    => true,
				'maxlength'    => true,
				'pattern'      => true,
				'inputmode'    => true,
				'multiple'     => true,
				'accept'       => true,
				'size'         => true,
				'type'         => true,
				'checked'      => true,
				'list'         => true,
			)
		);

		$allowed = array(
			'div'      => $attr_global,
			'span'     => $attr_global,
			'p'        => $attr_global,
			'label'    => array_merge( $attr_global, array( 'for' => true ) ),
			'fieldset' => array_merge( $attr_global, array( 'form' => true ) ),
			'legend'   => $attr_global,
			'small'    => $attr_global,
			'em'       => $attr_global,
			'strong'   => $attr_global,
			'br'       => $attr_global,
			'ul'       => $attr_global,
			'ol'       => $attr_global,
			'li'       => $attr_global,
			'output'   => array_merge( $attr_global, array( 'for' => true, 'name' => true ) ),
			'input'    => $attr_form_input,
			'textarea' => array_merge( $attr_form_input, array( 'rows' => true, 'cols' => true, 'wrap' => true ) ),
			'select'   => $attr_form_input,
			'option'   => array_merge( $attr_global, array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true ) ),
			'optgroup' => array_merge( $attr_global, array( 'label' => true, 'disabled' => true ) ),
			'datalist' => $attr_global,
			'button'   => array_merge( $attr_global, array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'form' => true ) ),
			'a'        => array_merge( $attr_global, array( 'href' => true, 'target' => true, 'rel' => true ) ),
		);

		/**
		 * Erlaubte Tags/Attribute für Field-Block-Inner-HTML. Erweiterungen mit
		 * Bedacht (nichts mit JS-/Style-führenden Attributen wie on*, srcdoc, etc.).
		 *
		 * @param array<string,array<string,bool>> $allowed
		 */
		$allowed = apply_filters( 'gfb_inner_blocks_allowed_html', $allowed );

		$kses = wp_kses( $html, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );

		// Sicherheitsnetz: alle "on*"-Eventhandler entfernen, falls sie irgendwo durchrutschen sollten.
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', (string) $kses );
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*\'[^\']*\'/i', '', (string) $kses );

		return (string) $kses;
	}

	/**
	 * Erfolgsbereich: KSES wie typischer Beitragsinhalt (ohne Skripte), plus Sicherheitsnetz gegen on*-Handler.
	 *
	 * @param string $html HTML aus do_blocks() der Erfolgs-InnerBlocks.
	 * @return string
	 */
	private static function ksesed_success_inner_html( $html ) {
		$allowed = wp_kses_allowed_html( 'post' );
		unset( $allowed['script'], $allowed['style'] );
		/**
		 * Erlaubte Tags für den Erfolgsbereich (Core-Blöcke o. ä.).
		 *
		 * @param array<string,array<string,bool>> $allowed
		 */
		$allowed = apply_filters( 'gfb_success_inner_blocks_allowed_html', $allowed );
		$kses    = wp_kses( (string) $html, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );
		$kses    = preg_replace( '/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', (string) $kses );
		$kses    = preg_replace( '/\s+on[a-z]+\s*=\s*\'[^\']*\'/i', '', (string) $kses );
		return (string) $kses;
	}

	/**
	 * Innere Blöcke des Formulars in Felder vs. Erfolgsbereiche splitten.
	 *
	 * @param array<int,array<string,mixed>> $inner_blocks Geparste innerBlocks des gfb/form-Blocks.
	 * @return array{fields:array<int,array<string,mixed>>,success:array<int,array<string,mixed>>}
	 */
	private static function split_form_inner_blocks( $inner_blocks ) {
		$fields  = array();
		$success = array();
		foreach ( $inner_blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( 'gfb/form-success' === ( $b['blockName'] ?? '' ) ) {
				$success[] = $b;
			} else {
				$fields[] = $b;
			}
		}
		return array(
			'fields'  => $fields,
			'success' => $success,
		);
	}

	/**
	 * Frontend: ein Erfolgs-Panel (InnerBlocks als HTML, KSES in render_form_success_block).
	 *
	 * @param array<string,mixed> $attributes Block-Attribute.
	 * @param string                $content    Gerenderte InnerBlocks.
	 * @param WP_Block              $block      Block-Instanz.
	 * @return string
	 */
	public static function render_form_success_block( $attributes, $content, $block ) {
		unset( $attributes, $block );
		$html = is_string( $content ) ? $content : '';
		$safe  = self::ksesed_success_inner_html( $html );
		return '<section class="gfb-form-success-panel" data-gfb-success-panel="1">' . $safe . '</section>';
	}

	/**
	 * Payload einer Einsendung: zuerst form_id der Zeile, dann nur Felder dieses Formularschemas.
	 *
	 * Verhindert Vermischung, wenn auf einer Seite mehrere Formulare dieselben Feldnamen hatten
	 * oder historische Daten ohne eindeutige Zuordnung vorliegen.
	 *
	 * @param object|array<string,mixed> $row DB-Zeile (mind. form_id, post_id, payload).
	 * @return array<string,mixed> Feldwerte ohne _gfb_labels.
	 */
	public static function get_submission_payload_for_row( $row ) {
		$form_id = '';
		$post_id = 0;
		$raw     = '';

		if ( is_object( $row ) ) {
			$form_id = isset( $row->form_id ) ? sanitize_key( (string) $row->form_id ) : '';
			$post_id = isset( $row->post_id ) ? absint( $row->post_id ) : 0;
			$raw     = isset( $row->payload ) ? (string) $row->payload : '';
		} elseif ( is_array( $row ) ) {
			$form_id = isset( $row['form_id'] ) ? sanitize_key( (string) $row['form_id'] ) : '';
			$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
			$raw     = isset( $row['payload'] ) ? (string) $row['payload'] : '';
		}

		if ( '' === $form_id ) {
			return array();
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$schema_keys = array();
		if ( $post_id > 0 ) {
			foreach ( self::get_form_schema_from_post( $post_id, $form_id ) as $field ) {
				$n = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
				if ( '' !== $n ) {
					$schema_keys[ $n ] = true;
				}
			}
		}

		$out = array();
		foreach ( $payload as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$k = sanitize_key( (string) $key );
			if ( '' === $k ) {
				continue;
			}
			if ( ! empty( $schema_keys ) && ! isset( $schema_keys[ $k ] ) ) {
				continue;
			}
			$out[ $k ] = $value;
		}

		return $out;
	}

	/**
	 * Build field schema for server validation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $form_id Form ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_form_schema_from_post( $post_id, $form_id ) {
		$form_block = self::locate_form_block_for_post( $post_id, $form_id );
		if ( null === $form_block ) {
			return array();
		}

		return self::extract_fields_from_inner_blocks( $form_block['innerBlocks'] ?? array() );
	}

	/**
	 * Attribute des gfb/form-Blocks (z. B. Folgeseite nach Absenden).
	 *
	 * @param int    $post_id Post-ID.
	 * @param string $form_id Formular-ID.
	 * @return array<string,mixed>
	 */
	public static function get_form_block_attributes_from_post( $post_id, $form_id ) {
		$form_block = self::locate_form_block_for_post( $post_id, $form_id );
		if ( null === $form_block ) {
			return array();
		}
		return isset( $form_block['attrs'] ) && is_array( $form_block['attrs'] ) ? $form_block['attrs'] : array();
	}

	/**
	 * Findet den gfb/form-Block in Beitragsinhalt, synchronisierten Blöcken, Template-Parts und FSE-Templates.
	 *
	 * @param int    $post_id Post-ID (wie beim Rendern / gfb_post_id).
	 * @param string $form_id Formular-ID (sanitize_key).
	 * @return array<string,mixed>|null Geparster Block oder null.
	 */
	public static function locate_form_block_for_post( $post_id, $form_id ) {
		$post_id = absint( $post_id );
		$form_id = sanitize_key( (string) $form_id );
		if ( ! $post_id || '' === $form_id ) {
			return null;
		}

		$visited = array();
		$sources = array();

		$content = get_post_field( 'post_content', $post_id );
		if ( is_string( $content ) && '' !== $content ) {
			$sources[] = $content;
		}

		foreach ( self::gfb_fse_template_markup_candidates_for_post( $post_id ) as $markup ) {
			if ( is_string( $markup ) && '' !== $markup ) {
				$sources[] = $markup;
			}
		}

		/**
		 * Zusätzliche Roh-Markup-Quellen (z. B. Custom-Layouts), filterbar.
		 *
		 * @param array<int,string> $sources   Kandidaten (Block-Markup).
		 * @param int               $post_id   Post-ID.
		 * @param string            $form_id   Formular-ID.
		 */
		$sources = apply_filters( 'gfb_form_schema_markup_sources', $sources, $post_id, $form_id );

		foreach ( $sources as $source ) {
			$blocks     = parse_blocks( $source );
			$form_block = self::find_form_block_recursive( $blocks, $form_id, $visited );
			if ( null !== $form_block ) {
				return $form_block;
			}
		}

		return null;
	}

	/**
	 * Block-Templates (FSE), in denen ein Formular typischerweise liegt, wenn nicht im Beitragsinhalt.
	 *
	 * @param int $post_id Post-ID.
	 * @return array<int,string> HTML/Block-Markup (Reihenfolge: spezifischer zuerst).
	 */
	private static function gfb_fse_template_markup_candidates_for_post( $post_id ) {
		$out = array();
		if ( ! $post_id || ! function_exists( 'get_block_template' ) || ! current_theme_supports( 'block-templates' ) ) {
			return $out;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $out;
		}

		$ptype = $post->post_type;
		$slug  = isset( $post->post_name ) ? (string) $post->post_name : '';
		$slug  = sanitize_title( $slug );

		$hierarchy = array();
		if ( 'page' === $ptype ) {
			if ( $slug ) {
				$hierarchy[] = 'page-' . $slug;
			}
			$hierarchy[] = 'page';
		} elseif ( 'post' === $ptype ) {
			if ( $slug ) {
				$hierarchy[] = 'single-' . $slug;
			}
			$hierarchy[] = 'single';
		} else {
			if ( $slug ) {
				$hierarchy[] = 'single-' . $ptype . '-' . $slug;
			}
			$hierarchy[] = 'single-' . $ptype;
		}
		$hierarchy[] = 'singular';
		$hierarchy[] = 'index';

		$theme_slugs = array_unique(
			array_filter(
				array(
					get_stylesheet(),
					get_template() !== get_stylesheet() ? get_template() : '',
				)
			)
		);

		foreach ( $hierarchy as $tpl_slug ) {
			foreach ( $theme_slugs as $theme_slug ) {
				$tpl = get_block_template( $theme_slug . '//' . $tpl_slug, 'wp_template' );
				if ( $tpl && ! empty( $tpl->content ) ) {
					$out[] = $tpl->content;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Sucht ein gfb/form mit passender formId (verschachtelt, Synopsis, Template-Part).
	 *
	 * @param array<int,array<string,mixed>> $blocks  Geparste Blöcke.
	 * @param string                         $form_id Formular-ID.
	 * @param array<string,bool>             $visited Schutz vor Zyklen (Refs/Template-Parts).
	 * @return array<string,mixed>|null Block-Array oder null.
	 */
	private static function find_form_block_recursive( $blocks, $form_id, array &$visited ) {
		foreach ( $blocks as $block ) {
			if ( 'gfb/form' === ( $block['blockName'] ?? '' ) ) {
				$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
				$id    = isset( $attrs['formId'] ) ? sanitize_key( (string) $attrs['formId'] ) : '';
				if ( $id === $form_id ) {
					return $block;
				}
			}

			// Synchronisierter Muster-/Bibliotheks-Block: Inhalt liegt im wp_block-Beitrag (ref).
			if ( 'core/block' === ( $block['blockName'] ?? '' ) ) {
				$ref = isset( $block['attrs']['ref'] ) ? absint( $block['attrs']['ref'] ) : 0;
				if ( $ref && empty( $visited[ 'core_block:' . $ref ] ) ) {
					$visited[ 'core_block:' . $ref ] = true;
					$ref_post                        = get_post( $ref );
					if ( $ref_post && 'wp_block' === $ref_post->post_type && 'publish' === $ref_post->post_status ) {
						$inner = parse_blocks( (string) $ref_post->post_content );
						$found = self::find_form_block_recursive( $inner, $form_id, $visited );
						if ( null !== $found ) {
							return $found;
						}
					}
				}
			}

			// Template-Part (FSE): Inhalt aus Theme/DB auflösen.
			if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
				$slug  = isset( $block['attrs']['slug'] ) ? sanitize_text_field( (string) $block['attrs']['slug'] ) : '';
				$theme = isset( $block['attrs']['theme'] ) ? sanitize_text_field( (string) $block['attrs']['theme'] ) : '';
				if ( $slug && function_exists( 'get_block_template' ) ) {
					if ( '' === $theme ) {
						$theme = get_stylesheet();
					}
					$tp_key = 'template_part:' . $theme . ':' . $slug;
					if ( empty( $visited[ $tp_key ] ) ) {
						$visited[ $tp_key ] = true;
						$tp                 = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
						if ( ! $tp && get_template() !== get_stylesheet() ) {
							$tp = get_block_template( get_template() . '//' . $slug, 'wp_template_part' );
						}
						if ( $tp && ! empty( $tp->content ) ) {
							$inner = parse_blocks( $tp->content );
							$found = self::find_form_block_recursive( $inner, $form_id, $visited );
							if ( null !== $found ) {
								return $found;
							}
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_form_block_recursive( $block['innerBlocks'], $form_id, $visited );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Recursively gather form fields from inner blocks.
	 *
	 * @param array $inner_blocks Nested blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_fields_from_inner_blocks( $inner_blocks ) {
		$fields = array();
		foreach ( $inner_blocks as $block ) {
			$name  = $block['blockName'] ?? '';
			if ( 'gfb/form-success' === $name ) {
				continue;
			}
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			$row = self::block_to_field_row( $name, $attrs );
			if ( null !== $row ) {
				$fields[] = $row;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$fields = array_merge( $fields, self::extract_fields_from_inner_blocks( $block['innerBlocks'] ) );
			}
		}
		return $fields;
	}

	/**
	 * Map block type to schema row.
	 *
	 * @param string               $block_name Block name.
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array<string,mixed>|null
	 */
	private static function block_to_field_row( $block_name, $attrs ) {
		$map = array(
			'gfb/field-text'     => 'text',
			'gfb/field-email'    => 'email',
			'gfb/field-textarea' => 'textarea',
			'gfb/field-select'   => 'select',
			'gfb/field-checkbox' => 'checkbox',
			'gfb/field-number'   => 'number',
			'gfb/field-tel'      => 'tel',
			'gfb/field-url'      => 'url',
			'gfb/field-date'     => 'date',
			'gfb/field-time'     => 'time',
			'gfb/field-datetime' => 'datetime',
			'gfb/field-radio'    => 'radio',
			'gfb/field-hidden'   => 'hidden',
			'gfb/field-range'    => 'range',
			'gfb/field-file'     => 'file',
		);
		if ( ! isset( $map[ $block_name ] ) ) {
			return null;
		}
		$type = $map[ $block_name ];
		$key  = isset( $attrs['name'] ) ? sanitize_key( (string) $attrs['name'] ) : '';
		if ( ! $key ) {
			return null;
		}

		$label = isset( $attrs['label'] ) ? sanitize_text_field( (string) $attrs['label'] ) : $key;
		if ( 'hidden' === $type && '' === $label ) {
			$label = $key;
		}

		$row = array(
			'name'      => $key,
			'label'     => $label,
			'type'      => $type,
			'required'  => ! empty( $attrs['required'] ) && 'hidden' !== $type,
			// Sensitive: Wert wird vor dem Speichern verschlüsselt (AES-256-GCM via GFB_Crypto).
			'sensitive' => ! empty( $attrs['sensitive'] ),
		);
		// Files sind immer verschlüsselt-im-Storage; das `sensitive`-Flag ist hier ohne Effekt.
		if ( 'file' === $type ) {
			$row['sensitive'] = false;
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) && isset( $attrs['options'] ) ) {
			$row['options'] = self::parse_option_lines( (string) $attrs['options'] );
		}

		if ( in_array( $type, array( 'number', 'range' ), true ) ) {
			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				if ( isset( $attrs[ $k ] ) && '' !== (string) $attrs[ $k ] ) {
					$row[ $k ] = sanitize_text_field( (string) $attrs[ $k ] );
				}
			}
		}

		if ( 'date' === $type ) {
			foreach ( array( 'min', 'max' ) as $k ) {
				if ( isset( $attrs[ $k ] ) && '' !== (string) $attrs[ $k ] ) {
					$row[ $k ] = sanitize_text_field( (string) $attrs[ $k ] );
				}
			}
		}

		if ( 'hidden' === $type && isset( $attrs['hiddenValue'] ) ) {
			$row['hidden_value'] = sanitize_text_field( (string) $attrs['hiddenValue'] );
		}

		// M5: lockedValue erzwingt den Server-Wert. Client-POST wird ignoriert.
		if ( 'hidden' === $type && ! empty( $attrs['lockedValue'] ) ) {
			$row['locked'] = true;
		}

		if ( 'file' === $type ) {
			$row['accept']      = isset( $attrs['accept'] ) ? sanitize_text_field( (string) $attrs['accept'] ) : '';
			$row['max_size_mb'] = isset( $attrs['maxSizeMb'] ) ? max( 1, absint( $attrs['maxSizeMb'] ) ) : 8;
		}

		return $row;
	}

	/**
	 * @param string $raw Multiline options.
	 * @return array<int,string>
	 */
	private static function parse_option_lines( $raw ) {
		$out   = array();
		$lines = explode( "\n", $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = sanitize_text_field( $line );
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $needle Block name.
	 */
	private static function parsed_blocks_contain_block( $blocks, $needle ) {
		foreach ( $blocks as $b ) {
			if ( $needle === ( $b['blockName'] ?? '' ) ) {
				return true;
			}
			if ( ! empty( $b['innerBlocks'] ) && self::parsed_blocks_contain_block( $b['innerBlocks'], $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
