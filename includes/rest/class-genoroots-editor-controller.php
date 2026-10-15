<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API of the field editor ([genoroots_field_editor]):
 * geotour-genoroots/v1/editor/…
 *
 * Signed-in users with edit_genoroots_people only (cookie + REST nonce).
 * Every write goes through GenoRoots_Family_Ops inside GenoRoots_Changes::run(),
 * so it is logged, can be undone, and a retried request (same request_id)
 * is answered without writing twice.
 *
 * Collections: a user can be limited to some collections (user meta
 * genoroots_collections). They then see and edit only people in those,
 * and new people join the collections of the person they are added to.
 * Administrators and editors, and anyone with no list, are not limited.
 */
class GenoRoots_Editor_Controller {

	const USER_COLLECTIONS = 'genoroots_collections';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns   = GenoRoots_Rest_Controller::NAMESPACE_;
		$can = array( __CLASS__, 'permission' ); // ids: the (?P<id>\d+) patterns accept digits only

		register_rest_route( $ns, '/editor/search', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'search' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/person', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_person' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/person/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_person' ),
				'permission_callback' => $can,
			),
			array(
				'methods'             => 'PATCH, POST',
				'callback'            => array( __CLASS__, 'update_person' ),
				'permission_callback' => $can,
			),
		) );
		register_rest_route( $ns, '/editor/person/(?P<id>\d+)/portrait', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_portrait' ),
			'permission_callback' => function () {
				return self::permission() && current_user_can( 'upload_files' );
			},
		) );
		register_rest_route( $ns, '/editor/relative', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_relative' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/family/(?P<id>\d+)', array(
			'methods'             => 'PATCH, POST',
			'callback'            => array( __CLASS__, 'update_family' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/unlink', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'unlink' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/changes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'changes' ),
			'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/editor/changes/(?P<id>\d+)/undo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'undo' ),
			'permission_callback' => $can,
		) );
	}

	public static function permission() {
		return current_user_can( GenoRoots_Capabilities::EDIT );
	}

	// ------------------------------------------------------------------
	// Collections the user may work in
	// ------------------------------------------------------------------

	/** @return int[]|null Allowed collection IDs, or null for "all". */
	public static function allowed_collections( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( user_can( $user_id, GenoRoots_Capabilities::MANAGE_GROUPS ) ) {
			return null;
		}
		$ids = array_filter( array_map( 'intval', (array) get_user_meta( $user_id, self::USER_COLLECTIONS, true ) ) );
		return $ids ? array_values( $ids ) : null;
	}

	/** Collections to show in the editor's selector. */
	public static function collections_for_user() {
		$allowed = self::allowed_collections();
		$terms   = get_terms( array(
			'taxonomy'   => GenoRoots_Collection_Taxonomy::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		) );
		$out = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( $allowed === null || in_array( (int) $term->term_id, $allowed, true ) ) {
				$out[] = array( 'id' => (int) $term->term_id, 'name' => $term->name );
			}
		}
		return $out;
	}

	private static function may_edit( $person_id ) {
		$allowed = self::allowed_collections();
		return $allowed === null || (bool) array_intersect( $allowed, GenoRoots_Family_Ops::collections_of( $person_id ) );
	}

	private static function check_person( $person_id ) {
		$person_id = (int) $person_id;
		if ( get_post_type( $person_id ) !== GenoRoots_Person_CPT::POST_TYPE || get_post_status( $person_id ) === 'trash' ) {
			throw new GenoRoots_Edit_Error( 'genoroots_no_person', __( 'Person not found.', 'geotour-genoroots' ), array( 'status' => 404 ) );
		}
		if ( ! self::may_edit( $person_id ) ) {
			throw new GenoRoots_Edit_Error( 'genoroots_not_allowed', __( 'You cannot edit people in this collection.', 'geotour-genoroots' ), array( 'status' => 403 ) );
		}
		return $person_id;
	}

	/** The collection chosen in the editor, if the user may use it. */
	private static function chosen_collection( $request ) {
		$id      = (int) $request->get_param( 'collection' );
		$allowed = self::allowed_collections();
		if ( $id && ( $allowed === null || in_array( $id, $allowed, true ) ) && term_exists( $id, GenoRoots_Collection_Taxonomy::TAXONOMY ) ) {
			return $id;
		}
		return 0;
	}

	// ------------------------------------------------------------------
	// Reading
	// ------------------------------------------------------------------

	public static function search( WP_REST_Request $request ) {
		$ids = GenoRoots_Search::people( (string) $request->get_param( 'q' ), array(
			'statuses'    => array( 'publish', 'draft', 'pending', 'private' ),
			'collection'  => self::chosen_collection( $request ),
			'collections' => self::allowed_collections() ?: array(),
			'limit'       => 25,
		) );
		return rest_ensure_response( array_map( array( __CLASS__, 'card' ), $ids ) );
	}

	public static function get_person( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			return self::person_view( self::check_person( $request['id'] ) );
		} );
	}

	public static function changes( WP_REST_Request $request ) {
		$person = (int) $request->get_param( 'person' );
		if ( $person ) {
			try {
				self::check_person( $person );
			} catch ( GenoRoots_Edit_Error $error ) {
				return $error->to_wp_error();
			}
		} elseif ( ! current_user_can( GenoRoots_Capabilities::MANAGE_GROUPS ) ) {
			// Without a person, genealogy editors see only their own changes.
			return rest_ensure_response( array_values( array_filter( GenoRoots_Changes::history( 0, 100 ), function ( $row ) {
				return $row['user_id'] === get_current_user_id();
			} ) ) );
		}
		return rest_ensure_response( GenoRoots_Changes::history( $person, (int) ( $request->get_param( 'limit' ) ?: 30 ), (int) $request->get_param( 'offset' ) ) );
	}

	/** A short card: enough to tell two people with the same name apart. */
	public static function card( $id ) {
		$id      = (int) $id;
		$spouses = array();
		foreach ( GenoRoots_Family_Ops::fams( $id ) as $family_id ) {
			foreach ( array( (int) get_field( 'spouse1', $family_id, false ), (int) get_field( 'spouse2', $family_id, false ) ) as $spouse ) {
				if ( $spouse && $spouse !== $id ) {
					$spouses[] = self::title( $spouse );
				}
			}
		}
		$famc    = GenoRoots_Family_Ops::famc( $id );
		$parents = $famc ? array_values( array_filter( array(
			self::title( (int) get_field( 'spouse1', $famc, false ) ),
			self::title( (int) get_field( 'spouse2', $famc, false ) ),
		) ) ) : array();

		return array(
			'id'          => $id,
			'gedcom_id'   => (string) get_post_meta( $id, 'gedcom_id', true ),
			'name'        => self::title( $id ),
			'nickname'    => (string) get_post_meta( $id, 'nickname', true ),
			'father_name' => (string) get_post_meta( $id, 'father_name', true ),
			'sex'         => GenoRoots_Family_Ops::sex( $id ),
			'years'       => self::years( $id ),
			'spouses'     => array_values( array_unique( $spouses ) ),
			'parents'     => $parents,
			'portrait'    => (string) get_the_post_thumbnail_url( $id, 'thumbnail' ),
			'draft'       => get_post_status( $id ) !== 'publish',
		);
	}

	/** One person with the fields to edit and their close family. */
	private static function person_view( $id ) {
		$fields = array( 'name' => self::title( $id ) );
		foreach ( array_keys( GenoRoots_Family_Ops::PERSON_FIELDS ) as $name ) {
			$fields[ $name ] = (string) get_post_meta( $id, $name, true );
		}
		$fields['sex'] = GenoRoots_Family_Ops::sex( $id );

		$famc     = GenoRoots_Family_Ops::famc( $id );
		$parents  = null;
		$siblings = array();
		if ( $famc ) {
			$father  = (int) get_field( 'spouse1', $famc, false );
			$mother  = (int) get_field( 'spouse2', $famc, false );
			$parents = array(
				'family_id' => $famc,
				'father'    => $father ? self::card( $father ) : null,
				'mother'    => $mother ? self::card( $mother ) : null,
			);
			foreach ( GenoRoots_Family_Ops::children( $famc ) as $child ) {
				if ( $child !== $id ) {
					$siblings[] = self::card( $child );
				}
			}
		}

		$marriages = array();
		foreach ( GenoRoots_Family_Ops::fams( $id ) as $family_id ) {
			$s1     = (int) get_field( 'spouse1', $family_id, false );
			$s2     = (int) get_field( 'spouse2', $family_id, false );
			$spouse = $s1 === $id ? $s2 : $s1;
			$family = array( 'family_id' => $family_id, 'spouse' => $spouse ? self::card( $spouse ) : null );
			foreach ( array_keys( GenoRoots_Family_Ops::FAMILY_FIELDS ) as $name ) {
				$family[ $name ] = (string) get_post_meta( $family_id, $name, true );
			}
			$family['version']  = self::version( $family_id, GenoRoots_Family_Ops::FAMILY_FIELDS );
			$family['children'] = array_map( array( __CLASS__, 'card' ), GenoRoots_Family_Ops::children( $family_id ) );
			$marriages[]        = $family;
		}

		return array(
			'person'    => self::card( $id ) + array(
				'fields'      => $fields,
				'version'     => self::version( $id, GenoRoots_Family_Ops::PERSON_FIELDS ),
				'url'         => get_post_status( $id ) === 'publish' ? get_permalink( $id ) : '',
				'collections' => GenoRoots_Family_Ops::collections_of( $id ),
				'portrait_l'  => (string) get_the_post_thumbnail_url( $id, 'medium' ),
			),
			'parents'   => $parents,
			'siblings'  => $siblings,
			'marriages' => $marriages,
		);
	}

	/**
	 * What an edit form was opened on: the title and the editable fields.
	 * Links changing meanwhile don't count as a conflict.
	 */
	private static function version( $post_id, array $fields ) {
		$values = array( get_post_field( 'post_title', $post_id, 'raw' ) );
		foreach ( array_keys( $fields ) as $name ) {
			$values[] = (string) get_post_meta( $post_id, $name, true );
		}
		return substr( md5( (string) wp_json_encode( $values ) ), 0, 12 );
	}

	// ------------------------------------------------------------------
	// Writing
	// ------------------------------------------------------------------

	public static function create_person( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$fields = (array) $request->get_param( 'fields' );
			if ( ! self::replayed( $request ) ) {
				self::duplicate_check( $fields, $request );
			}
			$collection = self::chosen_collection( $request );
			$allowed    = self::allowed_collections();
			if ( $allowed !== null && ! $collection ) {
				$collection = $allowed[0];
			}
			$result = GenoRoots_Changes::run( array(
				'action'     => 'create_person',
				'person_id'  => function ( $result ) {
					return $result['person'];
				},
				'request_id' => $request->get_param( 'request_id' ),
				'summary'    => function ( $result ) {
					/* translators: %s: person name */
					return sprintf( __( 'Added %s', 'geotour-genoroots' ), self::title( $result['person'] ) );
				},
			), function () use ( $fields, $collection ) {
				return array( 'person' => GenoRoots_Family_Ops::create_person( $fields, $collection ? array( $collection ) : array() ) );
			} );
			return $result + self::person_view( $result['person'] );
		} );
	}

	public static function update_person( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$id     = self::check_person( $request['id'] );
			$fields = (array) $request->get_param( 'fields' );
			$base   = (string) $request->get_param( 'version' );
			if ( $base !== '' && $base !== self::version( $id, GenoRoots_Family_Ops::PERSON_FIELDS ) && ! self::replayed( $request ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_edit_conflict', __( 'Someone changed this person while you were editing. Check their details and save again.', 'geotour-genoroots' ), array( 'current' => self::person_view( $id ) ) );
			}
			$result = GenoRoots_Changes::run( array(
				'action'     => 'update_person',
				'person_id'  => $id,
				'request_id' => $request->get_param( 'request_id' ),
				'summary'    => function () use ( $id ) {
					/* translators: %s: person name */
					return sprintf( __( 'Edited %s', 'geotour-genoroots' ), self::title( $id ) );
				},
			), function () use ( $id, $fields ) {
				GenoRoots_Family_Ops::update_person( $id, $fields );
				return array( 'person' => $id );
			} );
			return $result + self::person_view( $id );
		} );
	}

	/**
	 * Adds a relative: an existing person (existing_id) or a new one
	 * (new_person fields), as spouse, child, parent or sibling.
	 */
	public static function add_relative( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$anchor   = self::check_person( $request->get_param( 'person' ) );
			$relation = (string) $request->get_param( 'relation' );
			if ( ! in_array( $relation, array( 'spouse', 'child', 'parent', 'sibling' ), true ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_bad_relation', __( 'Unknown relation.', 'geotour-genoroots' ), array( 'status' => 400 ) );
			}
			$existing = (int) $request->get_param( 'existing_id' );
			$new      = (array) $request->get_param( 'new_person' );
			if ( $existing ) {
				self::check_person( $existing );
			} elseif ( ! self::replayed( $request ) ) {
				self::duplicate_check( $new, $request );
			}
			$family_id = (int) $request->get_param( 'family_id' );
			$marriage  = (array) $request->get_param( 'marriage' );
			$confirm   = (bool) $request->get_param( 'confirm' );

			$result = GenoRoots_Changes::run( array(
				'action'     => 'add_' . $relation,
				'person_id'  => $anchor,
				'request_id' => $request->get_param( 'request_id' ),
				'summary'    => function ( $result ) use ( $anchor, $relation ) {
					$labels = array(
						/* translators: 1: new relative, 2: person */
						'spouse'  => __( 'Added %1$s as spouse of %2$s', 'geotour-genoroots' ),
						/* translators: 1: new relative, 2: person */
						'child'   => __( 'Added %1$s as child of %2$s', 'geotour-genoroots' ),
						/* translators: 1: new relative, 2: person */
						'parent'  => __( 'Added %1$s as parent of %2$s', 'geotour-genoroots' ),
						/* translators: 1: new relative, 2: person */
						'sibling' => __( 'Added %1$s as sibling of %2$s', 'geotour-genoroots' ),
					);
					return sprintf( $labels[ $relation ], self::title( $result['relative'] ), self::title( $anchor ) );
				},
			), function () use ( $anchor, $relation, $existing, $new, $family_id, $marriage, $confirm ) {
				$relative = $existing ?: GenoRoots_Family_Ops::create_person( $new, GenoRoots_Family_Ops::collections_of( $anchor ) );
				switch ( $relation ) {
					case 'spouse':
						$family = GenoRoots_Family_Ops::add_spouse( $anchor, $relative, $marriage );
						break;
					case 'child':
						$family = GenoRoots_Family_Ops::add_child( $anchor, $relative, $family_id, $confirm );
						break;
					case 'parent':
						$family = GenoRoots_Family_Ops::add_parent( $anchor, $relative );
						break;
					default:
						$family = GenoRoots_Family_Ops::add_sibling( $anchor, $relative, $confirm );
				}
				return array( 'relative' => $relative, 'family' => $family );
			} );
			return $result + self::person_view( $anchor );
		} );
	}

	public static function update_family( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$family_id = (int) $request['id'];
			$anchor    = self::check_person( $request->get_param( 'person' ) );
			if ( ! in_array( $family_id, GenoRoots_Family_Ops::fams( $anchor ), true ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_not_their_family', __( 'That marriage is not this person’s.', 'geotour-genoroots' ) );
			}
			$base = (string) $request->get_param( 'version' );
			if ( $base !== '' && $base !== self::version( $family_id, GenoRoots_Family_Ops::FAMILY_FIELDS ) && ! self::replayed( $request ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_edit_conflict', __( 'Someone changed this marriage while you were editing. Check it and save again.', 'geotour-genoroots' ), array( 'current' => self::person_view( $anchor ) ) );
			}
			$fields = (array) $request->get_param( 'fields' );
			$result = GenoRoots_Changes::run( array(
				'action'     => 'update_family',
				'person_id'  => $anchor,
				'request_id' => $request->get_param( 'request_id' ),
				/* translators: %s: the couple */
				'summary'    => sprintf( __( 'Edited the marriage %s', 'geotour-genoroots' ), self::title( $family_id ) ),
			), function () use ( $family_id, $fields ) {
				GenoRoots_Family_Ops::update_family( $family_id, $fields );
				return array( 'family' => $family_id );
			} );
			return $result + self::person_view( $anchor );
		} );
	}

	public static function unlink( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$anchor    = self::check_person( $request->get_param( 'person' ) );
			$other     = (int) $request->get_param( 'other' );
			$family_id = (int) $request->get_param( 'family_id' );
			$own       = array_merge( GenoRoots_Family_Ops::fams( $anchor ), array( GenoRoots_Family_Ops::famc( $anchor ) ) );
			if ( ! in_array( $family_id, $own, true ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_not_their_family', __( 'That family is not this person’s.', 'geotour-genoroots' ) );
			}
			$result = GenoRoots_Changes::run( array(
				'action'     => 'unlink',
				'person_id'  => $anchor,
				'request_id' => $request->get_param( 'request_id' ),
				/* translators: 1: person removed, 2: family */
				'summary'    => sprintf( __( 'Removed %1$s from the family %2$s', 'geotour-genoroots' ), self::title( $other ), self::title( $family_id ) ),
			), function () use ( $family_id, $other ) {
				GenoRoots_Family_Ops::unlink( $family_id, $other );
				return array( 'family' => $family_id );
			} );
			return $result + self::person_view( $anchor );
		} );
	}

	public static function upload_portrait( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$id = self::check_person( $request['id'] );
			if ( self::replayed( $request ) ) {
				return GenoRoots_Changes::run( array( 'action' => 'portrait', 'request_id' => $request->get_param( 'request_id' ) ), '__return_empty_array' ) + self::person_view( $id );
			}
			$files = $request->get_file_params();
			if ( empty( $files['file'] ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_no_file', __( 'No photo was sent.', 'geotour-genoroots' ), array( 'status' => 400 ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$type = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
			if ( empty( $type['type'] ) || strpos( $type['type'], 'image/' ) !== 0 ) {
				throw new GenoRoots_Edit_Error( 'genoroots_not_an_image', __( 'That file is not a photo.', 'geotour-genoroots' ), array( 'status' => 400 ) );
			}
			$_FILES['genoroots_portrait'] = $files['file'];
			// The phone already sends the square, small: keep only the sizes
			// the tree (thumbnail) and person pages (medium) show.
			$sizes = function ( $sizes ) {
				return array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'medium' ) ) );
			};
			add_filter( 'intermediate_image_sizes_advanced', $sizes );
			$attachment = media_handle_upload( 'genoroots_portrait', $id, array( 'post_title' => self::title( $id ) ) );
			remove_filter( 'intermediate_image_sizes_advanced', $sizes );
			if ( is_wp_error( $attachment ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_upload_failed', $attachment->get_error_message(), array( 'status' => 400 ) );
			}
			$result = GenoRoots_Changes::run( array(
				'action'     => 'portrait',
				'person_id'  => $id,
				'request_id' => $request->get_param( 'request_id' ),
				/* translators: %s: person name */
				'summary'    => sprintf( __( 'New portrait for %s', 'geotour-genoroots' ), self::title( $id ) ),
			), function () use ( $id, $attachment ) {
				GenoRoots_Family_Ops::set_portrait( $id, $attachment );
				return array( 'attachment' => (int) $attachment );
			} );
			return $result + self::person_view( $id );
		} );
	}

	public static function undo( WP_REST_Request $request ) {
		return self::wrap( function () use ( $request ) {
			$change = GenoRoots_Changes::get( (int) $request['id'] );
			if ( ! $change || ! GenoRoots_Changes::can_undo( $change ) ) {
				if ( $change && self::replayed( $request ) ) {
					return GenoRoots_Changes::undo( (int) $request['id'], $request->get_param( 'request_id' ) );
				}
				throw new GenoRoots_Edit_Error( 'genoroots_cannot_undo', __( 'You cannot undo this change.', 'geotour-genoroots' ), array( 'status' => 403 ) );
			}
			$result = GenoRoots_Changes::undo( (int) $request['id'], $request->get_param( 'request_id' ) );
			return $result + ( $change['person_id'] && get_post_status( (int) $change['person_id'] ) && get_post_status( (int) $change['person_id'] ) !== 'trash'
				? self::person_view( (int) $change['person_id'] )
				: array() );
		} );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Before a new person is created: people with the same name (and
	 * father's name) who may be the same person. Skipped with confirm_new.
	 */
	private static function duplicate_check( array $fields, WP_REST_Request $request ) {
		if ( $request->get_param( 'confirm_new' ) ) {
			return;
		}
		$name = trim( (string) ( $fields['name'] ?? '' ) );
		if ( $name === '' ) {
			throw new GenoRoots_Edit_Error( 'genoroots_name_required', __( 'A name is needed.', 'geotour-genoroots' ), array( 'status' => 400 ) );
		}
		$matches = GenoRoots_Search::people( $name, array(
			'statuses'    => array( 'publish', 'draft', 'pending', 'private' ),
			'collections' => self::allowed_collections() ?: array(),
			'limit'       => 8,
		) );
		if ( $matches ) {
			throw new GenoRoots_Edit_Error(
				'genoroots_possible_duplicates',
				__( 'People with this name are already in the tree. Is it one of them?', 'geotour-genoroots' ),
				array( 'matches' => array_map( array( __CLASS__, 'card' ), $matches ) )
			);
		}
	}

	/** True when this request_id was already saved (a retry). */
	private static function replayed( WP_REST_Request $request ) {
		global $wpdb;
		$request_id = (string) $request->get_param( 'request_id' );
		return $request_id !== '' && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . GenoRoots_Changes::table() . ' WHERE request_id = %s', $request_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function wrap( callable $fn ) {
		try {
			$response = rest_ensure_response( $fn() );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( GenoRoots_Edit_Error $error ) {
			return $error->to_wp_error();
		}
	}

	private static function title( $post_id ) {
		return $post_id ? html_entity_decode( get_the_title( (int) $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}

	/** "1900–1975", "b. 1900", "d. 1975" or "". */
	private static function years( $id ) {
		$year  = function ( $raw ) {
			return preg_match( '/(\d{3,4})/', (string) $raw, $m ) ? $m[1] : '';
		};
		$birth = $year( get_post_meta( $id, 'birth_date_raw', true ) );
		$death = $year( get_post_meta( $id, 'death_date_raw', true ) );
		if ( $birth && $death ) {
			return $birth . '–' . $death;
		}
		if ( $birth ) {
			/* translators: %s: year of birth */
			return sprintf( __( 'b. %s', 'geotour-genoroots' ), $birth );
		}
		/* translators: %s: year of death */
		return $death ? sprintf( __( 'd. %s', 'geotour-genoroots' ), $death ) : '';
	}
}
