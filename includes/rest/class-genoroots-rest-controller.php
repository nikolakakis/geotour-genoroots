<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public read-only REST API powering the focus-centered family chart.
 *
 * Two bounded endpoints, not one whole-population dump: /focus walks a
 * neighborhood outward from one person (ancestors, siblings, spouses,
 * descendants, all depth-limited), and /search does server-side name
 * lookup. Both stay fast regardless of total population size, unlike the
 * old /tree endpoint this replaces.
 */
class GenoRoots_Rest_Controller {

	const NAMESPACE_ = 'geotour-genoroots/v1';
	const YEAR_GAP    = 25; // assumed years between a parent and a child, for interpolation only.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NAMESPACE_, '/focus', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_focus' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'person' => array( 'type' => 'integer' ),
				'group'  => array( 'type' => 'string' ),
				'up'     => array( 'type' => 'integer', 'default' => 2 ),
				'down'   => array( 'type' => 'integer', 'default' => 2 ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_search' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'q'          => array( 'type' => 'string' ),
				'collection' => array( 'type' => 'string' ),
			),
		) );
	}

	/**
	 * The (translated) title as plain text. get_the_title() returns HTML
	 * with typographic entities (&#8220;), which the cards would escape again.
	 */
	public static function plain_title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function get_search( $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( $q === '' ) {
			return rest_ensure_response( array() );
		}

		$collection = GenoRoots_Collection_Taxonomy::term( (string) $request->get_param( 'collection' ) );
		$ids        = GenoRoots_Search::people( $q, array( 'collection' => $collection ? $collection->term_id : 0 ) );

		$results = array();
		foreach ( $ids as $id ) {
			$results[] = array(
				'id'          => $id,
				'name'        => self::plain_title( $id ),
				'name_latin'  => (string) get_field( 'name_latin', $id ),
				'nickname'    => (string) get_field( 'nickname', $id ),
				'father_name' => (string) get_field( 'father_name', $id ),
				'url'         => get_permalink( $id ),
			);
		}
		return rest_ensure_response( $results );
	}

	public static function get_focus( $request ) {
		$group_slug = (string) $request->get_param( 'group' );
		$up         = max( 0, (int) $request->get_param( 'up' ) );
		$down       = max( 0, (int) $request->get_param( 'down' ) );
		$person_id  = (int) $request->get_param( 'person' );

		$group_term = $group_slug !== ''
			? get_term_by( 'slug', $group_slug, GenoRoots_Family_Group_Taxonomy::TAXONOMY )
			: null;

		if ( ! $person_id ) {
			if ( ! $group_term ) {
				return new WP_Error( 'genoroots_missing_focus', 'A person or group parameter is required.', array( 'status' => 400 ) );
			}
			$person_id = self::pick_default_person_in_group( $group_term->term_id );
			if ( ! $person_id ) {
				return new WP_Error( 'genoroots_empty_group', 'This group has no members.', array( 'status' => 404 ) );
			}
		}

		if ( get_post_type( $person_id ) !== GenoRoots_Person_CPT::POST_TYPE || get_post_status( $person_id ) !== 'publish' ) {
			return new WP_Error( 'genoroots_not_found', 'Person not found.', array( 'status' => 404 ) );
		}

		$people    = array();
		$families  = array();

		$people[ $person_id ] = self::get_person_data( $person_id, 'focus', 0 );
		self::walk_ancestors( $person_id, $up, $people, $families, 0 );
		self::add_siblings( $person_id, $people, $families );
		self::walk_descendants( $person_id, $down, $people, $families, 0 );

		// `group` only picks the starting person. Cutting the neighbourhood
		// down to group members dropped in-laws from other families and
		// broke the tree's shape.

		self::interpolate_years( $people, $families );

		// Group memberships, so the chart can highlight the explorer's selection.
		update_object_term_cache( array_keys( $people ), GenoRoots_Person_CPT::POST_TYPE );
		foreach ( $people as $id => &$person ) {
			$person['groups'] = GenoRoots_Groups_Controller::group_slugs_of( $id );
		}
		unset( $person );

		return rest_ensure_response( array(
			'focus_id' => $person_id,
			'people'   => array_values( $people ),
			'families' => array_values( $families ),
		) );
	}

	private static function walk_ancestors( $person_id, $remaining_up, array &$people, array &$families, $generation ) {
		if ( $remaining_up <= 0 ) {
			return;
		}
		$famc = get_post_meta( $person_id, '_famc_family_id' );
		if ( empty( $famc ) ) {
			return;
		}
		$family_id = (int) $famc[0];
		$family    = self::register_family( $family_id, 'ancestor-union', $families );
		if ( ! $family ) {
			return;
		}

		foreach ( array( $family['spouse1'], $family['spouse2'] ) as $parent_id ) {
			if ( ! $parent_id ) {
				continue;
			}
			if ( ! isset( $people[ $parent_id ] ) ) {
				$people[ $parent_id ] = self::get_person_data( $parent_id, 'ancestor', $generation - 1 );
			}
			self::walk_ancestors( $parent_id, $remaining_up - 1, $people, $families, $generation - 1 );
		}
	}

	private static function add_siblings( $person_id, array &$people, array &$families ) {
		$famc = get_post_meta( $person_id, '_famc_family_id' );
		if ( empty( $famc ) ) {
			return;
		}
		$family_id = (int) $famc[0];
		$family    = self::register_family( $family_id, 'ancestor-union', $families );
		if ( ! $family ) {
			return;
		}

		foreach ( $family['children'] as $sibling_id ) {
			if ( $sibling_id === (int) $person_id || isset( $people[ $sibling_id ] ) ) {
				continue;
			}
			$people[ $sibling_id ] = self::get_person_data( $sibling_id, 'sibling', 0 );
		}
	}

	private static function walk_descendants( $person_id, $remaining_down, array &$people, array &$families, $generation ) {
		$fams = array_map( 'intval', (array) get_post_meta( $person_id, '_fams_family_id' ) );

		foreach ( $fams as $family_id ) {
			$role   = $generation === 0 ? 'focus-union' : 'descendant-union';
			$family = self::register_family( $family_id, $role, $families );
			if ( ! $family ) {
				continue;
			}

			$spouse_id = ( (int) $family['spouse1'] === (int) $person_id ) ? $family['spouse2'] : $family['spouse1'];
			if ( $spouse_id && ! isset( $people[ $spouse_id ] ) ) {
				$people[ $spouse_id ] = self::get_person_data( $spouse_id, 'spouse', $generation );
			}

			if ( $remaining_down <= 0 ) {
				continue; // Show the union + spouse, but don't recurse into children.
			}

			foreach ( $family['children'] as $child_id ) {
				if ( ! isset( $people[ $child_id ] ) ) {
					$people[ $child_id ] = self::get_person_data( $child_id, 'descendant', $generation + 1 );
				}
				self::walk_descendants( $child_id, $remaining_down - 1, $people, $families, $generation + 1 );
			}
		}
	}

	/**
	 * Fills in a birth year for every person who doesn't have one, by
	 * propagating from dated relatives (parent -> +25y, child -> -25y,
	 * spouse -> same year) until stable, falling back to the dataset's
	 * average known year for anyone still unreachable. A heuristic, not
	 * genealogical fact -- callers get `year_is_estimated` to render it
	 * differently.
	 */
	private static function interpolate_years( array &$people, array $families ) {
		$known = array();
		foreach ( $people as $id => $person ) {
			$year = self::extract_year( $person['birth_date'] );
			if ( $year ) {
				$known[ $id ] = $year;
			}
		}

		$edges = array();
		foreach ( $families as $family ) {
			$spouses = array_filter( array( $family['spouse1'], $family['spouse2'] ) );
			$spouses = array_values( $spouses );
			if ( count( $spouses ) === 2 ) {
				$edges[ $spouses[0] ][] = array( $spouses[1], 0 );
				$edges[ $spouses[1] ][] = array( $spouses[0], 0 );
			}
			foreach ( $spouses as $parent_id ) {
				foreach ( $family['children'] as $child_id ) {
					$edges[ $child_id ][]  = array( $parent_id, self::YEAR_GAP );
					$edges[ $parent_id ][] = array( $child_id, -self::YEAR_GAP );
				}
			}
		}

		$estimated = $known;
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$changed = false;
			foreach ( $people as $id => $person ) {
				if ( isset( $estimated[ $id ] ) || empty( $edges[ $id ] ) ) {
					continue;
				}
				foreach ( $edges[ $id ] as $edge ) {
					list( $other_id, $offset ) = $edge;
					if ( isset( $estimated[ $other_id ] ) ) {
						$estimated[ $id ] = $estimated[ $other_id ] + $offset;
						$changed          = true;
						break;
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}

		$fallback = $known ? (int) round( array_sum( $known ) / count( $known ) ) : (int) gmdate( 'Y' ) - 60;

		foreach ( $people as $id => &$person ) {
			if ( isset( $estimated[ $id ] ) ) {
				$person['estimated_birth_year'] = $estimated[ $id ];
				$person['year_is_estimated']    = ! isset( $known[ $id ] );
			} else {
				$person['estimated_birth_year'] = $fallback;
				$person['year_is_estimated']    = true;
			}
		}
		unset( $person );
	}

	private static function pick_default_person_in_group( $term_id ) {
		$posts = get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore -- simple single-term lookup, no perf concern at this scale.
				array(
					'taxonomy' => GenoRoots_Family_Group_Taxonomy::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		) );
		if ( empty( $posts ) ) {
			return 0;
		}

		$best_id   = 0;
		$best_year = null;
		foreach ( $posts as $id ) {
			$year = self::extract_year( get_field( 'birth_date_raw', $id ) );
			if ( $year !== null && ( $best_year === null || $year < $best_year ) ) {
				$best_year = $year;
				$best_id   = $id;
			}
		}
		if ( $best_id ) {
			return $best_id;
		}

		usort( $posts, function ( $a, $b ) {
			return strcmp( get_the_title( $a ), get_the_title( $b ) );
		} );
		return $posts[0];
	}

	private static function extract_year( $raw ) {
		if ( preg_match( '/(\d{4})/', (string) $raw, $matches ) ) {
			return (int) $matches[1];
		}
		return null;
	}

	private static function get_person_data( $id, $role, $generation ) {
		$related = GenoRoots_Related::of_person( $id );
		return array(
			'id'             => $id,
			'name'           => self::plain_title( $id ),
			'name_latin'     => (string) get_field( 'name_latin', $id ),
			'nickname'       => (string) get_field( 'nickname', $id ),
			'father_name'    => (string) get_field( 'father_name', $id ),
			'sex'            => (string) ( get_field( 'sex', $id ) ?: 'U' ),
			'birth_date'     => (string) get_field( 'birth_date_raw', $id ),
			'birth_place'    => (string) get_field( 'birth_place', $id ),
			'birth_place_en' => (string) get_field( 'birth_place_en', $id ),
			'death_date'     => (string) get_field( 'death_date_raw', $id ),
			'death_place'    => (string) get_field( 'death_place', $id ),
			'death_place_en' => (string) get_field( 'death_place_en', $id ),
			'url'            => get_permalink( $id ),
			// Photo archive (Piwigo tag page), set by the Piwigo sync.
			'photos_url'     => (string) get_field( 'photo_archive_url', $id ),
			'photo_count'    => (int) get_field( 'piwigo_photo_count', $id ),
			'portrait'       => (string) get_the_post_thumbnail_url( $id, 'thumbnail' ),
			// "Related article" (e.g. a biography), in the request's language.
			'related_url'    => $related ? $related['url'] : '',
			'related_title'  => $related ? $related['title'] : '',
			'role'           => $role,
			'generation'     => $generation,
			// Family links, so the chart can tell which relatives exist but
			// haven't been fetched yet and mark that branch as expandable.
			'famc'           => (int) get_post_meta( $id, '_famc_family_id', true ) ?: null,
			'fams'           => array_map( 'intval', (array) get_post_meta( $id, '_fams_family_id' ) ),
		);
	}

	private static function get_family_data( $family_id ) {
		if ( get_post_type( $family_id ) !== GenoRoots_Family_CPT::POST_TYPE ) {
			return null;
		}
		// Draft people are left out, so the tree walks never reach them.
		$spouse1 = GenoRoots_Relatives::published( array( (int) get_field( 'spouse1', $family_id ) ) );
		$spouse2 = GenoRoots_Relatives::published( array( (int) get_field( 'spouse2', $family_id ) ) );
		return array(
			'id'                => $family_id,
			'spouse1'           => $spouse1 ? $spouse1[0] : null,
			'spouse2'           => $spouse2 ? $spouse2[0] : null,
			'children'          => GenoRoots_Relatives::published( (array) get_field( 'children', $family_id ) ),
			'marriage_date'     => (string) get_field( 'marriage_date_raw', $family_id ),
			'marriage_place'    => (string) get_field( 'marriage_place', $family_id ),
			'marriage_place_en' => (string) get_field( 'marriage_place_en', $family_id ),
		);
	}

	/** Registers a family in the accumulator the first time it's seen; later calls are no-ops (first role wins). */
	private static function register_family( $family_id, $role, array &$families ) {
		if ( isset( $families[ $family_id ] ) ) {
			return $families[ $family_id ];
		}
		$family = self::get_family_data( $family_id );
		if ( ! $family ) {
			return null;
		}
		$family['role']        = $role;
		$families[ $family_id ] = $family;
		return $family;
	}
}
