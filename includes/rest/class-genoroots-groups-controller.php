<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only REST API for the groups explorer.
 *
 * /groups   — every non-empty group with its member count, plus how many
 *             members each pair of groups shares (cached; one SQL self-join).
 * /members  — the people in ALL of the given groups (one group = its
 *             members, two = their overlap), paged and searchable.
 *
 * Groups are keyed by slug. Slugs go out decoded (readable Greek in URLs)
 * and come back in through sanitize_title(), which re-encodes them.
 *
 * Both take an optional ?collection=slug: only that collection's people are
 * counted and listed, so unrelated family networks never mix.
 */
class GenoRoots_Groups_Controller {

	const CACHE_KEY     = 'genoroots_groups_overview_v2'; // new suffix whenever the overview's shape changes, so a deploy never serves the old shape
	const CACHE_VERSION = 'genoroots_groups_cache_version'; // bumped to drop every cached overview at once
	const PER_PAGE  = 60;
	const PORTRAITS = 8; // per group, for the circle backgrounds

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Any membership or group change invalidates the cached overview.
		add_action( 'set_object_terms', array( __CLASS__, 'maybe_flush_on_terms' ), 10, 6 );
		$tax = GenoRoots_Family_Group_Taxonomy::TAXONOMY;
		add_action( "created_{$tax}", array( __CLASS__, 'flush' ) );
		add_action( "edited_{$tax}", array( __CLASS__, 'flush' ) );
		add_action( "delete_{$tax}", array( __CLASS__, 'flush' ) );
		$collections = GenoRoots_Collection_Taxonomy::TAXONOMY;
		add_action( "created_{$collections}", array( __CLASS__, 'flush' ) );
		add_action( "edited_{$collections}", array( __CLASS__, 'flush' ) );
		add_action( "delete_{$collections}", array( __CLASS__, 'flush' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_flush_on_status' ), 10, 3 );
		// Portraits shown inside the circles.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'maybe_flush_on_portrait' ), 10, 3 );
		}
	}

	public static function maybe_flush_on_portrait( $meta_id, $object_id, $meta_key ) {
		if ( $meta_key === '_thumbnail_id' && get_post_type( $object_id ) === GenoRoots_Person_CPT::POST_TYPE ) {
			self::flush();
		}
	}

	public static function flush() {
		update_option( self::CACHE_VERSION, (int) get_option( self::CACHE_VERSION, 0 ) + 1, false );
	}

	// Per locale: translation plugins may swap group names per language.
	private static function cache_key( $collection_slug ) {
		return self::CACHE_KEY . '_' . (int) get_option( self::CACHE_VERSION, 0 ) . '_' . md5( $collection_slug . '|' . determine_locale() );
	}

	public static function maybe_flush_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( in_array( $taxonomy, array( GenoRoots_Family_Group_Taxonomy::TAXONOMY, GenoRoots_Collection_Taxonomy::TAXONOMY ), true ) ) {
			self::flush();
		}
	}

	public static function maybe_flush_on_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post->post_type === GenoRoots_Person_CPT::POST_TYPE ) {
			self::flush();
		}
	}

	public static function register_routes() {
		register_rest_route( GenoRoots_Rest_Controller::NAMESPACE_, '/groups', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_groups' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'collection' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( GenoRoots_Rest_Controller::NAMESPACE_, '/members', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_members' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'groups' => array( 'type' => 'string', 'required' => true ),
				'q'          => array( 'type' => 'string' ),
				'page'       => array( 'type' => 'integer', 'default' => 1 ),
				'collection' => array( 'type' => 'string' ),
			),
		) );
	}

	public static function get_groups( $request ) {
		$collection = (string) $request->get_param( 'collection' );
		$term       = GenoRoots_Collection_Taxonomy::term( $collection );
		if ( $collection !== '' && ! $term ) {
			return new WP_Error( 'genoroots_unknown_collection', 'No such collection.', array( 'status' => 404 ) );
		}

		$key      = self::cache_key( $term ? $term->slug : '' );
		$overview = get_transient( $key );
		if ( $overview === false ) {
			$overview = self::build_overview( $term ? (int) $term->term_taxonomy_id : 0 );
			set_transient( $key, $overview, DAY_IN_SECONDS );
		}
		return rest_ensure_response( $overview );
	}

	/**
	 * Groups with member counts and pairwise overlaps: published people only,
	 * and only the collection's people when one is given.
	 */
	private static function build_overview( $collection_tt_id = 0 ) {
		global $wpdb;
		$tax = GenoRoots_Family_Group_Taxonomy::TAXONOMY;
		// Joined after posts "p" in both queries below.
		$in_collection = $collection_tt_id
			? $wpdb->prepare( " JOIN {$wpdb->term_relationships} c ON c.object_id = p.ID AND c.term_taxonomy_id = %d", $collection_tt_id )
			: '';

		// Members per group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aggregate, cached; $in_collection is prepared above.
		$counts = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.term_taxonomy_id AS tt, COUNT(*) AS members
			FROM {$wpdb->term_relationships} r
			JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = %s
			JOIN {$wpdb->posts} p ON p.ID = r.object_id AND p.post_type = %s AND p.post_status = 'publish'
			{$in_collection}
			GROUP BY r.term_taxonomy_id",
			$tax,
			GenoRoots_Person_CPT::POST_TYPE
		) );
		$members = array();
		foreach ( $counts as $row ) {
			$members[ (int) $row->tt ] = (int) $row->members;
		}

		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => true,
		) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$groups   = array();
		$slug_for = array(); // term_taxonomy_id -> decoded slug
		foreach ( $terms as $term ) {
			$tt = (int) $term->term_taxonomy_id;
			if ( empty( $members[ $tt ] ) ) {
				continue; // no published members (in this collection)
			}
			$slug             = urldecode( $term->slug );
			$slug_for[ $tt ] = $slug;
			$groups[]         = array(
				'slug'        => $slug,
				'name'        => $term->name,
				'count'       => $members[ $tt ],
				'description' => $term->description,
			);
		}

		// Members shared by each pair of groups.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate, cached above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.term_taxonomy_id AS ta, b.term_taxonomy_id AS tb, COUNT(*) AS shared
			FROM {$wpdb->term_relationships} a
			JOIN {$wpdb->term_relationships} b
				ON b.object_id = a.object_id AND a.term_taxonomy_id < b.term_taxonomy_id
			JOIN {$wpdb->term_taxonomy} xa ON xa.term_taxonomy_id = a.term_taxonomy_id AND xa.taxonomy = %s
			JOIN {$wpdb->term_taxonomy} xb ON xb.term_taxonomy_id = b.term_taxonomy_id AND xb.taxonomy = %s
			JOIN {$wpdb->posts} p ON p.ID = a.object_id AND p.post_type = %s AND p.post_status = 'publish'
			{$in_collection}
			GROUP BY a.term_taxonomy_id, b.term_taxonomy_id",
			$tax,
			$tax,
			GenoRoots_Person_CPT::POST_TYPE
		) );

		$overlaps = array();
		foreach ( $rows as $row ) {
			if ( isset( $slug_for[ (int) $row->ta ], $slug_for[ (int) $row->tb ] ) ) {
				$overlaps[] = array(
					'a'      => $slug_for[ (int) $row->ta ],
					'b'      => $slug_for[ (int) $row->tb ],
					'shared' => (int) $row->shared,
				);
			}
		}

		// A few members' portraits per group, for the circle backgrounds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- cached; $in_collection is prepared above.
		$photos = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.term_taxonomy_id AS tt, m.meta_value AS attachment
			FROM {$wpdb->term_relationships} r
			JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id AND x.taxonomy = %s
			JOIN {$wpdb->posts} p ON p.ID = r.object_id AND p.post_type = %s AND p.post_status = 'publish'
			{$in_collection}
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
			ORDER BY p.post_title",
			$tax,
			GenoRoots_Person_CPT::POST_TYPE
		) );
		$portraits = array(); // decoded slug -> image URLs
		foreach ( $photos as $row ) {
			$slug = $slug_for[ (int) $row->tt ] ?? null;
			if ( $slug !== null && count( $portraits[ $slug ] ?? array() ) < self::PORTRAITS ) {
				$url = wp_get_attachment_image_url( (int) $row->attachment, 'medium' );
				if ( $url ) {
					$portraits[ $slug ][] = $url;
				}
			}
		}
		foreach ( $groups as &$group ) {
			$group['portraits'] = $portraits[ $group['slug'] ] ?? array();
		}
		unset( $group );

		return array(
			'groups'   => $groups,
			'overlaps' => $overlaps,
		);
	}

	public static function get_members( $request ) {
		$tax   = GenoRoots_Family_Group_Taxonomy::TAXONOMY;
		$slugs = array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'groups' ) ) ) );
		if ( ! $slugs ) {
			return new WP_Error( 'genoroots_missing_groups', 'At least one group is required.', array( 'status' => 400 ) );
		}

		$tax_query = array( 'relation' => 'AND' );
		foreach ( $slugs as $slug ) {
			$tax_query[] = array(
				'taxonomy' => $tax,
				'field'    => 'slug',
				'terms'    => sanitize_title( $slug ),
			);
		}

		$args = array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => $tax_query, // phpcs:ignore -- the whole point of this endpoint.
		);
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( $q !== '' ) {
			$args['s'] = $q;
		}
		$in_collection = GenoRoots_Collection_Taxonomy::tax_clause( (string) $request->get_param( 'collection' ) );
		if ( $in_collection ) {
			$args['tax_query'][] = $in_collection; // joined by the same AND as the groups
		}

		$query = new WP_Query( $args );
		$ids   = wp_list_pluck( $query->posts, 'ID' );
		update_object_term_cache( $ids, GenoRoots_Person_CPT::POST_TYPE );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'         => $post->ID,
				'name'       => GenoRoots_Rest_Controller::plain_title( $post ),
				'sex'        => (string) ( get_field( 'sex', $post->ID ) ?: 'U' ),
				'birth_date' => (string) get_field( 'birth_date_raw', $post->ID ),
				'death_date' => (string) get_field( 'death_date_raw', $post->ID ),
				'url'        => get_permalink( $post ),
				'portrait'   => (string) get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
				'groups'     => self::group_slugs_of( $post->ID ),
			);
		}

		return rest_ensure_response( array(
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'items' => $items,
		) );
	}

	/** Decoded slugs of a person's groups (uses the object term cache). */
	public static function group_slugs_of( $person_id ) {
		$terms = get_the_terms( $person_id, GenoRoots_Family_Group_Taxonomy::TAXONOMY );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_values( array_map( function ( $term ) {
			return urldecode( $term->slug );
		}, $terms ) );
	}
}
