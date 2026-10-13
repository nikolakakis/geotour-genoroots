<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the plugin ship its own front-end templates instead of requiring
 * the active theme to provide matching files — same pattern as the sister
 * geotour-events plugin's template-loader.php.
 */

add_filter( 'single_template', 'genoroots_single_template' );
function genoroots_single_template( $original_template ) {
	if ( is_singular( GenoRoots_Person_CPT::POST_TYPE ) ) {
		$custom_template = GENOROOTS_PLUGIN_DIR . 'templates/single-genoroots_person.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}
	}
	return $original_template;
}

add_filter( 'taxonomy_template', 'genoroots_collection_template' );
function genoroots_collection_template( $original_template ) {
	if ( is_tax( GenoRoots_Collection_Taxonomy::TAXONOMY ) ) {
		$custom_template = GENOROOTS_PLUGIN_DIR . 'templates/taxonomy-genoroots_collection.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}
	}
	return $original_template;
}

add_filter( 'archive_template', 'genoroots_archive_template' );
function genoroots_archive_template( $original_template ) {
	if ( is_post_type_archive( GenoRoots_Person_CPT::POST_TYPE ) ) {
		$custom_template = GENOROOTS_PLUGIN_DIR . 'templates/archive-genoroots_person.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}
	}
	return $original_template;
}
