<?php
/**
 * Backend-Seite «Texte»: alle besucher- und mailsichtbaren Sätze bearbeiten.
 *
 * @package gutenberg-formbuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oberfläche zur Textverwaltung (Registry in GFB_Texts).
 */
class GFB_Admin_Texts {

	const PAGE_SLUG = 'gfb-texts';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 13 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_post' ) );
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			GFB_Admin_Submissions::PAGE_SLUG,
			__( 'Texte', 'gutenberg-formbuilder' ),
			__( 'Texte', 'gutenberg-formbuilder' ),
			GFB_Capabilities::CAP_MANAGE_SETTINGS,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Speichern. Nonce und Berechtigung wie auf der Einstellungsseite.
	 *
	 * @return void
	 */
	public static function maybe_handle_post() {
		if ( ! isset( $_POST['gfb_texts_action'] ) ) {
			return;
		}
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ) );
		}
		check_admin_referer( 'gfb_texts_save' );

		$anrede = isset( $_POST['gfb_anrede'] ) && 'du' === sanitize_key( wp_unslash( $_POST['gfb_anrede'] ) ) ? 'du' : 'sie';
		update_option( GFB_Texts::OPTION_ANREDE, $anrede, false );

		$mode_in = isset( $_POST['gfb_site_label_mode'] ) ? sanitize_key( wp_unslash( $_POST['gfb_site_label_mode'] ) ) : 'domain';
		$mode    = in_array( $mode_in, array( 'domain', 'title', 'custom' ), true ) ? $mode_in : 'domain';
		update_option( GFB_Texts::OPTION_SITE_LABEL_MODE, $mode, false );
		$custom = isset( $_POST['gfb_site_label_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['gfb_site_label_custom'] ) ) : '';
		update_option( GFB_Texts::OPTION_SITE_LABEL_CUSTOM, mb_substr( $custom, 0, 120 ), false );

		$input = array();
		foreach ( GFB_Texts::registry() as $group_key => $group ) {
			foreach ( array_keys( $group['texts'] ) as $text_key ) {
				$field = 'gfbtext_' . $group_key . '__' . $text_key;
				if ( isset( $_POST[ $field ] ) ) {
					$input[ $group_key . '.' . $text_key ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitisierung zentral in GFB_Texts::save().
				}
			}
		}

		$rejected = GFB_Texts::save( $input );

		$args = array( 'page' => self::PAGE_SLUG, 'gfb_saved' => '1' );
		if ( ! empty( $rejected ) ) {
			$args['gfb_rejected'] = rawurlencode( implode( ',', $rejected ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ) );
		}

		$stored   = GFB_Texts::stored();
		$registry = GFB_Texts::registry();

		echo '<div class="wrap gfb-admin gfb-admin-texts">';
		echo '<h1>' . esc_html__( 'Texte', 'gutenberg-formbuilder' ) . '</h1>';
		echo '<p class="description" style="max-width:60rem">'
			. esc_html__( 'Hier steht jeder Satz, den eine ausfüllende Person zu sehen bekommt – im Formular, in den Meldungen, in den Mails und auf den Bestätigungsseiten. Leeres Feld bedeutet: der eingebaute Standardtext gilt (übersetzt in Deutsch, Englisch, Französisch und Italienisch). Ein eigener Text gilt unverändert für alle Formulare und Sprachen.', 'gutenberg-formbuilder' )
			. '</p>';

		if ( isset( $_GET['gfb_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Texte gespeichert.', 'gutenberg-formbuilder' ) . '</p></div>';
		}
		if ( ! empty( $_GET['gfb_rejected'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige.
			$list = array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_GET['gfb_rejected'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Diese Texte wurden nicht übernommen, weil ihnen ein Platzhalter fehlt oder sie einen zusätzlichen enthalten – dort gilt weiterhin der bisherige Text:', 'gutenberg-formbuilder' )
				. ' <code>' . esc_html( implode( '</code>, <code>', $list ) ) . '</code></p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'gfb_texts_save' );
		echo '<input type="hidden" name="gfb_texts_action" value="save" />';

		$anrede = GFB_Texts::anrede();
		echo '<div class="gfb-anrede-box">';
		echo '<strong>' . esc_html__( 'Anrede der Standardtexte', 'gutenberg-formbuilder' ) . '</strong> ';
		echo '<label style="margin-left:1rem"><input type="radio" name="gfb_anrede" value="sie" ' . checked( $anrede, 'sie', false ) . ' /> ' . esc_html__( 'Sie', 'gutenberg-formbuilder' ) . '</label> ';
		echo '<label style="margin-left:1rem"><input type="radio" name="gfb_anrede" value="du" ' . checked( $anrede, 'du', false ) . ' /> ' . esc_html__( 'Du', 'gutenberg-formbuilder' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Gilt für die eingebauten Standardtexte. Eigene Texte bleiben unverändert – sie stehen ohnehin so da, wie Sie sie eingetragen haben. Texte ohne Anrede sind in beiden Formen gleich.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</div>';

		$mode   = (string) get_option( GFB_Texts::OPTION_SITE_LABEL_MODE, 'domain' );
		$custom = (string) get_option( GFB_Texts::OPTION_SITE_LABEL_CUSTOM, '' );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$host   = is_string( $host ) ? preg_replace( '/^www\./i', '', $host ) : '';
		$title  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		echo '<div class="gfb-anrede-box">';
		echo '<strong>' . esc_html__( 'Bezeichnung der Website in Mails', 'gutenberg-formbuilder' ) . '</strong>';
		echo '<p class="description" style="margin:.3rem 0 .6rem">' . esc_html__( 'Sie erscheint in den Betreffzeilen, über der Datentabelle, in der Fusszeile und als Absendername. Die Domain ist die Vorgabe: Empfängerinnen erkennen sie zuverlässiger als einen Markennamen, der von der Adresse abweichen kann.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<p><label><input type="radio" name="gfb_site_label_mode" value="domain" ' . checked( $mode, 'domain', false ) . ' /> '
			. esc_html__( 'Domain', 'gutenberg-formbuilder' ) . ' <code>' . esc_html( $host ) . '</code></label></p>';
		echo '<p><label><input type="radio" name="gfb_site_label_mode" value="title" ' . checked( $mode, 'title', false ) . ' /> '
			. esc_html__( 'Website-Titel', 'gutenberg-formbuilder' ) . ' <code>' . esc_html( $title ) . '</code></label></p>';
		echo '<p><label><input type="radio" name="gfb_site_label_mode" value="custom" ' . checked( $mode, 'custom', false ) . ' /> '
			. esc_html__( 'Eigene Bezeichnung', 'gutenberg-formbuilder' ) . '</label> '
			. '<input type="text" class="regular-text" maxlength="120" name="gfb_site_label_custom" value="' . esc_attr( $custom ) . '" placeholder="' . esc_attr( $host ) . '" /></p>';
		echo '<p class="description">' . sprintf(
			/* translators: %s: aktuell wirksame Bezeichnung */
			esc_html__( 'Aktuell wirksam: %s', 'gutenberg-formbuilder' ),
			'<code>' . esc_html( GFB_Texts::site_label() ) . '</code>'
		) . '</p>';
		echo '</div>';

		foreach ( $registry as $group_key => $group ) {
			$overridden = 0;
			foreach ( array_keys( $group['texts'] ) as $text_key ) {
				if ( isset( $stored[ $group_key . '.' . $text_key ] ) ) {
					$overridden++;
				}
			}
			$badge = $overridden > 0
				? '<span class="gfb-texts-badge">' . sprintf(
					/* translators: %d: Anzahl geänderter Texte */
					esc_html( _n( '%d eigener Text', '%d eigene Texte', $overridden, 'gutenberg-formbuilder' ) ),
					(int) $overridden
				) . '</span>'
				: '';

			echo '<details class="gfb-settings-card" id="gfb-texts-' . esc_attr( $group_key ) . '">';
			echo '<summary><h2>' . esc_html( $group['label'] ) . '</h2>' . $badge . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge oben escaped.
			echo '<p class="description">' . esc_html( $group['description'] ) . '</p>';
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $group['texts'] as $text_key => $meta ) {
				$key     = $group_key . '.' . $text_key;
				$field   = 'gfbtext_' . $group_key . '__' . $text_key;
				$value   = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
				$default = GFB_Texts::default_for( $key );
				$meta['default'] = $default;
				echo '<tr>';
				echo '<th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $meta['hint'] ) . '</label></th>';
				echo '<td>';
				if ( ! empty( $meta['multiline'] ) ) {
					echo '<textarea class="large-text" rows="3" maxlength="' . esc_attr( (string) GFB_Texts::MAX_LEN ) . '" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" placeholder="' . esc_attr( $meta['default'] ) . '">' . esc_textarea( $value ) . '</textarea>';
				} else {
					echo '<input type="text" class="large-text" maxlength="' . esc_attr( (string) GFB_Texts::MAX_LEN ) . '" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $meta['default'] ) . '" />';
				}
				echo '<p class="description"><strong>' . esc_html__( 'Standard:', 'gutenberg-formbuilder' ) . '</strong> ' . esc_html( $meta['default'] );
				if ( ! empty( $meta['placeholders'] ) ) {
					echo ' <em>' . sprintf(
						/* translators: %s: Liste der Pflicht-Platzhalter */
						esc_html__( 'Pflicht-Platzhalter: %s', 'gutenberg-formbuilder' ),
						'<code>' . esc_html( implode( '</code>, <code>', (array) $meta['placeholders'] ) ) . '</code>'
					) . '</em>';
				}
				echo '</p>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></details>';
		}

		submit_button( __( 'Texte speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';
		echo '</div>';

		self::print_inline_css();
	}

	/**
	 * Karten-Optik der Seite (Muster der Einstellungsseite).
	 *
	 * @return void
	 */
	private static function print_inline_css() {
		echo '<style>
#wpbody-content .wrap.gfb-admin-texts .gfb-settings-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; margin:0 0 1rem; padding:0 1.2rem 1rem; }
#wpbody-content .wrap.gfb-admin-texts .gfb-settings-card > summary { cursor:pointer; padding:.9rem 0; display:flex; align-items:center; gap:.6rem; }
#wpbody-content .wrap.gfb-admin-texts .gfb-settings-card > summary h2 { display:inline; margin:0; font-size:1.05rem; }
#wpbody-content .wrap.gfb-admin-texts .gfb-texts-badge { background:#f0f0f1; color:#50575e; border-radius:999px; font-size:11px; padding:.15rem .6rem; }
#wpbody-content .wrap.gfb-admin-texts .form-table th { width:22rem; font-weight:400; color:#50575e; vertical-align:top; padding-top:1rem; }
#wpbody-content .wrap.gfb-admin-texts .form-table .description code { font-size:11px; }
#wpbody-content .wrap.gfb-admin-texts .gfb-anrede-box { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:.9rem 1.2rem; margin:0 0 1rem; }
#wpbody-content .wrap.gfb-admin-texts .gfb-anrede-box .description { margin:.5rem 0 0; }
</style>';
	}
}
