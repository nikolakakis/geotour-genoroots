<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collections: separate family networks on one site (e.g. "Venizelos" and
 * "Kornaros" on GeoTour), each with its own page, explorer, search and list.
 *
 * Assigned by hand (or by `wp genoroots import --collection=`), never worked
 * out from the links: one wrong marriage would merge two dynasties. A person
 * may belong to more than one collection. With no collections at all (as on
 * Kamilari) everything behaves as one shared tree.
 *
 * Also attached to families, so imports can keep each collection's records
 * apart; only people are listed and counted.
 */
class GenoRoots_Collection_Taxonomy {

	const TAXONOMY = 'genoroots_collection';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$labels = array(
			'name'          => __( 'Collections', 'geotour-genoroots' ),
			'singular_name' => __( 'Collection', 'geotour-genoroots' ),
			'search_items'  => __( 'Search collections', 'geotour-genoroots' ),
			'all_items'     => __( 'All collections', 'geotour-genoroots' ),
			'edit_item'     => __( 'Edit collection', 'geotour-genoroots' ),
			'update_item'   => __( 'Update collection', 'geotour-genoroots' ),
			'add_new_item'  => __( 'New collection', 'geotour-genoroots' ),
			'new_item_name' => __( 'New collection name', 'geotour-genoroots' ),
			'menu_name'     => __( 'Collections', 'geotour-genoroots' ),
		);

		register_taxonomy( self::TAXONOMY, array( GenoRoots_Person_CPT::POST_TYPE, GenoRoots_Family_CPT::POST_TYPE ), array(
			'labels'            => $labels,
			// Hierarchical only for the checkbox box on the edit screen; no nesting is used.
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'capabilities'      => GenoRoots_Capabilities::taxonomy_caps(),
			'rewrite'           => array( 'slug' => GenoRoots_Slugs::get( 'collection' ), 'with_front' => false, 'hierarchical' => false ),
		) );
	}

	/** The collection term for a (decoded or encoded) slug, or null. */
	public static function term( $slug ) {
		$slug = trim( (string) $slug );
		if ( $slug === '' ) {
			return null;
		}
		$term = get_term_by( 'slug', sanitize_title( $slug ), self::TAXONOMY );
		return $term instanceof WP_Term ? $term : null;
	}

	/** A WP_Query tax_query clause limiting to one collection, or null for "all". */
	public static function tax_clause( $slug ) {
		$term = self::term( $slug );
		return $term ? array(
			'taxonomy' => self::TAXONOMY,
			'field'    => 'term_id',
			'terms'    => $term->term_id,
		) : null;
	}

	/** Collections that have people, by name. */
	public static function listed() {
		$terms = get_terms( array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/** A person's first collection (where their "full tree" lives), or null. */
	public static function of_person( $person_id ) {
		$terms = get_the_terms( $person_id, self::TAXONOMY );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	}

	/** Published people in a collection. */
	public static function people_count( WP_Term $term ) {
		$query = new WP_Query( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array( self::tax_clause( urldecode( $term->slug ) ) ), // phpcs:ignore
		) );
		return (int) $query->found_posts;
	}
}
