<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenoRoots_Person_CPT {

	const POST_TYPE = 'genoroots_person';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$labels = array(
			'name'               => __( 'People', 'geotour-genoroots' ),
			'singular_name'      => __( 'Person', 'geotour-genoroots' ),
			'add_new_item'       => __( 'New person', 'geotour-genoroots' ),
			'edit_item'          => __( 'Edit person', 'geotour-genoroots' ),
			'new_item'           => __( 'New person', 'geotour-genoroots' ),
			'view_item'          => __( 'View person', 'geotour-genoroots' ),
			'search_items'       => __( 'Search people', 'geotour-genoroots' ),
			'not_found'          => __( 'No people found', 'geotour-genoroots' ),
			'not_found_in_trash' => __( 'No people found in Trash', 'geotour-genoroots' ),
			'all_items'          => __( 'All people', 'geotour-genoroots' ),
			'menu_name'          => __( 'GenoRoots', 'geotour-genoroots' ),
			'featured_image'     => __( 'Portrait', 'geotour-genoroots' ),
			'set_featured_image' => __( 'Set portrait', 'geotour-genoroots' ),
			'remove_featured_image' => __( 'Remove portrait', 'geotour-genoroots' ),
			'use_featured_image' => __( 'Use as portrait', 'geotour-genoroots' ),
		);

		register_post_type( self::POST_TYPE, array(
			'labels'              => $labels,
			// Public front-end templates come from includes/template-loader.php.
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-groups',
			'show_in_rest'        => true,
			'rest_base'           => 'genoroots-people',
			'supports'            => array( 'title', 'thumbnail' ), // thumbnail = portrait
			'has_archive'         => GenoRoots_Slugs::get( 'people' ),
			'rewrite'             => array( 'slug' => GenoRoots_Slugs::get( 'person' ), 'with_front' => false ),
			'capability_type'     => 'post',
		) );
	}
}
