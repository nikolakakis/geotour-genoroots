<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a Family's spouse/children ACF fields (the single source of truth)
 * mirrored onto each linked Person as back-reference meta, so "this
 * person's family/spouse/parents" is a direct meta lookup instead of a
 * reverse scan over every Family post.
 */
class GenoRoots_Relationships {

	public static function init() {
		add_action( 'acf/save_post', array( __CLASS__, 'maybe_sync_family' ), 20 );
	}

	public static function maybe_sync_family( $post_id ) {
		if ( ! is_int( $post_id ) && ! ctype_digit( (string) $post_id ) ) {
			return; // acf/save_post also fires for options pages etc. with non-numeric IDs.
		}
		if ( get_post_type( $post_id ) !== GenoRoots_Family_CPT::POST_TYPE ) {
			return;
		}
		self::rebuild_backrefs_for_family( (int) $post_id );
	}

	/**
	 * Rebuilds `_fams_family_id` / `_famc_family_id` meta on every Person
	 * currently or previously linked to this Family, from the Family's own
	 * spouse1/spouse2/children fields.
	 */
	public static function rebuild_backrefs_for_family( $family_id ) {
		$previously_linked = get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => '_fams_family_id',
					'value' => $family_id,
				),
				array(
					'key'   => '_famc_family_id',
					'value' => $family_id,
				),
			),
		) );
		foreach ( $previously_linked as $person_id ) {
			delete_post_meta( $person_id, '_fams_family_id', $family_id );
			delete_post_meta( $person_id, '_famc_family_id', $family_id );
		}

		$spouse1  = (int) get_field( 'spouse1', $family_id );
		$spouse2  = (int) get_field( 'spouse2', $family_id );
		$children = (array) get_field( 'children', $family_id );

		foreach ( array_filter( array( $spouse1, $spouse2 ) ) as $spouse_id ) {
			add_post_meta( $spouse_id, '_fams_family_id', $family_id );
		}
		foreach ( array_filter( $children ) as $child_id ) {
			add_post_meta( $child_id, '_famc_family_id', $family_id );
		}
	}
}
