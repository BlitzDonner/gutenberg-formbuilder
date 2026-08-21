// Einrichtung einer frischen WordPress-Umgebung für den Testlauf.

export async function einrichten( u ) {
	await u.warten( 180 );

	const schonDa = await u.wp( 'core is-installed' ).then( () => true ).catch( () => false );
	if ( ! schonDa ) {
		await u.wp(
			`core install --url=${ u.basis } --title="GFB Testreihe" ` +
			`--admin_user=chef --admin_password=chef-geheim --admin_email=chef@example.test --skip-email`
		);
	}

	// «kommend» soll die Fassung von morgen prüfen. Das Beta-Abbild hinkt nach
	// einer Freigabe hinterher, deshalb der Sprung auf die Nightly.
	if ( 'kommend' === u.kennung ) {
		try {
			await u.wp( 'core update --version=nightly --force' );
			await u.wp( 'core update-db' );
		} catch ( fehler ) {
			u.hinweis = 'Nightly liess sich nicht laden, geprüft wurde das Beta-Abbild.';
		}
	}

	// Das WordPress-Abbild schreibt WORDPRESS_CONFIG_EXTRA nicht in die
	// wp-config. Ohne diese Konstanten liefe das Plugin im Klartext-Weg und
	// die Verschlüsselung bliebe ungeprüft.
	const schluessel = process.env.GFB_MASTER_KEYS || '';
	if ( schluessel ) {
		await u.wp( [ 'config', 'set', 'GFB_MASTER_KEYS', schluessel, '--type=constant' ] );
		await u.wp( [ 'config', 'set', 'GFB_ACTIVE_KEY_ID', process.env.GFB_ACTIVE_KEY_ID || '1', '--type=constant' ] );
	}
	await u.wp( [ 'config', 'set', 'WP_DEBUG_LOG', '/var/www/html/wp-content/debug.log', '--type=constant' ] );
	await u.wp( [ 'config', 'set', 'WP_DEBUG_DISPLAY', 'false', '--raw', '--type=constant' ] );
	await u.wp( [ 'config', 'set', 'AUTOMATIC_UPDATER_DISABLED', 'true', '--raw', '--type=constant' ] );

	await u.wp( 'option update timezone_string Europe/Zurich' );
	await u.wp( 'option update blogdescription "Testreihe"' );
	await u.wp( 'rewrite structure "/%postname%/" --hard' );
	await u.wp( 'rewrite flush --hard' );

	// Sprache: de_CH, falls das Paket geladen werden kann.
	await u.wp( 'language core install de_CH --activate' ).catch( () => {} );

	// In der Umstiegs-Umgebung spielt Gruppe M die Versionen selbst ein.
	if ( 'umstieg' !== u.kennung ) {
		await u.wp( 'plugin activate gutenberg-formbuilder' );
	}

	// Zusätzliche Personen für die Rechteprüfung.
	await u.wp( 'user create redakteur redakteur@example.test --role=editor --user_pass=geheim' ).catch( () => {} );
	await u.wp( 'user create leser leser@example.test --role=subscriber --user_pass=geheim' ).catch( () => {} );

	// Spam-Schutz konfigurieren: ohne Schlüssel bliebe die ganze Prüfkette aus.
	// Die Antwort des Anbieters stellt das mu-Plugin nach. In der Umstiegs-
	// Umgebung passiert das später, nach dem Einspielen der Vorversion.
	if ( 'umstieg' === u.kennung ) {
		u.wpVersion = await u.wp( 'core version' );
		return;
	}
	await u.php( `
		GFB_Captcha::update_settings( array(
			'enabled'  => true,
			'site_key' => 'TESTLAUF-SITEKEY',
			'api_key'  => 'TESTLAUF-APIKEY',
		) );
		echo wp_json_encode( GFB_Captcha::get_settings() );
	` );

	u.wpVersion = await u.wp( 'core version' );
}

/** Setzt einen Schalter der Teststeuerung. */
export async function steuern( u, schalter ) {
	const json = JSON.stringify( schalter ).replaceAll( "'", "'\\''" );
	await u.php( `
		$neu = json_decode( '${ json }', true );
		$alt = get_option( 'gfb_test_steuerung', array() );
		update_option( 'gfb_test_steuerung', array_merge( is_array( $alt ) ? $alt : array(), $neu ), false );
		echo 'ok';
	` );
}

/** Setzt alle Schalter zurück. */
export async function steuerungZuruecksetzen( u ) {
	await u.php( `delete_option( 'gfb_test_steuerung' ); echo 'ok';` );
}

/** Liest die Anzahl Einsendungen. */
export async function einsendungenZaehlen( u, formId = '' ) {
	const wo = formId ? `WHERE form_id = '${ formId }'` : '';
	const ausgabe = await u.php( `
		global $wpdb;
		echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gfb_submissions ${ wo }" );
	` );
	return parseInt( ausgabe.trim(), 10 );
}

/** Liest die jüngste Einsendung als Feld-Wert-Paare. */
export async function letzteEinsendung( u ) {
	const ausgabe = await u.php( `
		global $wpdb;
		$zeile = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}gfb_submissions ORDER BY id DESC LIMIT 1", ARRAY_A );
		echo wp_json_encode( $zeile ?: array() );
	` );
	return JSON.parse( ausgabe.trim() || '{}' );
}

/** Liest das Fehlerprotokoll von WordPress. */
export async function fehlerprotokoll( u ) {
	return u.imWp( 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true' );
}

/** Leert das Fehlerprotokoll. */
export async function protokollLeeren( u ) {
	await u.imWp( ': > /var/www/html/wp-content/debug.log 2>/dev/null || true' );
}

/**
 * Setzt alle Zähler zurück, die eine Wiederholung im selben Container bremsen:
 * der Häufigkeits-Schutz beim Absenden (fünf pro zehn Minuten), das
 * Sendekontingent der Bestätigungsmail (zehn pro Stunde und IP) und die
 * Grenze für Bestätigungsklicks. Ohne das blockte der fünfte Prüfpunkt alle
 * folgenden, obwohl er mit ihnen nichts zu tun hat.
 */
export async function zaehlerLoeschen( u ) {
	await u.php( `
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%gfb_rate%'
			OR option_name LIKE '%gfb_confirm_rate%'
			OR option_name LIKE 'gfb_rg_%'" );
		wp_cache_flush();
		echo 'ok';
	` );
}
