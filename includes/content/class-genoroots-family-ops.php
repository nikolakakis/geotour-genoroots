<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every edit to people and families, in one place: the admin Relations box
 * and the field editor both call these.
 *
 * Relations live in Family records (a couple + their children), as in
 * GEDCOM. A Family's spouse1/spouse2/children are the source of truth;
 * the people's _fams/_famc links are rebuilt from them after each change
 * (GenoRoots_Relationships). Nothing here deletes a person.
 *
 * Before a post is changed it is handed to GenoRoots_Changes::touch(), so
 * the change log can record its earlier state and undo it. Refusals are
 * thrown as GenoRoots_Edit_Error, which aborts the whole change.
 */
class GenoRoots_Family_Ops {

	const F_SPOUSE1  = 'field_genoroots_family_spouse1';
	const F_SPOUSE2  = 'field_genoroots_family_spouse2';
	const F_CHILDREN = 'field_genoroots_family_children';

	/** Person fields the editors may write: field name => sanitiser. */
	const PERSON_FIELDS = array(
		'sex'            => 'sex',
		'father_name'    => 'text',
		'nickname'       => 'text',
		'birth_date_raw' => 'text',
		'birth_place'    => 'text',
		'death_date_raw' => 'text',
		'death_place'    => 'text',
		'notes'          => 'textarea',
	);

	const FAMILY_FIELDS = array(
		'marriage_date_raw' => 'text',
		'marriage_place'    => 'text',
		'status'            => 'status',
	);

	// ------------------------------------------------------------------
	// People
	// ------------------------------------------------------------------

	/**
	 * A new published person with the next GenoRoots ID.
	 *
	 * @param array $fields     'name' plus any of PERSON_FIELDS.
	 * @param int[] $collections Collection term IDs to put them in.
	 * @return int The new person's post ID.
	 */
	public static function create_person( array $fields, array $collections = array() ) {
		$name = self::clean_name( $fields['name'] ?? '' );
		if ( $name === '' ) {
			throw new GenoRoots_Edit_Error( 'genoroots_name_required', __( 'A name is needed.', 'geotour-genoroots' ) );
		}
		$person_id = wp_insert_post( array(
			'post_type'   => GenoRoots_Person_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		), true );
		if ( is_wp_error( $person_id ) ) {
			throw new GenoRoots_Edit_Error( 'genoroots_insert_failed', $person_id->get_error_message() );
		}
		GenoRoots_Changes::created( $person_id );
		self::write_person_fields( $person_id, $fields + array( 'sex' => 'U' ) );
		GenoRoots_Ids::assign( $person_id, 'I' );
		if ( $collections ) {
			wp_set_object_terms( $person_id, array_map( 'intval', $collections ), GenoRoots_Collection_Taxonomy::TAXONOMY );
		}
		return (int) $person_id;
	}

	/** Changes only the fields given ('name' and/or PERSON_FIELDS). */
	public static function update_person( $person_id, array $fields ) {
		$person_id = self::person( $person_id );
		GenoRoots_Changes::touch( $person_id );

		if ( array_key_exists( 'name', $fields ) ) {
			$name = self::clean_name( $fields['name'] );
			if ( $name === '' ) {
				throw new GenoRoots_Edit_Error( 'genoroots_name_required', __( 'A name is needed.', 'geotour-genoroots' ) );
			}
			if ( $name !== get_post_field( 'post_title', $person_id, 'raw' ) ) {
				wp_update_post( array( 'ID' => $person_id, 'post_title' => $name ) );
				// Family titles show the couple's names.
				foreach ( self::fams( $person_id ) as $family_id ) {
					GenoRoots_Changes::touch( $family_id );
					self::retitle( $family_id );
				}
			}
		}
		self::write_person_fields( $person_id, $fields );
		return $person_id;
	}

	/** Sets the portrait (featured image) to an attachment. */
	public static function set_portrait( $person_id, $attachment_id ) {
		$person_id = self::person( $person_id );
		GenoRoots_Changes::touch( $person_id );
		set_post_thumbnail( $person_id, (int) $attachment_id );
		return $person_id;
	}

	// ------------------------------------------------------------------
	// Relations
	// ------------------------------------------------------------------

	/** Marries two people: finds the couple's family or creates it. Returns the family ID. */
	public static function add_spouse( $person_id, $spouse_id, array $marriage = array() ) {
		$person_id = self::person( $person_id );
		$spouse_id = self::person( $spouse_id );
		if ( $person_id === $spouse_id ) {
			throw new GenoRoots_Edit_Error( 'genoroots_same_person', __( 'A person cannot be linked to themselves.', 'geotour-genoroots' ) );
		}
		list( $husband, $wife ) = self::couple_order( $person_id, $spouse_id );
		$family_id = self::find_couple( $husband, $wife );
		if ( ! $family_id ) {
			$family_id = self::create_family( $husband, $wife, self::collections_of( $person_id ) );
		}
		if ( $marriage ) {
			self::update_family( $family_id, $marriage );
		}
		return $family_id;
	}

	/**
	 * Adds a child to one of the person's marriages ($family_id), or with
	 * the other parent unknown ($family_id = 0). A child has one family of
	 * birth: if they already have other parents, $confirm must be true and
	 * they move. Returns the family ID.
	 */
	public static function add_child( $person_id, $child_id, $family_id = 0, $confirm = false ) {
		$person_id = self::person( $person_id );
		$child_id  = self::person( $child_id );
		if ( $person_id === $child_id ) {
			throw new GenoRoots_Edit_Error( 'genoroots_same_person', __( 'A person cannot be linked to themselves.', 'geotour-genoroots' ) );
		}
		$family_id = (int) $family_id;
		if ( $family_id ) {
			if ( ! in_array( $family_id, self::fams( $person_id ), true ) ) {
				throw new GenoRoots_Edit_Error( 'genoroots_not_their_family', __( 'That marriage is not this person’s.', 'geotour-genoroots' ) );
			}
		} else {
			list( $husband, $wife ) = self::couple_order( $person_id, 0 );
			$family_id = self::find_couple( $husband, $wife ) ?: self::create_family( $husband, $wife, self::collections_of( $person_id ) );
		}
		self::move_child( $child_id, $family_id, $confirm );
		return $family_id;
	}

	/**
	 * Gives the person a parent: fills the free place (by the parent's sex)
	 * in their family of birth, or starts one. Refuses when that place is
	 * already taken. Returns the family ID.
	 */
	public static function add_parent( $person_id, $parent_id ) {
		$person_id = self::person( $person_id );
		$parent_id = self::person( $parent_id );
		if ( $person_id === $parent_id ) {
			throw new GenoRoots_Edit_Error( 'genoroots_same_person', __( 'A person cannot be linked to themselves.', 'geotour-genoroots' ) );
		}
		$famc = self::famc( $person_id );
		if ( ! $famc ) {
			list( $husband, $wife ) = self::couple_order( $parent_id, 0 );
			$family_id = self::create_family( $husband, $wife, self::collections_of( $person_id ) );
			self::move_child( $person_id, $family_id, true );
			return $family_id;
		}

		$father = (int) get_field( 'spouse1', $famc, false );
		$mother = (int) get_field( 'spouse2', $famc, false );
		if ( in_array( $parent_id, array( $father, $mother ), true ) ) {
			return $famc; // already a parent
		}
		$sex = self::sex( $parent_id );
		if ( $sex === 'M' ) {
			$slot = $father ? null : self::F_SPOUSE1;
		} elseif ( $sex === 'F' ) {
			$slot = $mother ? null : self::F_SPOUSE2;
		} else {
			$slot = ! $father ? self::F_SPOUSE1 : ( ! $mother ? self::F_SPOUSE2 : null );
		}
		if ( ! $slot ) {
			$taken = $sex === 'F' ? $mother : $father;
			throw new GenoRoots_Edit_Error(
				'genoroots_parent_taken',
				sprintf(
					/* translators: %s: the parent already recorded */
					__( 'This person already has that parent: %s. Correct it on that person first.', 'geotour-genoroots' ),
					self::title( $taken ?: $father ?: $mother )
				)
			);
		}
		self::touch_family( $famc );
		GenoRoots_Changes::touch( $parent_id );
		update_field( $slot, $parent_id, $famc );
		self::retitle( $famc );
		GenoRoots_Relationships::rebuild_backrefs_for_family( $famc );
		return $famc;
	}

	/**
	 * Puts $sibling_id in the person's family of birth. With no family of
	 * birth yet, starts one with no parents for the two of them.
	 */
	public static function add_sibling( $person_id, $sibling_id, $confirm = false ) {
		$person_id  = self::person( $person_id );
		$sibling_id = self::person( $sibling_id );
		if ( $person_id === $sibling_id ) {
			throw new GenoRoots_Edit_Error( 'genoroots_same_person', __( 'A person cannot be linked to themselves.', 'geotour-genoroots' ) );
		}
		$famc = self::famc( $person_id );
		if ( ! $famc ) {
			$famc = self::create_family( 0, 0, self::collections_of( $person_id ) );
			self::move_child( $person_id, $famc, true );
		}
		self::move_child( $sibling_id, $famc, $confirm );
		return $famc;
	}

	/**
	 * Removes one person from a family: a spouse leaves the couple, a child
	 * leaves the children. A family left with nobody goes to the trash.
	 */
	public static function unlink( $family_id, $person_id ) {
		$family_id = self::family( $family_id );
		$person_id = (int) $person_id;
		self::touch_family( $family_id );

		$spouse1  = (int) get_field( 'spouse1', $family_id, false );
		$spouse2  = (int) get_field( 'spouse2', $family_id, false );
		$children = self::children( $family_id );
		if ( $person_id === $spouse1 ) {
			update_field( self::F_SPOUSE1, '', $family_id );
		} elseif ( $person_id === $spouse2 ) {
			update_field( self::F_SPOUSE2, '', $family_id );
		} elseif ( in_array( $person_id, $children, true ) ) {
			update_field( self::F_CHILDREN, array_values( array_diff( $children, array( $person_id ) ) ), $family_id );
		} else {
			throw new GenoRoots_Edit_Error( 'genoroots_not_in_family', __( 'That person is not in this family.', 'geotour-genoroots' ) );
		}
		self::retitle( $family_id );
		GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
		self::trash_if_empty( $family_id );
		return $family_id;
	}

	/** Marriage date, place and certainty. */
	public static function update_family( $family_id, array $fields ) {
		$family_id = self::family( $family_id );
		GenoRoots_Changes::touch( $family_id );
		foreach ( self::FAMILY_FIELDS as $name => $type ) {
			if ( array_key_exists( $name, $fields ) ) {
				$value = self::sanitize( $fields[ $name ], $type );
				if ( $value !== null ) {
					update_field( 'field_genoroots_family_' . $name, $value, $family_id );
				}
			}
		}
		return $family_id;
	}

	/**
	 * Sets a couple (the admin box's spouse dropdown). $spouse_id = 0 leaves
	 * the person alone in the family.
	 */
	public static function set_spouse( $family_id, $person_id, $spouse_id ) {
		$family_id = self::family( $family_id );
		self::touch_family( $family_id );
		if ( $spouse_id ) {
			GenoRoots_Changes::touch( (int) $spouse_id );
		}
		$s1 = (int) get_field( 'spouse1', $family_id, false );
		update_field( $s1 === (int) $person_id ? self::F_SPOUSE2 : self::F_SPOUSE1, $spouse_id ? (int) $spouse_id : '', $family_id );
		self::retitle( $family_id );
		GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
		return $family_id;
	}

	/**
	 * Replaces the person's parents with this couple (the admin box's
	 * father/mother dropdowns). Siblings stay where they are.
	 */
	public static function set_parents( $person_id, $father_id, $mother_id ) {
		$person_id = self::person( $person_id );
		$famc      = self::famc( $person_id );
		if ( $famc ) {
			self::touch_family( $famc );
			update_field( self::F_CHILDREN, array_values( array_diff( self::children( $famc ), array( $person_id ) ) ), $famc );
			GenoRoots_Relationships::rebuild_backrefs_for_family( $famc );
			self::trash_if_empty( $famc );
		}
		if ( $father_id || $mother_id ) {
			$family_id = self::find_couple( (int) $father_id, (int) $mother_id ) ?: self::create_family( (int) $father_id, (int) $mother_id, self::collections_of( $person_id ) );
			self::move_child( $person_id, $family_id, true );
			return $family_id;
		}
		return 0;
	}

	/** Unlinks everyone from a family and puts it in the trash (the people stay). */
	public static function dissolve( $family_id ) {
		$family_id = self::family( $family_id );
		self::touch_family( $family_id );
		update_field( self::F_SPOUSE1, '', $family_id );
		update_field( self::F_SPOUSE2, '', $family_id );
		update_field( self::F_CHILDREN, array(), $family_id );
		GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
		wp_trash_post( $family_id );
	}

	// ------------------------------------------------------------------
	// Reading
	// ------------------------------------------------------------------

	/** The person's family of birth, or 0. */
	public static function famc( $person_id ) {
		return (int) get_post_meta( (int) $person_id, '_famc_family_id', true );
	}

	/** @return int[] The person's marriages. */
	public static function fams( $person_id ) {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( (int) $person_id, '_fams_family_id' ) ) ) ) );
	}

	/** @return int[] */
	public static function children( $family_id ) {
		return array_values( array_filter( array_map( 'intval', (array) get_field( 'children', (int) $family_id, false ) ) ) );
	}

	/** @return int[] Collection term IDs. */
	public static function collections_of( $post_id ) {
		$ids = wp_get_object_terms( (int) $post_id, GenoRoots_Collection_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	public static function sex( $person_id ) {
		$sex = (string) get_post_meta( (int) $person_id, 'sex', true );
		return in_array( $sex, array( 'M', 'F' ), true ) ? $sex : 'U';
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/** The family of exactly this couple (0 = nobody in that place), or 0. */
	public static function find_couple( $husband, $wife ) {
		if ( ! $husband && ! $wife ) {
			return 0;
		}
		$found = get_posts( array(
			'post_type'      => GenoRoots_Family_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => 'spouse1', 'value' => $husband ?: '', 'compare' => '=' ),
				array( 'key' => 'spouse2', 'value' => $wife ?: '', 'compare' => '=' ),
			),
		) );
		return $found ? (int) $found[0] : 0;
	}

	private static function create_family( $husband, $wife, array $collections = array() ) {
		$family_id = wp_insert_post( array(
			'post_type'   => GenoRoots_Family_CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => __( 'Family', 'geotour-genoroots' ),
		), true );
		if ( is_wp_error( $family_id ) ) {
			throw new GenoRoots_Edit_Error( 'genoroots_insert_failed', $family_id->get_error_message() );
		}
		GenoRoots_Changes::created( $family_id );
		foreach ( array_filter( array( $husband, $wife ) ) as $spouse ) {
			GenoRoots_Changes::touch( (int) $spouse );
		}
		update_field( self::F_SPOUSE1, $husband ?: '', $family_id );
		update_field( self::F_SPOUSE2, $wife ?: '', $family_id );
		update_field( self::F_CHILDREN, array(), $family_id );
		GenoRoots_Ids::assign( $family_id, 'F' );
		if ( $collections ) {
			wp_set_object_terms( $family_id, $collections, GenoRoots_Collection_Taxonomy::TAXONOMY );
		}
		self::retitle( $family_id );
		GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
		return (int) $family_id;
	}

	/** Takes the child out of any other family of birth, then adds them here. */
	private static function move_child( $child_id, $family_id, $confirm ) {
		$others = array_values( array_diff( array_map( 'intval', (array) get_post_meta( $child_id, '_famc_family_id' ) ), array( 0, $family_id ) ) );
		if ( $others && ! $confirm ) {
			throw new GenoRoots_Edit_Error(
				'genoroots_needs_confirm',
				sprintf(
					/* translators: 1: person, 2: their current parents */
					__( '%1$s is already recorded as the child of %2$s. Move them?', 'geotour-genoroots' ),
					self::title( $child_id ),
					self::parents_label( $others[0] )
				),
				array( 'needs_confirm' => true )
			);
		}
		GenoRoots_Changes::touch( $child_id );
		foreach ( $others as $old ) {
			self::touch_family( $old );
			update_field( self::F_CHILDREN, array_values( array_diff( self::children( $old ), array( $child_id ) ) ), $old );
			GenoRoots_Relationships::rebuild_backrefs_for_family( $old );
			self::trash_if_empty( $old );
		}
		self::touch_family( $family_id );
		$children = self::children( $family_id );
		if ( ! in_array( $child_id, $children, true ) ) {
			$children[] = $child_id;
			update_field( self::F_CHILDREN, $children, $family_id );
		}
		GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
	}

	/** Records a family and everyone in it before a change (their links change too). */
	private static function touch_family( $family_id ) {
		GenoRoots_Changes::touch( $family_id );
		$members = array_merge(
			array( (int) get_field( 'spouse1', $family_id, false ), (int) get_field( 'spouse2', $family_id, false ) ),
			self::children( $family_id )
		);
		foreach ( array_filter( $members ) as $member ) {
			GenoRoots_Changes::touch( $member );
		}
	}

	private static function trash_if_empty( $family_id ) {
		if ( ! get_field( 'spouse1', $family_id, false ) && ! get_field( 'spouse2', $family_id, false ) && ! self::children( $family_id ) ) {
			wp_trash_post( $family_id );
		}
	}

	public static function retitle( $family_id ) {
		$names = array_filter( array(
			self::title( (int) get_field( 'spouse1', $family_id, false ) ),
			self::title( (int) get_field( 'spouse2', $family_id, false ) ),
		) );
		wp_update_post( array(
			'ID'         => $family_id,
			'post_title' => $names ? implode( ' – ', $names ) : __( 'Family', 'geotour-genoroots' ),
		) );
	}

	/** Husband first, as GEDCOM has it: a woman goes to spouse2, anyone else to spouse1. */
	private static function couple_order( $a, $b ) {
		if ( self::sex( $a ) === 'F' || ( $b && self::sex( $b ) === 'M' ) ) {
			return array( (int) $b, (int) $a );
		}
		return array( (int) $a, (int) $b );
	}

	private static function write_person_fields( $person_id, array $fields ) {
		foreach ( self::PERSON_FIELDS as $name => $type ) {
			if ( array_key_exists( $name, $fields ) ) {
				$value = self::sanitize( $fields[ $name ], $type );
				if ( $value !== null ) {
					update_field( 'field_genoroots_person_' . $name, $value, $person_id );
				}
			}
		}
	}

	private static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'sex':
				$value = strtoupper( (string) $value );
				return in_array( $value, array( 'M', 'F', 'U' ), true ) ? $value : null;
			case 'status':
				return in_array( $value, array( 'confirmed', 'probable', 'claimed' ), true ) ? $value : null;
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private static function clean_name( $name ) {
		return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $name ) ) );
	}

	private static function person( $id ) {
		$id = (int) $id;
		if ( ! $id || get_post_type( $id ) !== GenoRoots_Person_CPT::POST_TYPE || get_post_status( $id ) === 'trash' ) {
			throw new GenoRoots_Edit_Error( 'genoroots_no_person', __( 'Person not found.', 'geotour-genoroots' ), array( 'status' => 404 ) );
		}
		return $id;
	}

	private static function family( $id ) {
		$id = (int) $id;
		if ( ! $id || get_post_type( $id ) !== GenoRoots_Family_CPT::POST_TYPE || get_post_status( $id ) === 'trash' ) {
			throw new GenoRoots_Edit_Error( 'genoroots_no_family', __( 'Family not found.', 'geotour-genoroots' ), array( 'status' => 404 ) );
		}
		return $id;
	}

	private static function title( $post_id ) {
		return $post_id ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}

	private static function parents_label( $family_id ) {
		$names = array_filter( array(
			self::title( (int) get_field( 'spouse1', $family_id, false ) ),
			self::title( (int) get_field( 'spouse2', $family_id, false ) ),
		) );
		return $names ? implode( ' & ', $names ) : __( 'unknown parents', 'geotour-genoroots' );
	}
}

/** A refused edit: aborts the change, and the REST API returns it as an error. */
class GenoRoots_Edit_Error extends Exception {

	public $error_code;
	public $data;

	public function __construct( $error_code, $message, array $data = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->data       = $data + array( 'status' => 409 );
	}

	public function to_wp_error() {
		return new WP_Error( $this->error_code, $this->getMessage(), $this->data );
	}
}
