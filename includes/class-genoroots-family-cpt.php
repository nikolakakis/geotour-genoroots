<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenoRoots_Family_CPT {

	const POST_TYPE = 'genoroots_family';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$labels = array(
			'name'               => __( 'Families', 'geotour-genoroots' ),
			'singular_name'      => __( 'Family', 'geotour-genoroots' ),
			'add_new_item'       => __( 'New family', 'geotour-genoroots' ),
			'edit_item'          => __( 'Edit family', 'geotour-genoroots' ),
			'new_item'           => __( 'New family', 'geotour-genoroots' ),
			'view_item'          => __( 'View family', 'geotour-genoroots' ),
			'search_items'       => __( 'Search families', 'geotour-genoroots' ),
			'not_found'          => __( 'No families found', 'geotour-genoroots' ),
			'not_found_in_trash' => __( 'No families found in Trash', 'geotour-genoroots' ),
			'all_items'          => __( 'All families', 'geotour-genoroots' ),
		);

		register_post_type( self::POST_TYPE, array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=' . GenoRoots_Person_CPT::POST_TYPE,
			'show_in_rest'    => true,
			'rest_base'       => 'genoroots-families',
			'supports'        => array( 'title' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'capability_type' => GenoRoots_Capabilities::TYPE,
			'map_meta_cap'    => true,
		) );
	}
}
