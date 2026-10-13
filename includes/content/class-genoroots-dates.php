<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Readable dates, in the page's language.
 *
 * GEDCOM writes dates as "23 AUG 1864", "JUL 1930", "ABT 1780", "AFT 1935",
 * "BET 1900 AND 1910". Shown as stored they read like codes. This turns them
 * into "23 Αυγούστου 1864" / "23 August 1864", "περ. 1780" / "c. 1780"…
 * Month names come from WordPress's own locale (wp_date), so every site
 * language is covered without translating months here.
 *
 * Anything that isn't GEDCOM form (Kamilari's "Δεκέμβριος 1936", "1908")
 * is returned unchanged: dates are recorded as the sources give them.
 */
class GenoRoots_Dates {

	const MONTHS = array(
		'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
		'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
	);

	public static function format( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		$upper = strtoupper( preg_replace( '/\s+/', ' ', $raw ) );

		// Ranges and qualifiers wrap a plain date.
		if ( preg_match( '/^(?:BET|FROM) (.+) (?:AND|TO) (.+)$/', $upper, $m ) ) {
			$from = self::plain( $m[1] );
			$to   = self::plain( $m[2] );
			if ( $from !== null && $to !== null ) {
				/* translators: 1: earlier date, 2: later date */
				return sprintf( __( 'between %1$s and %2$s', 'geotour-genoroots' ), $from, $to );
			}
			return $raw;
		}
		if ( preg_match( '/^(ABT|ABOUT|CA|CIRCA|EST|CAL|AFT|BEF) (.+)$/', $upper, $m ) ) {
			$date = self::plain( $m[2] );
			if ( $date === null ) {
				return $raw;
			}
			switch ( $m[1] ) {
				case 'AFT':
					/* translators: %s: a date */
					return sprintf( __( 'after %s', 'geotour-genoroots' ), $date );
				case 'BEF':
					/* translators: %s: a date */
					return sprintf( __( 'before %s', 'geotour-genoroots' ), $date );
				default:
					/* translators: %s: a date ("circa") */
					return sprintf( __( 'c. %s', 'geotour-genoroots' ), $date );
			}
		}

		$date = self::plain( $upper );
		return $date === null ? $raw : $date;
	}

	/**
	 * "23 AUG 1864" | "AUG 1864" | "1864" in the current language, or null
	 * when the text is not one of those forms.
	 */
	private static function plain( $text ) {
		$utc = new DateTimeZone( 'UTC' ); // a calendar date, never shifted by the site's timezone

		if ( preg_match( '/^(\d{1,2}) ([A-Z]{3}) (\d{3,4})$/', $text, $m ) && isset( self::MONTHS[ $m[2] ] ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-n-j', sprintf( '%d-%d-%d', $m[3], self::MONTHS[ $m[2] ], $m[1] ), $utc );
			return $date ? wp_date( 'j F Y', $date->getTimestamp(), $utc ) : null;
		}
		if ( preg_match( '/^([A-Z]{3}) (\d{3,4})$/', $text, $m ) && isset( self::MONTHS[ $m[1] ] ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-n-j', sprintf( '%d-%d-1', $m[2], self::MONTHS[ $m[1] ] ), $utc );
			return $date ? wp_date( 'F Y', $date->getTimestamp(), $utc ) : null;
		}
		if ( preg_match( '/^\d{3,4}$/', $text ) ) {
			return $text;
		}
		return null;
	}
}
