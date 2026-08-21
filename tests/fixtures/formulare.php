<?php
/**
 * Rückfall ohne Browser: baut die Testseiten aus Blockkommentaren.
 *
 * Feldblöcke speichern im Editor echtes HTML. Dieses Markup hier hat nur die
 * Kommentare, das Frontend rendert trotzdem richtig (der Server baut aus den
 * Attributen). Im Editor gelten die Blöcke damit als ungültig – deshalb ist
 * dieser Weg nur der Rückfall für Läufe ohne Browser.
 *
 * Quelle der Formulare: formulare.json, dieselbe Datei nutzt der Editor-Weg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spez = json_decode( (string) file_get_contents( __DIR__ . '/formulare.json' ), true );
if ( ! is_array( $spez ) ) {
	echo wp_json_encode( array( 'fehler' => 'formulare.json nicht lesbar' ) ) . "\n";
	return;
}

/**
 * Baut den Kommentar eines Blocks.
 *
 * @param string $name  Vollständiger Blockname.
 * @param array  $attrs Attribute.
 * @return string
 */
function gfbt_block( $name, array $attrs = array(), array $inner = array() ) {
	$json = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( empty( $inner ) ) {
		return "<!-- wp:{$name}{$json} /-->";
	}
	$teile = array();
	foreach ( $inner as $kind ) {
		$teile[] = gfbt_block(
			$kind['name'],
			isset( $kind['attrs'] ) ? $kind['attrs'] : array(),
			isset( $kind['inner'] ) ? $kind['inner'] : array()
		);
	}
	return "<!-- wp:{$name}{$json} -->\n" . implode( "\n", $teile ) . "\n<!-- /wp:{$name} -->";
}

/**
 * Legt eine Seite an oder aktualisiert sie.
 *
 * @param string $slug   Seitenkennung.
 * @param string $titel  Seitentitel.
 * @param string $inhalt Blockmarkup.
 * @return int
 */
function gfbt_seite( $slug, $titel, $inhalt ) {
	$vorhanden = get_page_by_path( $slug, OBJECT, 'page' );
	$daten     = array(
		'post_title'   => $titel,
		'post_name'    => $slug,
		'post_content' => wp_slash( $inhalt ),
		'post_status'  => 'publish',
		'post_type'    => 'page',
	);
	if ( $vorhanden ) {
		$daten['ID'] = $vorhanden->ID;
		return (int) wp_update_post( $daten );
	}
	return (int) wp_insert_post( $daten );
}

$danke_vorhanden = get_page_by_path( 'gfbt-danke', OBJECT, 'page' );
$danke_id        = $danke_vorhanden
	? (int) $danke_vorhanden->ID
	: gfbt_seite( 'gfbt-danke', 'Danke', '<!-- wp:paragraph --><p>Vielen Dank für die Einsendung.</p><!-- /wp:paragraph -->' );

$seiten = array( 'dankeZiel' => $danke_id );

foreach ( $spez['formulare'] as $formular ) {
	$felder = isset( $formular['felder'] ) ? $formular['felder'] : $spez['felder'];
	$attrs  = $formular['attrs'];
	if ( ! empty( $formular['dankeSeite'] ) ) {
		$attrs['thankYouPageId'] = $danke_id;
	}

	$teile = array();
	foreach ( $felder as $feld ) {
		$teile[] = gfbt_block(
			$feld['name'],
			isset( $feld['attrs'] ) ? $feld['attrs'] : array(),
			isset( $feld['inner'] ) ? $feld['inner'] : array()
		);
	}

	$inhalt = '<!-- wp:gfb/form ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " -->\n"
		. implode( "\n", $teile ) . "\n<!-- /wp:gfb/form -->";

	$seiten[ $formular['kennung'] ] = gfbt_seite( $formular['slug'], $formular['titel'], $inhalt );
}

echo wp_json_encode( array( 'seiten' => $seiten ) ) . "\n";
