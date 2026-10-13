<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-curated "Family Groups" — flat tags a person can be assigned to,
 * independent of their actual genealogical relationships. Used both as a
 * browsing/landing aid on the archive page and, when passed to the /focus
 * REST endpoint, as an actual scope filter on what a chart shows.
 */
class GenoRoots_Family_Group_Taxonomy {

	const TAXONOMY = 'genoroots_family_group';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$labels = array(
			'name'          => __( 'Groups', 'geotour-genoroots' ),
			'singular_name' => __( 'Group', 'geotour-genoroots' ),
			'search_items'  => __( 'Search groups', 'geotour-genoroots' ),
			'all_items'     => __( 'All groups', 'geotour-genoroots' ),
			'edit_item'     => __( 'Edit group', 'geotour-genoroots' ),
			'update_item'   => __( 'Update group', 'geotour-genoroots' ),
			'add_new_item'  => __( 'New group', 'geotour-genoroots' ),
			'new_item_name' => __( 'New group name', 'geotour-genoroots' ),
			'menu_name'     => __( 'Groups', 'geotour-genoroots' ),
		);

		register_taxonomy( self::TAXONOMY, array( GenoRoots_Person_CPT::POST_TYPE ), array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => GenoRoots_Slugs::get( 'group' ), 'with_front' => false ),
		) );
	}
}
