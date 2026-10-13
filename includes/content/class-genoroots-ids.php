<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GenoRoots IDs ("I384" for people, "F386" for families): permanent
 * numbers, one shared sequence, used in photo keywords ("Name [384]").
 * People and families created in WordPress get the next free number —
 * above every number already used, including numbers seen in Piwigo tags
 * that aren't in the tree yet (recorded by the Piwigo sync).
 */
class GenoRoots_Ids {

	const RESERVED_OPTION = 'genoroots_reserved_max_id';

	public static function init() {
		// After ACF has saved the form (priority 10).
		add_action( 'acf/save_post', array( __CLASS__, 'ensure_person_id' ), 20 );
	}

	public static function next_number() {
		global $wpdb;
		$max = (int) $wpdb->get_var(
			"SELECT MAX(CAST(SUBSTRING(meta_value, 2) AS UNSIGNED)) FROM {$wpdb->postmeta}
			WHERE meta_key = 'gedcom_id' AND meta_value REGEXP '^[IF][0-9]+$'"
		);
		return max( $max, (int) get_option( self::RESERVED_OPTION, 0 ) ) + 1;
	}

	/** Remembers the highest number used elsewhere (e.g. Piwigo "[972]"). */
	public static function reserve( $number ) {
		if ( (int) $number > (int) get_option( self::RESERVED_OPTION, 0 ) ) {
			update_option( self::RESERVED_OPTION, (int) $number, false );
		}
	}

	public static function ensure_person_id( $post_id ) {
		if ( ! is_numeric( $post_id ) || get_post_type( $post_id ) !== GenoRoots_Person_CPT::POST_TYPE ) {
			return;
		}
		self::assign( (int) $post_id, 'I' );
	}

	/** Gives a post the next ID if it has none. Returns its ID. */
	public static function assign( $post_id, $prefix ) {
		$current = (string) get_post_meta( $post_id, 'gedcom_id', true );
		if ( $current !== '' ) {
			return $current;
		}
		$id = $prefix . self::next_number();
		update_post_meta( $post_id, 'gedcom_id', $id );
		update_post_meta( $post_id, '_gedcom_id', $prefix === 'I' ? 'field_genoroots_person_gedcom_id' : 'field_genoroots_family_gedcom_id' );
		return $id;
	}
}
