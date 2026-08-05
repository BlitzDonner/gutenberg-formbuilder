<?php
/**
 * BDLIZ_Module – zentrale Lizenzverwaltung fuer Blitz-&-Donner-Plugins.
 *
 * Wird als Kopie mit jedem Plugin ausgeliefert und vom bdliz-Loader als
 * neueste Version genau einmal geladen. Verwaltet ein oder mehrere
 * Lizenz-Tokens in EINER gemeinsamen Ablage, klaert mit dem Update-Server,
 * welches Token welches Plugin abdeckt, und zeigt einen gemeinsamen
 * Einstellungs-Screen («Einstellungen → Blitz & Donner Lizenzen»).
 *
 * GPL-GRENZE (verbindlich): Dieses Modul steuert ausschliesslich den
 * Update-Bezug. Ohne Lizenz laufen alle Plugins voll funktionsfaehig weiter –
 * kein Killswitch, keine Funktionsabschaltung.
 *
 * SICHERHEIT:
 * - Tokens werden nie in der Oberflaeche im Klartext angezeigt (nur Anfang).
 * - Alle Aktionen: Capability manage_options + Nonce, POST.
 * - Tokens gehen nur als Bearer-Header an den eigenen Server, nie in URLs.
 *
 * @package bdliz
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BDLIZ_Module' ) ) :

class BDLIZ_Module {

	const VERSION    = '1.0.0';
	const SERVER_URL = 'https://plugins.blitzdonner.ch';
	const OPTION     = 'bdliz_tokens';
	const PAGE_SLUG  = 'bdliz-lizenzen';

	/** @var BDLIZ_Module|null */
	private static $instance = null;

	/** @var array<string,array> slug => array(name, legacy_option) */
	private $plugins = array();

	public static function instance() {
		return self::$instance;
	}

	/**
	 * Vom Loader auf plugins_loaded aufgerufen.
	 *
	 * @param array $plugins Registrierte Plugins (slug => name/legacy_option).
	 */
	public static function boot( $plugins ) {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance          = new self();
		self::$instance->plugins = is_array( $plugins ) ? $plugins : array();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::$instance, 'register_page' ) );
			add_action( 'admin_init', array( self::$instance, 'migrate_legacy_tokens' ) );
			add_action( 'admin_init', array( self::$instance, 'maybe_refresh_coverage' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Token-Abfrage (genutzt von den Update-Clients)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Token, das dieses Plugin abdeckt, oder ''.
	 *
	 * @param string $slug Plugin-Slug.
	 * @return string
	 */
	public function token_for( $slug ) {
		foreach ( $this->tokens() as $entry ) {
			if ( ! empty( $entry['coverage'][ $slug ] ) ) {
				return (string) $entry['token'];
			}
		}
		return '';
	}

	/** @return array Liste der Token-Eintraege. */
	private function tokens() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/** Token-Liste speichern (autoload aus – wird nur bei Bedarf gelesen). */
	private function save_tokens( $list ) {
		update_option( self::OPTION, array_values( $list ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Migration der bisherigen Einzel-Optionen                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Uebernimmt vorhandene Einzel-Tokens (z.B. gfb_update_token) einmalig in
	 * die gemeinsame Ablage. Fuer Bestandskunden unsichtbar: das Token bleibt
	 * dasselbe, nur der Ort wechselt. Die alte Option bleibt als Rueckfall-
	 * ebene stehen, bis der Kunde sie selbst entfernt.
	 */
	public function migrate_legacy_tokens() {
		$list    = $this->tokens();
		$known   = wp_list_pluck( $list, 'token' );
		$changed = false;

		foreach ( $this->plugins as $slug => $info ) {
			if ( empty( $info['legacy_option'] ) ) {
				continue;
			}
			$legacy = (string) get_option( $info['legacy_option'], '' );
			if ( '' === $legacy || in_array( $legacy, $known, true ) ) {
				continue;
			}
			$list[]  = array(
				'token'      => $legacy,
				'coverage'   => array(),
				'checked_at' => 0,
			);
			$known[] = $legacy;
			$changed = true;
		}

		if ( $changed ) {
			$this->save_tokens( $list );
			delete_transient( 'bdliz_refresh_lock' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Abdeckungs-Pruefung gegen den Update-Server                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Prueft je Token und Plugin-Slug, ob der Server den Update-Bezug erlaubt
	 * (HTTP 200 = abgedeckt, 403 = nicht abgedeckt). Gleicher Endpunkt und
	 * gleiche Semantik wie der Update-Client.
	 */
	public function refresh_coverage() {
		$slugs = $this->relevant_slugs();
		$list  = $this->tokens();

		foreach ( $list as $i => $entry ) {
			$coverage = array();
			foreach ( $slugs as $slug ) {
				$response = wp_remote_post(
					self::SERVER_URL . '/bd-updater/check/' . rawurlencode( $slug ),
					array(
						'timeout' => 10,
						'headers' => array(
							'Accept'        => 'application/json',
							'Authorization' => 'Bearer ' . $entry['token'],
						),
						'body'    => array( 'domain' => wp_parse_url( home_url(), PHP_URL_HOST ) ),
					)
				);
				if ( is_wp_error( $response ) ) {
					// Server nicht erreichbar: bisherige Einschaetzung behalten.
					$coverage[ $slug ] = ! empty( $entry['coverage'][ $slug ] );
					continue;
				}
				$coverage[ $slug ] = ( 200 === wp_remote_retrieve_response_code( $response ) );
			}
			$list[ $i ]['coverage']   = $coverage;
			$list[ $i ]['checked_at'] = time();
		}

		$this->save_tokens( $list );
		set_transient( 'bdliz_refresh_lock', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Prueft im Admin hoechstens einmal pro Stunde, ob ein registriertes
	 * Plugin in keiner Abdeckungs-Tabelle vorkommt (z.B. frisch installiert),
	 * und holt die Pruefung dann nach.
	 */
	public function maybe_refresh_coverage() {
		if ( get_transient( 'bdliz_refresh_lock' ) ) {
			return;
		}
		$list = $this->tokens();
		if ( empty( $list ) ) {
			return;
		}
		foreach ( array_keys( $this->plugins ) as $slug ) {
			foreach ( $list as $entry ) {
				if ( ! isset( $entry['coverage'][ $slug ] ) ) {
					$this->refresh_coverage();
					return;
				}
			}
		}
		set_transient( 'bdliz_refresh_lock', 1, HOUR_IN_SECONDS );
	}

	/** Slugs, deren Abdeckung geprueft wird: installierte + Katalog. */
	private function relevant_slugs() {
		$slugs = array_keys( $this->plugins );
		foreach ( $this->catalog() as $item ) {
			if ( ! in_array( $item['slug'], $slugs, true ) ) {
				$slugs[] = $item['slug'];
			}
		}
		return $slugs;
	}

	/* ------------------------------------------------------------------ */
	/* Katalog des Update-Servers                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Oeffentlicher Plugin-Katalog des Servers (12 Stunden gecacht).
	 *
	 * @return array Eintraege mit slug, name, description, version, page_url.
	 */
	private function catalog() {
		$cached = get_transient( 'bdliz_catalog' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			self::SERVER_URL . '/wp-json/bd-updater/catalog',
			array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		$items = array();
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) ) {
				foreach ( $body as $row ) {
					if ( isset( $row['slug'], $row['name'], $row['page_url'] ) ) {
						$items[] = array(
							'slug'        => (string) $row['slug'],
							'name'        => (string) $row['name'],
							'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
							'version'     => isset( $row['version'] ) ? (string) $row['version'] : '',
							'page_url'    => (string) $row['page_url'],
						);
					}
				}
			}
		}
		set_transient( 'bdliz_catalog', $items, 12 * HOUR_IN_SECONDS );
		return $items;
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungs-Screen                                                 */
	/* ------------------------------------------------------------------ */

	public function register_page() {
		$hook = add_options_page(
			'Blitz & Donner Lizenzen',
			'B&D Lizenzen',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
		add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
	}

	/** POST-Aktionen: Token hinzufuegen, entfernen, Abdeckung neu pruefen. */
	public function handle_actions() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$action = isset( $_POST['bdliz_action'] ) ? sanitize_key( wp_unslash( $_POST['bdliz_action'] ) ) : '';

		if ( 'add' === $action ) {
			check_admin_referer( 'bdliz_add' );
			$token = isset( $_POST['bdliz_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bdliz_token'] ) ) ) : '';
			if ( '' !== $token && strlen( $token ) <= 128 ) {
				$list  = $this->tokens();
				$known = wp_list_pluck( $list, 'token' );
				if ( ! in_array( $token, $known, true ) ) {
					$list[] = array( 'token' => $token, 'coverage' => array(), 'checked_at' => 0 );
					$this->save_tokens( $list );
				}
				$this->refresh_coverage();
			}
		} elseif ( 'remove' === $action ) {
			check_admin_referer( 'bdliz_remove' );
			$index = isset( $_POST['bdliz_index'] ) ? absint( $_POST['bdliz_index'] ) : -1;
			$list  = $this->tokens();
			if ( isset( $list[ $index ] ) ) {
				unset( $list[ $index ] );
				$this->save_tokens( $list );
			}
		} elseif ( 'refresh' === $action ) {
			check_admin_referer( 'bdliz_refresh' );
			$this->refresh_coverage();
		} else {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&aktualisiert=1' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tokens  = $this->tokens();
		$catalog = $this->catalog();

		echo '<div class="wrap"><h1>Blitz &amp; Donner Lizenzen</h1>';
		if ( isset( $_GET['aktualisiert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>';
		}
		echo '<p>Eine Lizenz gilt fuer alle hier aufgefuehrten Plugins, die sie abdeckt. Ohne Lizenz laufen die Plugins uneingeschraenkt weiter – es entfallen nur die automatischen Updates.</p>';

		// Lizenzen.
		echo '<h2>Hinterlegte Lizenzen</h2>';
		if ( empty( $tokens ) ) {
			echo '<p>Noch keine Lizenz hinterlegt.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Lizenz</th><th>Deckt ab</th><th>Geprueft</th><th></th></tr></thead><tbody>';
			foreach ( $tokens as $i => $entry ) {
				$covered = array();
				foreach ( $entry['coverage'] as $slug => $ok ) {
					if ( $ok ) {
						$covered[] = isset( $this->plugins[ $slug ]['name'] ) ? $this->plugins[ $slug ]['name'] : $slug;
					}
				}
				echo '<tr>';
				echo '<td><code>' . esc_html( substr( (string) $entry['token'], 0, 6 ) ) . '&hellip;</code></td>';
				echo '<td>' . ( $covered ? esc_html( implode( ', ', $covered ) ) : '<em>kein Plugin</em>' ) . '</td>';
				echo '<td>' . ( $entry['checked_at'] ? esc_html( wp_date( 'd.m.Y H:i', $entry['checked_at'] ) ) : '&ndash;' ) . '</td>';
				echo '<td><form method="post">';
				wp_nonce_field( 'bdliz_remove' );
				echo '<input type="hidden" name="bdliz_action" value="remove"><input type="hidden" name="bdliz_index" value="' . esc_attr( $i ) . '">';
				echo '<button class="button button-small" onsubmit="">Entfernen</button>';
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'bdliz_refresh' );
			echo '<input type="hidden" name="bdliz_action" value="refresh">';
			echo '<button class="button">Abdeckung neu pruefen</button></form>';
		}

		echo '<h2 style="margin-top:1.5em">Lizenz hinzufuegen</h2>';
		echo '<form method="post" autocomplete="off" style="max-width:760px">';
		wp_nonce_field( 'bdliz_add' );
		echo '<input type="hidden" name="bdliz_action" value="add">';
		echo '<input type="password" name="bdliz_token" class="regular-text" autocomplete="off" spellcheck="false" placeholder="Lizenz-Token einfuegen"> ';
		echo '<button class="button button-primary">Hinzufuegen</button>';
		echo '<p class="description">Das Token stammt aus Ihrer Bestellung bei Blitz &amp; Donner. Es wird nur an plugins.blitzdonner.ch gesendet und hier nie im Klartext angezeigt.</p>';
		echo '</form>';

		// Installierte Plugins.
		echo '<h2 style="margin-top:1.5em">Installierte Blitz-&amp;-Donner-Plugins</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Plugin</th><th>Lizenz-Status</th></tr></thead><tbody>';
		foreach ( $this->plugins as $slug => $info ) {
			$has = ( '' !== $this->token_for( $slug ) );
			$url = '';
			foreach ( $catalog as $item ) {
				if ( $item['slug'] === $slug ) {
					$url = $item['page_url'];
					break;
				}
			}
			echo '<tr><td>' . esc_html( $info['name'] ) . '</td><td>';
			if ( $has ) {
				echo '<span style="color:#1a7f37">lizenziert &ndash; Updates aktiv</span>';
			} else {
				echo '<span style="color:#b32d2e">keine Lizenz &ndash; keine automatischen Updates</span>';
				if ( $url ) {
					echo ' &middot; <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Lizenz erwerben</a>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// Weitere verfuegbare Plugins.
		$weitere = array();
		foreach ( $catalog as $item ) {
			if ( ! isset( $this->plugins[ $item['slug'] ] ) ) {
				$weitere[] = $item;
			}
		}
		if ( $weitere ) {
			echo '<h2 style="margin-top:1.5em">Weitere Plugins von Blitz &amp; Donner</h2>';
			echo '<table class="widefat striped" style="max-width:760px"><tbody>';
			foreach ( $weitere as $item ) {
				echo '<tr><td style="width:220px"><strong>' . esc_html( $item['name'] ) . '</strong></td>';
				echo '<td>' . esc_html( $item['description'] ) . '</td>';
				echo '<td style="width:160px"><a href="' . esc_url( $item['page_url'] ) . '" target="_blank" rel="noopener">Ansehen und laden</a></td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p class="description" style="margin-top:1.5em">Lizenz-Modul Version ' . esc_html( self::VERSION ) . '</p>';
		echo '</div>';
	}
}

endif;
