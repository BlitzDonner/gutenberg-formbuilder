#!/usr/bin/env php
<?php
/**
 * Einmaliges Migrations-Skript: ersetzt deutsche Umlaut-Krücken (ae/oe/ue)
 * durch korrekte Umlaute (ä/ö/ü) in Plugin-Quelltexten.
 *
 * Sicherheitsmechanismen:
 *  - Explizite kuratierte Mapping-Liste, KEIN blindes ae→ä.
 *  - Wortgrenzen am Anfang via \b, am Ende dynamisch (Präfix-Mappings).
 *  - Englische Wörter wie `value`, `true`, `queue`, `header` werden nicht
 *    berührt (kommen in der Mapping-Liste nicht vor).
 *  - Standardmässig --dry-run; Schreiben nur mit --apply.
 *
 * Nutzung:
 *   php bin/fix-umlauts.php             # Dry-Run, listet geplante Änderungen
 *   php bin/fix-umlauts.php --apply     # schreibt Dateien
 *   php bin/fix-umlauts.php --apply --paths=includes/class-gfb-admin-settings.php
 */

$apply  = in_array( '--apply', $argv, true );
$paths  = null;
foreach ( $argv as $a ) {
	if ( str_starts_with( $a, '--paths=' ) ) {
		$paths = explode( ',', substr( $a, 8 ) );
	}
}

$root = realpath( __DIR__ . '/..' );
chdir( $root );

// ---- Wort-Mapping ----
// Ganzwort-Ersetzungen: vor und nach dem Wort muss eine Wortgrenze stehen.
// (\b matcht zwischen Wort- und Nicht-Wortzeichen.)
$whole_words = array(
	'fuer'      => 'für',
	'Fuer'      => 'Für',
	'ueber'     => 'über',
	'Ueber'     => 'Über',
	'ue'        => 'ue', // dummy, niemals als Ganzes
	'gluecklich'=> 'glücklich',
	'Glueck'    => 'Glück',
	'Buero'     => 'Büro',
	'gruene'    => 'grüne',
	'Gruen'     => 'Grün',
	'gruen'     => 'grün',
);

// Präfix-Ersetzungen: Wortanfang muss \b haben, das Ende ist offen
// (greift damit `pruef`, `pruefen`, `Pruefung`, `geprueft`, etc.)
$prefixes = array(
	// m (längere zuerst: Moeglichkeit vor moeglich)
	'Moeglichkeit'    => 'Möglichkeit',
	'moeglichkeit'    => 'möglichkeit',
	'Moeglich'        => 'Möglich',
	'moeglich'        => 'möglich',
	// p
	'pruef'           => 'prüf',
	'Pruef'           => 'Prüf',
	// k
	'koenn'           => 'könn',
	'Koenn'           => 'Könn',
	// m (muess->müss erfasst muessen, muesst, muessten; "muss" bleibt unangetastet)
	'muess'           => 'müss',
	'Muess'           => 'Müss',
	// w
	'wuerd'           => 'würd',
	'Wuerd'           => 'Würd',
	'waehl'           => 'wähl',
	'Waehl'           => 'Wähl',
	// s
	'schluess'        => 'schlüss',
	'Schluess'        => 'Schlüss',
	'schluessel'      => 'schlüssel',
	'Schluessel'      => 'Schlüssel',
	// e
	'entschluess'     => 'entschlüss',
	'Entschluess'     => 'Entschlüss',
	'verschluess'     => 'verschlüss',
	'Verschluess'     => 'Verschlüss',
	// l
	'loesch'          => 'lösch',
	'Loesch'          => 'Lösch',
	'loesung'         => 'lösung',
	'Loesung'         => 'Lösung',
	'loesen'          => 'lösen',
	// o
	'oeffentlich'     => 'öffentlich',
	'Oeffentlich'     => 'Öffentlich',
	// n
	'noetig'          => 'nötig',
	'Noetig'          => 'Nötig',
	'naechst'         => 'nächst',
	'Naechst'         => 'Nächst',
	'naemlich'        => 'nämlich',
	'Naemlich'        => 'Nämlich',
	'nuetzlich'       => 'nützlich',
	'Nuetzlich'       => 'Nützlich',
	// v
	'verfueg'         => 'verfüg',
	'Verfueg'         => 'Verfüg',
	'vollstaendig'    => 'vollständig',
	'Vollstaendig'    => 'Vollständig',
	// a
	'aenderung'       => 'änderung',
	'Aenderung'       => 'Änderung',
	'aendern'         => 'ändern',
	'Aendern'         => 'Ändern',
	'aehnlich'        => 'ähnlich',
	'Aehnlich'        => 'Ähnlich',
	'auswaehl'        => 'auswähl',
	'Auswaehl'        => 'Auswähl',
	'aufraeum'        => 'aufräum',
	'Aufraeum'        => 'Aufräum',
	'aergerlich'      => 'ärgerlich',
	// d
	'darueber'        => 'darüber',
	'Darueber'        => 'Darüber',
	// u
	'unterstuetz'     => 'unterstütz',
	'Unterstuetz'     => 'Unterstütz',
	'urspruenglich'   => 'ursprünglich',
	'Urspruenglich'   => 'Ursprünglich',
	'unguelti'        => 'ungülti',
	'unguelti'        => 'ungülti',
	'unzulaess'       => 'unzuläss',
	'Unzulaess'       => 'Unzuläss',
	// g
	'gueltig'         => 'gültig',
	'Gueltig'         => 'Gültig',
	'genuegend'       => 'genügend',
	'Genuegend'       => 'Genügend',
	'gewaehlt'        => 'gewählt',
	'Gewaehlt'        => 'Gewählt',
	'gehoert'         => 'gehört',
	'Gehoert'         => 'Gehört',
	'geoeffnet'       => 'geöffnet',
	'Geoeffnet'       => 'Geöffnet',
	// r
	'regelmaessig'    => 'regelmässig',
	'Regelmaessig'    => 'Regelmässig',
	// st
	'standardmaessig' => 'standardmässig',
	'Standardmaessig' => 'Standardmässig',
	// gr
	'groesse'         => 'grösse',
	'Groesse'         => 'Grösse',
	// h
	'hoehe'           => 'höhe',
	'Hoehe'           => 'Höhe',
	'hoechst'         => 'höchst',
	'Hoechst'         => 'Höchst',
	// b
	'beruehr'         => 'berühr',
	'Beruehr'         => 'Berühr',
	'benoetig'        => 'benötig',
	'Benoetig'        => 'Benötig',
	// e
	'erklaer'         => 'erklär',
	'Erklaer'         => 'Erklär',
	'enthaelt'        => 'enthält',
	'Enthaelt'        => 'Enthält',
	'eintraeg'        => 'einträg',
	'Eintraeg'        => 'Einträg',
	'empfaenger'      => 'empfänger',
	'Empfaenger'      => 'Empfänger',
	// f
	'fuehl'           => 'fühl',
	'Fuehl'           => 'Fühl',
	'fuehr'           => 'führ',
	'Fuehr'           => 'Führ',
	'faehig'          => 'fähig',
	'Faehig'          => 'Fähig',
	'faellt'          => 'fällt',
	'Faellt'          => 'Fällt',
	'foerder'         => 'förder',
	'Foerder'         => 'Förder',
	// g
	'geraet'          => 'gerät',
	'Geraet'          => 'Gerät',
	// p
	'persoenlich'     => 'persönlich',
	'Persoenlich'     => 'Persönlich',
	'praezise'        => 'präzise',
	'Praezise'        => 'Präzise',
	'praeventiv'      => 'präventiv',
	'Praeventiv'      => 'Präventiv',
	// gespr
	'gespraech'       => 'gespräch',
	'Gespraech'       => 'Gespräch',
	'geschaeft'       => 'geschäft',
	'Geschaeft'       => 'Geschäft',
	// hu/hi
	'haeufig'         => 'häufig',
	'Haeufig'         => 'Häufig',
	// laess
	'laesst'          => 'lässt',
	'Laesst'          => 'Lässt',
	'laeuft'          => 'läuft',
	'Laeuft'          => 'Läuft',
	'laenge'          => 'länge',
	'Laenge'          => 'Länge',
	'laenger'         => 'länger',
	'Laenger'         => 'Länger',
	// t
	'taetig'          => 'tätig',
	'Taetig'          => 'Tätig',
	'taeglich'        => 'täglich',
	'Taeglich'        => 'Täglich',
	// w
	'waehrend'        => 'während',
	'Waehrend'        => 'Während',
	'waere'           => 'wäre',
	'Waere'           => 'Wäre',
	// z
	'zugaeng'         => 'zugäng',
	'Zugaeng'         => 'Zugäng',
	'zaehl'           => 'zähl',
	'Zaehl'           => 'Zähl',
	// erh
	'erhaelt'         => 'erhält',
	'Erhaelt'         => 'Erhält',
	'erhaeltlich'     => 'erhältlich',
	// gu
	'gueter'          => 'güter',
	'Gueter'          => 'Güter',
	// stueck
	'stueck'          => 'stück',
	'Stueck'          => 'Stück',
	// h
	'haerte'          => 'härte',
	'Haerte'          => 'Härte',
	'haert'           => 'härt',
	// kuerz
	'kuerz'           => 'kürz',
	'Kuerz'           => 'Kürz',
	// vergueten? nicht zutreffend
	// schluss bleibt
	// anh
	'anhaeng'         => 'anhäng',
	'Anhaeng'         => 'Anhäng',
	// abh
	'abhaeng'         => 'abhäng',
	'Abhaeng'         => 'Abhäng',
	// ber
	'beruecksichti'   => 'berücksichti',
	'Beruecksichti'   => 'Berücksichti',
	// bekueh — nicht zutreffend
	// hi
	'hinzufuegen'     => 'hinzufügen',
	'Hinzufuegen'     => 'Hinzufügen',
	'fuege'           => 'füge',
	'Fuege'           => 'Füge',
	'fuegt'           => 'fügt',
	// erg
	'ergaenz'         => 'ergänz',
	'Ergaenz'         => 'Ergänz',
	// re
	'rueck'           => 'rück',
	'Rueck'           => 'Rück',
	// gepl
	'gepruef'         => 'geprüf', // already covered by pruef? let's be explicit
	// p
	'puenktlich'      => 'pünktlich',
	'Puenktlich'      => 'Pünktlich',
	// fuell
	'fuellen'         => 'füllen',
	'Fuellen'         => 'Füllen',
	'fuell'           => 'füll',
	'Fuell'           => 'Füll',
	// rueck schon
	// wue
	'wuerd'           => 'würd',
	// reuq nope
	// ueber prefix erfasst:
	'uebernimm'       => 'übernimm',
	'Uebernimm'       => 'Übernimm',
	'uebernehm'       => 'übernehm',
	'Uebernehm'       => 'Übernehm',
	'uebertrag'       => 'übertrag',
	'Uebertrag'       => 'Übertrag',
	'uebersicht'      => 'übersicht',
	'Uebersicht'      => 'Übersicht',
	'ueberschrift'    => 'überschrift',
	'Ueberschrift'    => 'Überschrift',
	'ueberpruef'      => 'überprüf',
	'Ueberpruef'      => 'Überprüf',
	'ueberhaupt'      => 'überhaupt',
	'Ueberhaupt'      => 'Überhaupt',
	// betr
	'betraegt'        => 'beträgt',
	'Betraegt'        => 'Beträgt',
	// ablauf, einlauf — bleibt
	// uebrig
	'uebrig'          => 'übrig',
	'Uebrig'          => 'Übrig',
	// rep — nope
	// fueg
	'einfueg'         => 'einfüg',
	'Einfueg'         => 'Einfüg',
	'beifueg'         => 'beifüg',
	'Beifueg'         => 'Beifüg',
	'zufueg'          => 'zufüg',
	'Zufueg'          => 'Zufüg',
	// fueh
	'gefuehl'         => 'gefühl',
	'Gefuehl'         => 'Gefühl',
	'gefuehrt'        => 'geführt',
	'Gefuehrt'        => 'Geführt',
	// vorgefuehrt nope
	// hueftgelenk nope
	// muellabfuhr nope
	// quaeler nope
	// quaal nope
	'quaeler'         => 'quäler',
	// ringfuehrung — nicht relevant
	// kueche
	'kueche'          => 'küche',
	'Kueche'          => 'Küche',
	// laeche
	'laechel'         => 'lächel',
	'Laechel'         => 'Lächel',
	// neuanfaenger nope
	// -aeng
	'anfaeng'         => 'anfäng',
	'Anfaeng'         => 'Anfäng',
	// Aufenth — nope
	// hoer
	'hoer'            => 'hör', // höre, hört, hörte, gehört (gehört schon erfasst, aber doppelt schadet nicht — replace_all idempotent)
	'Hoer'            => 'Hör',
	// gehoer (schon)
	// schoen
	'schoen'          => 'schön',
	'Schoen'          => 'Schön',
	// foer
	'foederativ'      => 'föderativ',
	// hauptsaech
	'hauptsaech'      => 'hauptsäch',
	'Hauptsaech'      => 'Hauptsäch',
	// ausschluess
	'ausschluess'     => 'ausschlüss',
	'Ausschluess'     => 'Ausschlüss',
	// einschluess
	'einschluess'     => 'einschlüss',
	'Einschluess'     => 'Einschlüss',
);

// Substring-Mappings: greifen auch in Komposita wie `Sicherheitspruefung`,
// `geloescht`, `Ungueltig`, `zuruecksetzen`. Nur Wort-Bausteine, die
// definitiv KEIN englisches Wort und KEIN Code-Identifier in diesem Repo
// sind (vorab mit Grep verifiziert).
$substrings = array(
	'Moeglichkeit'    => 'Möglichkeit',
	'moeglichkeit'    => 'möglichkeit',
	'Moeglich'        => 'Möglich',
	'moeglich'        => 'möglich',
	'pruef'           => 'prüf',
	'Pruef'           => 'Prüf',
	'loesch'          => 'lösch',
	'Loesch'          => 'Lösch',
	'gueltig'         => 'gültig',
	'Gueltig'         => 'Gültig',
	'zurueck'         => 'zurück',
	'Zurueck'         => 'Zurück',
	'verfueg'         => 'verfüg',
	'Verfueg'         => 'Verfüg',
	'koenn'           => 'könn',
	'Koenn'           => 'Könn',
	'muess'           => 'müss',
	'Muess'           => 'Müss',
	'wuerd'           => 'würd',
	'Wuerd'           => 'Würd',
	'waehl'           => 'wähl',
	'Waehl'           => 'Wähl',
	'noetig'          => 'nötig',
	'Noetig'          => 'Nötig',
	'naechst'         => 'nächst',
	'Naechst'         => 'Nächst',
	'aehnlich'        => 'ähnlich',
	'Aehnlich'        => 'Ähnlich',
	'enthaelt'        => 'enthält',
	'laeuft'          => 'läuft',
	'fuell'           => 'füll',
	'Fuell'           => 'Füll',
	'gepruef'         => 'geprüf',
	'verschluess'     => 'verschlüss',
	'Verschluess'     => 'Verschlüss',
	'entschluess'     => 'entschlüss',
	'Entschluess'     => 'Entschlüss',
	'schluess'        => 'schlüss',
	'Schluess'        => 'Schlüss',
	'aendern'         => 'ändern',
	'Aendern'         => 'Ändern',
	'aenderung'       => 'änderung',
	'Aenderung'       => 'Änderung',
	'oeffentlich'     => 'öffentlich',
	'Oeffentlich'     => 'Öffentlich',
	'unterstuetz'     => 'unterstütz',
	'Unterstuetz'     => 'Unterstütz',
	'standardmaessig' => 'standardmässig',
	'Standardmaessig' => 'Standardmässig',
	'regelmaessig'    => 'regelmässig',
	'Regelmaessig'    => 'Regelmässig',
	'urspruenglich'   => 'ursprünglich',
	'Urspruenglich'   => 'Ursprünglich',
	'vollstaendig'    => 'vollständig',
	'Vollstaendig'    => 'Vollständig',
	'erklaer'         => 'erklär',
	'Erklaer'         => 'Erklär',
	'haeufig'         => 'häufig',
	'Haeufig'         => 'Häufig',
	'taeglich'        => 'täglich',
	'Taeglich'        => 'Täglich',
	'taetig'          => 'tätig',
	'Taetig'          => 'Tätig',
	'haerte'          => 'härte',
	'Haerte'          => 'Härte',
	'kuerz'           => 'kürz',
	'Kuerz'           => 'Kürz',
	'rueck'           => 'rück',
	'Rueck'           => 'Rück',
	'beruehr'         => 'berühr',
	'Beruehr'         => 'Berühr',
	'benoetig'        => 'benötig',
	'Benoetig'        => 'Benötig',
	'gewaehlt'        => 'gewählt',
	'Gewaehlt'        => 'Gewählt',
	'auswaehl'        => 'auswähl',
	'Auswaehl'        => 'Auswähl',
	'gehoert'         => 'gehört',
	'Gehoert'         => 'Gehört',
	'geoeffnet'       => 'geöffnet',
	'Geoeffnet'       => 'Geöffnet',
	'eintraeg'        => 'einträg',
	'Eintraeg'        => 'Einträg',
	'empfaenger'      => 'empfänger',
	'Empfaenger'      => 'Empfänger',
	'anhaeng'         => 'anhäng',
	'Anhaeng'         => 'Anhäng',
	'abhaeng'         => 'abhäng',
	'Abhaeng'         => 'Abhäng',
	'beruecksichti'   => 'berücksichti',
	'Beruecksichti'   => 'Berücksichti',
	'ergaenz'         => 'ergänz',
	'Ergaenz'         => 'Ergänz',
	'fuehl'           => 'fühl',
	'Fuehl'           => 'Fühl',
	'fuehr'           => 'führ',
	'Fuehr'           => 'Führ',
	'faehig'          => 'fähig',
	'Faehig'          => 'Fähig',
	'faellt'          => 'fällt',
	'Faellt'          => 'Fällt',
	'foerder'         => 'förder',
	'Foerder'         => 'Förder',
	'gespraech'       => 'gespräch',
	'Gespraech'       => 'Gespräch',
	'geschaeft'       => 'geschäft',
	'Geschaeft'       => 'Geschäft',
	'laesst'          => 'lässt',
	'Laesst'          => 'Lässt',
	'laenge'          => 'länge',
	'Laenge'          => 'Länge',
	'laenger'         => 'länger',
	'Laenger'         => 'Länger',
	'persoenlich'     => 'persönlich',
	'Persoenlich'     => 'Persönlich',
	'praezise'        => 'präzise',
	'Praezise'        => 'Präzise',
	'praeventiv'      => 'präventiv',
	'Praeventiv'      => 'Präventiv',
	'puenktlich'      => 'pünktlich',
	'Puenktlich'      => 'Pünktlich',
	'waehrend'        => 'während',
	'Waehrend'        => 'Während',
	'waere'           => 'wäre',
	'Waere'           => 'Wäre',
	'zaehl'           => 'zähl',
	'Zaehl'           => 'Zähl',
	'erhaelt'         => 'erhält',
	'Erhaelt'         => 'Erhält',
	'stueck'          => 'stück',
	'Stueck'          => 'Stück',
	'darueber'        => 'darüber',
	'Darueber'        => 'Darüber',
	'einfueg'         => 'einfüg',
	'Einfueg'         => 'Einfüg',
	'beifueg'         => 'beifüg',
	'Beifueg'         => 'Beifüg',
	'zufueg'          => 'zufüg',
	'Zufueg'          => 'Zufüg',
	'hinzufueg'       => 'hinzufüg',
	'Hinzufueg'       => 'Hinzufüg',
	'gefuehl'         => 'gefühl',
	'Gefuehl'         => 'Gefühl',
	'gefuehrt'        => 'geführt',
	'Gefuehrt'        => 'Geführt',
	'kueche'          => 'küche',
	'Kueche'          => 'Küche',
	'anfaeng'         => 'anfäng',
	'Anfaeng'         => 'Anfäng',
	'schoen'          => 'schön',
	'Schoen'          => 'Schön',
	'nuetzlich'       => 'nützlich',
	'Nuetzlich'       => 'Nützlich',
	'aergerlich'      => 'ärgerlich',
	'Aergerlich'      => 'Ärgerlich',
	'unzulaess'       => 'unzuläss',
	'Unzulaess'       => 'Unzuläss',
	'ueberhaupt'      => 'überhaupt',
	'Ueberhaupt'      => 'Überhaupt',
	'uebernimm'       => 'übernimm',
	'Uebernimm'       => 'Übernimm',
	'uebernehm'       => 'übernehm',
	'Uebernehm'       => 'Übernehm',
	'uebertrag'       => 'übertrag',
	'Uebertrag'       => 'Übertrag',
	'uebersicht'      => 'übersicht',
	'Uebersicht'      => 'Übersicht',
	'ueberschrift'    => 'überschrift',
	'Ueberschrift'    => 'Überschrift',
	'ueberpruef'      => 'überprüf',
	'Ueberpruef'      => 'Überprüf',
	'uebrig'          => 'übrig',
	'Uebrig'          => 'Übrig',
	'genuegend'       => 'genügend',
	'Genuegend'       => 'Genügend',
	'groesse'         => 'grösse',
	'Groesse'         => 'Grösse',
	'hoehe'           => 'höhe',
	'Hoehe'           => 'Höhe',
	'hoechst'         => 'höchst',
	'Hoechst'         => 'Höchst',
	'naemlich'        => 'nämlich',
	'Naemlich'        => 'Nämlich',
	'loesung'         => 'lösung',
	'Loesung'         => 'Lösung',
	'loesen'          => 'lösen',
	'Loesen'          => 'Lösen',
	'hauptsaech'      => 'hauptsäch',
	'Hauptsaech'      => 'Hauptsäch',
	'ausschluess'     => 'ausschlüss',
	'Ausschluess'     => 'Ausschlüss',
	'einschluess'     => 'einschlüss',
	'Einschluess'     => 'Einschlüss',
	'fuer'            => 'für',
	'Fuer'            => 'Für',
	'ueber'           => 'über',
	'Ueber'           => 'Über',
	'schaedlich'      => 'schädlich',
	'Schaedlich'      => 'Schädlich',
	'spaeter'         => 'später',
	'Spaeter'         => 'Später',
	'zusaetzlich'     => 'zusätzlich',
	'Zusaetzlich'     => 'Zusätzlich',
	'schraegstrich'   => 'schrägstrich',
	'Schraegstrich'   => 'Schrägstrich',
	'aufblaehung'     => 'aufblähung',
	'Aufblaehung'     => 'Aufblähung',
	'buendel'         => 'bündel',
	'Buendel'         => 'Bündel',
	'unveraendert'    => 'unverändert',
	'Unveraendert'    => 'Unverändert',
	'geaendert'       => 'geändert',
	'Geaendert'       => 'Geändert',
	'identitaet'      => 'identität',
	'Identitaet'      => 'Identität',
	'zugehoerig'      => 'zugehörig',
	'Zugehoerig'      => 'Zugehörig',
	'temporaer'       => 'temporär',
	'Temporaer'       => 'Temporär',
	'haette'          => 'hätte',
	'Haette'          => 'Hätte',
	'haelt'           => 'hält',
	'Haelt'           => 'Hält',
	'bloeck'          => 'blöck',
	'Bloeck'          => 'Blöck',
	'zufaellig'       => 'zufällig',
	'Zufaellig'       => 'Zufällig',
	'staerker'        => 'stärker',
	'Staerker'        => 'Stärker',
	'binaer'          => 'binär',
	'Binaer'          => 'Binär',
	'fehlschlaeg'     => 'fehlschläg',
	'Fehlschlaeg'     => 'Fehlschläg',
	'laedt'           => 'lädt',
	'Laedt'           => 'Lädt',
	'nachtraeglich'   => 'nachträglich',
	'Nachtraeglich'   => 'Nachträglich',
	'vorgaenger'      => 'vorgänger',
	'Vorgaenger'      => 'Vorgänger',
	'eingeschraenkt'  => 'eingeschränkt',
	'Eingeschraenkt'  => 'Eingeschränkt',
	'einschraenk'     => 'einschränk',
	'Einschraenk'     => 'Einschränk',
	'schuetz'         => 'schütz',
	'Schuetz'         => 'Schütz',
	'aendert'         => 'ändert',
	'Aendert'         => 'Ändert',
	'aendere'         => 'ändere',
	'Aendere'         => 'Ändere',
);

// ---- Datei-Discovery ----
$exts = array( 'php', 'js', 'json', 'md', 'css' );
$files = array();
if ( $paths ) {
	foreach ( $paths as $p ) {
		if ( is_file( $p ) ) {
			$files[] = $p;
		}
	}
} else {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$path = $f->getPathname();
		// skip vendor/, node_modules/, .git/
		if ( str_contains( $path, '/.git/' ) || str_contains( $path, '/node_modules/' ) || str_contains( $path, '/vendor/' ) ) {
			continue;
		}
		// skip selbst
		if ( str_ends_with( $path, '/bin/fix-umlauts.php' ) ) {
			continue;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $exts, true ) ) {
			continue;
		}
		$files[] = $path;
	}
}

sort( $files );
echo count( $files ) . " Dateien werden geprüft (apply=" . ( $apply ? 'JA' : 'nein – DRY-RUN' ) . ")\n\n";

$total_changes = 0;
$file_changes  = 0;

foreach ( $files as $file ) {
	$src = file_get_contents( $file );
	if ( false === $src ) {
		continue;
	}
	$new = $src;
	$file_diffs = array();

	// Präfix-Ersetzungen: \b<KrückenPräfix>(\w*)
	foreach ( $prefixes as $bad => $good ) {
		$pattern = '/\b' . preg_quote( $bad, '/' ) . '/u';
		$new = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $bad, $good, &$file_diffs ) {
				$file_diffs[ $bad ] = ( $file_diffs[ $bad ] ?? 0 ) + 1;
				return $good;
			},
			$new
		);
	}

	// Ganzwort-Ersetzungen: \b<wort>\b
	foreach ( $whole_words as $bad => $good ) {
		if ( $bad === $good ) continue;
		$pattern = '/\b' . preg_quote( $bad, '/' ) . '\b/u';
		$new = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $bad, $good, &$file_diffs ) {
				$file_diffs[ $bad ] = ( $file_diffs[ $bad ] ?? 0 ) + 1;
				return $good;
			},
			$new
		);
	}

	// Substring-Ersetzungen (greifen auch in Komposita, sind aber kuratiert).
	foreach ( $substrings as $bad => $good ) {
		if ( $bad === $good ) continue;
		$count = 0;
		$new = str_replace( $bad, $good, $new, $count );
		if ( $count > 0 ) {
			$file_diffs[ $bad ] = ( $file_diffs[ $bad ] ?? 0 ) + $count;
		}
	}

	if ( $new !== $src ) {
		$file_changes++;
		$rel = str_replace( $root . '/', '', $file );
		echo "  $rel\n";
		foreach ( $file_diffs as $w => $c ) {
			echo "    $w → " . ( $substrings[ $w ] ?? $prefixes[ $w ] ?? $whole_words[ $w ] ?? '?' ) . "  ×$c\n";
			$total_changes += $c;
		}
		if ( $apply ) {
			file_put_contents( $file, $new );
		}
	}
}

echo "\n";
echo "Dateien mit Änderungen: $file_changes\n";
echo "Geplante/durchgeführte Ersetzungen: $total_changes\n";
echo $apply ? "GESCHRIEBEN.\n" : "(DRY-RUN — nichts geschrieben. --apply zum Ausführen.)\n";
