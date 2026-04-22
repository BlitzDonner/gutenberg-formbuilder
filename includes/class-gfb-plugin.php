<?php
/**
 * Core plugin bootstrap and block registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Plugin {
	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_for_redirect_query' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_post_gfb_submit', array( 'GFB_Submit_Handler', 'handle' ) );
		add_action( 'admin_post_nopriv_gfb_submit', array( 'GFB_Submit_Handler', 'handle' ) );
		GFB_Admin_Submissions::boot();
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

		wp_register_script(
			'gfb-frontend',
			GFB_PLUGIN_URL . 'assets/frontend.js',
			array(),
			GFB_PLUGIN_VERSION,
			true
		);

		wp_register_style(
			'gfb-form',
			GFB_PLUGIN_URL . 'assets/form.css',
			array(),
			GFB_PLUGIN_VERSION
		);
	}

	/**
	 * Lädt gfb-frontend auf Folgeseiten ohne Formularblock (IndexedDB-Draft per URL löschen).
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
	 * Styles für die Block-Vorschau im Editor.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		wp_enqueue_style( 'gfb-form' );
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

		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-text' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-email' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-textarea' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-select' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-checkbox' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-submit' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-number' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-tel' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-url' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-date' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-time' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-datetime' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-radio' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-hidden' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-range' );
		register_block_type( GFB_PLUGIN_DIR . 'blocks/field-file' );
	}

	/**
	 * Erscheinungsmodus für Hell/Dunkel/Automatisch.
	 *
	 * @param mixed $value Raw attribute.
	 * @return string auto|light|dark
	 */
	private static function sanitize_appearance_mode( $value ) {
		$v = is_string( $value ) ? sanitize_key( $value ) : 'auto';
		if ( in_array( $v, array( 'auto', 'light', 'dark' ), true ) ) {
			return $v;
		}
		return 'auto';
	}

	/**
	 * Farbwert für style="--var: …" (wie im Block-Editor, nicht nur 6-stelliges Hex).
	 *
	 * @param mixed $value Raw attribute.
	 * @return string Sicherer CSS-Farbwert oder leer.
	 */
	private static function sanitize_gfb_color( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		if ( is_string( $hex ) && '' !== $hex ) {
			return $hex;
		}
		// 8-stelliges Hex (#RRGGBBAA), von Color-Pickern üblich — sanitize_hex_color lehnt das ab.
		if ( preg_match( '/^#([A-Fa-f0-9]{8})$/', $value ) ) {
			return $value;
		}
		// Theme-/Block-Presets: var(--wp--preset--color--…)
		if ( preg_match( '/^var\(\s*(--[a-zA-Z0-9\-]+)\s*\)$/', $value ) ) {
			return $value;
		}
		// rgb() / rgba() (inkl. Prozent, z. B. rgb(100%, 0%, 0%))
		if ( preg_match( '/^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)$/i', $value ) ) {
			return $value;
		}
		// hsl() / hsla()
		if ( preg_match( '/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(,\s*[\d.]+\s*)?\)$/i', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * CSS-Variablen für das Formular-Wrapper-Element (Theme-Farben).
	 *
	 * @param array<string,mixed> $attributes Block-Attribute.
	 * @return string Semikolon-getrennte Deklarationen ohne abschließendes Semikolon am Ende (für style="").
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
			'darkColorLabel',
			'darkColorText',
			'darkColorPlaceholder',
			'darkColorFieldBg',
			'darkColorBorder',
			'darkColorFocus',
			'darkColorButtonBg',
			'darkColorButtonText',
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
		$form_id = ! empty( $attributes['formId'] ) ? sanitize_key( $attributes['formId'] ) : '';
		if ( ! $form_id ) {
			return '';
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			global $post;
			$post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
		}

		$action = esc_url( admin_url( 'admin-post.php' ) );

		// Stabiler Draft-Key pro Block-Instanz (IndexedDB), nicht pro PHP-Render (sonst Löschen/Restore falsch).
		$instance_id = isset( $attributes['blockInstanceId'] ) ? sanitize_key( (string) $attributes['blockInstanceId'] ) : '';
		if ( '' === $instance_id ) {
			$instance_id = '0';
		}
		$key = $post_id . ':' . $form_id . ':' . $instance_id;
		$draft_enabled   = ! isset( $attributes['draftEnabled'] ) || (bool) $attributes['draftEnabled'];
		$restore_mode    = isset( $attributes['restoreMode'] ) ? sanitize_key( (string) $attributes['restoreMode'] ) : 'prompt';
		$draft_ttl_days  = isset( $attributes['draftTtlDays'] ) ? absint( $attributes['draftTtlDays'] ) : 7;
		$show_draft_reset = ! isset( $attributes['showDraftReset'] ) || (bool) $attributes['showDraftReset'];

		if ( $draft_ttl_days < 1 ) {
			$draft_ttl_days = 1;
		}
		if ( $draft_ttl_days > 30 ) {
			$draft_ttl_days = 30;
		}

		wp_enqueue_script( 'gfb-frontend' );
		wp_enqueue_style( 'gfb-form' );

		$status      = isset( $_GET['gfb_status'] ) ? sanitize_key( wp_unslash( $_GET['gfb_status'] ) ) : '';
		$status_form = isset( $_GET['gfb_form'] ) ? sanitize_key( wp_unslash( $_GET['gfb_form'] ) ) : '';
		$status_msg  = isset( $_GET['gfb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gfb_msg'] ) ) : '';

		$has_file_field = false;
		if ( $block instanceof WP_Block && ! empty( $block->parsed_block['innerBlocks'] ) ) {
			$has_file_field = self::parsed_blocks_contain_block( $block->parsed_block['innerBlocks'], 'gfb/field-file' );
		}

		$appearance        = self::sanitize_appearance_mode( isset( $attributes['appearanceMode'] ) ? $attributes['appearanceMode'] : 'auto' );
		$wrapper_classes   = array( 'gfb-form-wrapper' );
		$form_color_style = self::build_form_inline_color_style( $attributes );
		if ( self::form_has_any_custom_colors( $attributes ) ) {
			$wrapper_classes[] = 'gfb-form-colors-custom';
		}
		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>" data-gfb-appearance="<?php echo esc_attr( $appearance ); ?>"<?php
		if ( '' !== $form_color_style ) :
			echo ' style="' . esc_attr( $form_color_style ) . '"';
		endif;
		?>>
			<?php if ( $status && $status_form === $form_id && $status_msg ) : ?>
				<div class="gfb-notice gfb-notice-<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $status_msg ); ?>
				</div>
			<?php endif; ?>
			<form class="gfb-form" method="post" action="<?php echo $action; ?>" data-gfb-key="<?php echo esc_attr( $key ); ?>"<?php echo $has_file_field ? ' enctype="multipart/form-data"' : ''; ?>>
				<input type="hidden" name="gfb_rendered_at" value="<?php echo esc_attr( (string) time() ); ?>" />
				<input type="text" name="gfb_hp_field" value="" tabindex="-1" autocomplete="off" class="gfb-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" />
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
						<button type="button" class="gfb-draft-reset-button"><?php esc_html_e( 'Draft löschen', 'gutenberg-formbuilder' ); ?></button>
					</p>
				<?php endif; ?>
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build field schema for server validation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $form_id Form ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_form_schema_from_post( $post_id, $form_id ) {
		$content = get_post_field( 'post_content', $post_id );
		if ( ! $content ) {
			return array();
		}

		$blocks     = parse_blocks( $content );
		$form_block = self::find_form_block_recursive( $blocks, $form_id );
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
		$content = get_post_field( 'post_content', $post_id );
		if ( ! $content ) {
			return array();
		}
		$blocks     = parse_blocks( $content );
		$form_block = self::find_form_block_recursive( $blocks, $form_id );
		if ( null === $form_block ) {
			return array();
		}
		return isset( $form_block['attrs'] ) && is_array( $form_block['attrs'] ) ? $form_block['attrs'] : array();
	}

	/**
	 * Sucht ein gfb/form mit passender formId auch in verschachtelten Blöcken (Gruppe, Spalten …).
	 *
	 * @param array<int,array<string,mixed>> $blocks Geparste Blöcke.
	 * @param string                         $form_id Formular-ID.
	 * @return array<string,mixed>|null Block-Array oder null.
	 */
	private static function find_form_block_recursive( $blocks, $form_id ) {
		foreach ( $blocks as $block ) {
			if ( 'gfb/form' === ( $block['blockName'] ?? '' ) ) {
				$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
				$id    = isset( $attrs['formId'] ) ? sanitize_key( (string) $attrs['formId'] ) : '';
				if ( $id === $form_id ) {
					return $block;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_form_block_recursive( $block['innerBlocks'], $form_id );
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
		if ( 'hidden' === $type ) {
			$label = $key;
		}

		$row = array(
			'name'     => $key,
			'label'    => $label,
			'type'     => $type,
			'required' => ! empty( $attrs['required'] ) && 'hidden' !== $type,
		);

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
