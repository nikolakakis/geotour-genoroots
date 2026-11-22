<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder people: someone, or a run of generations, that nobody has
 * recorded, so two known people can still be linked. "Alexios → (an
 * unnamed son) → Leon", "a founder → (about 10 generations) → a family
 * that claims him", or "a shared unknown ancestor" of two relatives whose
 * kinship is not known.
 *
 * A placeholder is an ordinary person post with a flag and three details:
 * how many generations it stands for (min–max), what the link rests on
 * (the basis) and a short note. How sure the link is stays where it always
 * was, on the families (Certainty: confirmed / probable / claimed).
 *
 * Placeholders are drawn in the tree (the /focus walk goes through them)
 * but they are not people anyone can count: they are left out of group
 * sizes, member lists, search, person pages, sitemaps and every other
 * public list. Their name may be empty; it is then shown as "Unknown" in
 * the page's language.
 */
class GenoRoots_Placeholder {

	/** Post meta (ACF field names). */
	const FLAG  = 'genoroots_placeholder';
	const MIN   = 'placeholder_min';
	const MAX   = 'placeholder_max';
	const BASIS = 'placeholder_basis';
	const NOTE  = 'placeholder_note';

	/** The most generations one placeholder may stand for. */
	const SPAN_LIMIT = 99;

	const BASES = array( 'records', 'tradition', 'forged_charter', 'coins', 'surname', 'other' );

	public static function init() {
		add_filter( 'the_title', array( __CLASS__, 'title' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'keep_out_of_public_queries' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'rest_' . GenoRoots_Person_CPT::POST_TYPE . '_query', array( __CLASS__, 'rest_query' ), 10, 2 );

		// Sitemaps: WordPress core, Yoast SEO, Rank Math.
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_query' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'sitemap_exclude_ids' ) );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_entry' ), 10, 3 );
	}

	// ------------------------------------------------------------------
	// Reading
	// ------------------------------------------------------------------

	public static function is( $post_id ) {
		return $post_id && (string) get_post_meta( (int) $post_id, self::FLAG, true ) === '1';
	}

	/** Basis key => label. */
	public static function bases() {
		return array(
			'records'        => __( 'Records', 'geotour-genoroots' ),
			'tradition'      => __( 'Family tradition', 'geotour-genoroots' ),
			'forged_charter' => __( 'Forged charter', 'geotour-genoroots' ),
			'coins'          => __( 'Coins', 'geotour-genoroots' ),
			'surname'        => __( 'Surname only', 'geotour-genoroots' ),
			'other'          => __( 'Other', 'geotour-genoroots' ),
		);
	}

	public static function basis_label( $basis ) {
		$bases = self::bases();
		return $bases[ $basis ] ?? '';
	}

	/** [ min, max ]: at least 1, max never below min, an empty max = min. */
	public static function span( $min, $max ) {
		$min = max( 1, min( self::SPAN_LIMIT, (int) $min ) );
		$max = (int) $max ? max( $min, min( self::SPAN_LIMIT, (int) $max ) ) : $min;
		return array( $min, $max );
	}

	/** "1 generation", "about 10 generations", "8–12 generations". */
	public static function span_label( $min, $max ) {
		list( $min, $max ) = self::span( $min, $max );
		if ( $min === $max ) {
			/* translators: %d: number of generations nobody has recorded */
			return sprintf( _n( '%d generation', '%d generations', $min, 'geotour-genoroots' ), $min );
		}
		/* translators: 1: fewest, 2: most generations nobody has recorded */
		return sprintf( __( '%1$d–%2$d generations', 'geotour-genoroots' ), $min, $max );
	}

	/**
	 * Everything the tree and the editor show about a placeholder, or null
	 * for a real person.
	 *
	 * @return array{min:int,max:int,span:string,basis:string,basis_label:string,note:string}|null
	 */
	public static function info( $post_id ) {
		if ( ! self::is( $post_id ) ) {
			return null;
		}
		list( $min, $max ) = self::span( get_post_meta( $post_id, self::MIN, true ), get_post_meta( $post_id, self::MAX, true ) );
		$basis = (string) get_post_meta( $post_id, self::BASIS, true );
		$basis = in_array( $basis, self::BASES, true ) ? $basis : '';
		return array(
			'min'         => $min,
			'max'         => $max,
			'span'        => self::span_label( $min, $max ),
			'basis'       => $basis,
			'basis_label' => self::basis_label( $basis ),
			'note'        => (string) get_post_meta( $post_id, self::NOTE, true ),
		);
	}

	/** One line for lists: "Unknown · 10 generations · Forged charter". */
	public static function summary( $post_id ) {
		$info = self::info( $post_id );
		if ( ! $info ) {
			return '';
		}
		return implode( ' · ', array_filter( array( $info['span'], $info['basis_label'] ) ) );
	}

	/** An empty name is shown as "Unknown", in the page's language. */
	public static function title( $title, $post_id = 0 ) {
		if ( $title === '' && $post_id && get_post_type( $post_id ) === GenoRoots_Person_CPT::POST_TYPE && self::is( $post_id ) ) {
			return _x( 'Unknown', 'name of a person nobody recorded', 'geotour-genoroots' );
		}
		return $title;
	}

	/** @return int[] Every placeholder, any status. */
	public static function ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
			self::FLAG
		) ) );
	}

	// ------------------------------------------------------------------
	// Leaving placeholders out
	// ------------------------------------------------------------------

	/**
	 * SQL condition: the post in $id_column is not a placeholder. For the
	 * hand-written counting queries.
	 */
	public static function sql_not( $id_column ) {
		global $wpdb;
		return $wpdb->prepare(
			"NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} gnr_ph WHERE gnr_ph.post_id = {$id_column} AND gnr_ph.meta_key = %s AND gnr_ph.meta_value = '1')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a column name from our own code
			self::FLAG
		);
	}

	/** meta_query clause for WP_Query / get_posts: real people only. */
	public static function meta_clause() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::FLAG,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::FLAG,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/** Adds meta_clause() to WP_Query arguments, next to any meta_query already there. */
	public static function exclude( array $args ) {
		$existing = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'] = $existing // phpcs:ignore WordPress.DB.SlowDBQuery
			? array( 'relation' => 'AND', $existing, self::meta_clause() )
			: array( self::meta_clause() );
		return $args;
	}

	/**
	 * Front-end main queries (person pages, the archive, collection and
	 * group pages, site search, feeds) never return a placeholder, so a
	 * placeholder has no public page: its address is a 404.
	 */
	public static function keep_out_of_public_queries( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		$types   = (array) $query->get( 'post_type' );
		$ours    = in_array( GenoRoots_Person_CPT::POST_TYPE, $types, true );
		$listing = $query->is_search() || $query->is_feed()
			|| $query->is_tax( array( GenoRoots_Collection_Taxonomy::TAXONOMY, GenoRoots_Family_Group_Taxonomy::TAXONOMY ) );
		if ( ! $ours && ! $listing ) {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, self::meta_clause() ) : array( self::meta_clause() ) );
	}

	/** WordPress's own REST listing of people (/wp/v2/genoroots-people): real people only, except when editing. */
	public static function rest_query( $args, $request ) {
		if ( $request instanceof WP_REST_Request && $request->get_param( 'context' ) === 'edit' && current_user_can( GenoRoots_Capabilities::EDIT ) ) {
			return $args;
		}
		return self::exclude( $args );
	}

	public static function sitemap_query( $args, $post_type ) {
		return $post_type === GenoRoots_Person_CPT::POST_TYPE ? self::exclude( $args ) : $args;
	}

	public static function sitemap_exclude_ids( $ids ) {
		return array_merge( (array) $ids, self::ids() );
	}

	public static function rank_math_entry( $url, $type, $object ) {
		return ( $type === 'post' && $object instanceof WP_Post && $object->post_type === GenoRoots_Person_CPT::POST_TYPE && self::is( $object->ID ) ) ? false : $url;
	}

	/** "— Placeholder" after the name in the admin list of people. */
	public static function post_state( $states, $post ) {
		if ( $post instanceof WP_Post && $post->post_type === GenoRoots_Person_CPT::POST_TYPE && self::is( $post->ID ) ) {
			$states['genoroots_placeholder'] = self::summary( $post->ID )
				/* translators: %s: e.g. "10 generations · Forged charter" */
				? sprintf( __( 'Placeholder: %s', 'geotour-genoroots' ), self::summary( $post->ID ) )
				: __( 'Placeholder', 'geotour-genoroots' );
		}
		return $states;
	}
}
