<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Person-centric relative lookups, built from the Family CPT's fields
 * (the single source of truth — see relationships.php) rather than
 * duplicating that logic in every template that needs "this person's
 * parents/spouse/children."
 */
class GenoRoots_Relatives {

	/**
	 * Only people visitors may see. A draft relative stays out of every
	 * public page and API response: no name, no link.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function published( array $ids ) {
		return array_values( array_filter( array_map( 'intval', $ids ), function ( $id ) {
			return $id && get_post_status( $id ) === 'publish';
		} ) );
	}

	/** @return int[] */
	public static function get_parents( $person_id ) {
		$famc = get_post_meta( $person_id, '_famc_family_id' );
		if ( empty( $famc ) ) {
			return array();
		}
		$family_id = (int) $famc[0];
		return self::published( array(
			(int) get_field( 'spouse1', $family_id ),
			(int) get_field( 'spouse2', $family_id ),
		) );
	}

	/** @return int[] */
	public static function get_spouses( $person_id ) {
		$person_id = (int) $person_id;
		$fams      = array_map( 'intval', (array) get_post_meta( $person_id, '_fams_family_id' ) );
		$spouses   = array();
		foreach ( $fams as $family_id ) {
			foreach ( array( (int) get_field( 'spouse1', $family_id ), (int) get_field( 'spouse2', $family_id ) ) as $spouse_id ) {
				if ( $spouse_id && $spouse_id !== $person_id ) {
					$spouses[] = $spouse_id;
				}
			}
		}
		return self::published( array_unique( $spouses ) );
	}

	/** @return int[] */
	public static function get_children( $person_id ) {
		$fams     = array_map( 'intval', (array) get_post_meta( $person_id, '_fams_family_id' ) );
		$children = array();
		foreach ( $fams as $family_id ) {
			$children = array_merge( $children, array_map( 'intval', (array) get_field( 'children', $family_id ) ) );
		}
		return self::published( array_unique( array_filter( $children ) ) );
	}
}
