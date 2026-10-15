<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GenoRoots' own permissions, so a site can let someone edit the family
 * tree and nothing else.
 *
 * People and families share one set of post capabilities
 * (edit_genoroots_people, publish_genoroots_people, …, derived by
 * WordPress from TYPE). Groups and collections are managed with
 * manage_genoroots_groups and assigned with edit_genoroots_people.
 * Import, export and Settings stay with manage_options.
 *
 * Administrators and Editors get every GenoRoots capability, so nothing
 * changes for them. The "Genealogy editor" role gets only the tree: it
 * edits and publishes people and families, uploads portraits and puts
 * people into existing groups. It can't delete people, change groups or
 * reach anything else in the admin.
 */
class GenoRoots_Capabilities {

	/** Bump to re-run install() on active sites (it only ever adds capabilities). */
	const VERSION = 1;

	const ROLE          = 'genoroots_editor';
	const ROLE_NAME     = 'Genealogy editor';
	const MANAGE_GROUPS = 'manage_genoroots_groups';
	const EDIT          = 'edit_genoroots_people';

	/** capability_type of both post types. */
	const TYPE = array( 'genoroots_person', 'genoroots_people' );

	/** The genealogy editor's capabilities. */
	const EDITOR_CAPS = array(
		'read',
		'upload_files',
		'edit_genoroots_people',
		'edit_others_genoroots_people',
		'edit_published_genoroots_people',
		'edit_private_genoroots_people',
		'publish_genoroots_people',
		'read_private_genoroots_people',
	);

	/** Added on top for administrators and editors. */
	const FULL_CAPS = array(
		'delete_genoroots_people',
		'delete_others_genoroots_people',
		'delete_published_genoroots_people',
		'delete_private_genoroots_people',
		self::MANAGE_GROUPS,
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'translate_role_name' ), 10, 4 );
		add_filter( 'pre_insert_term', array( __CLASS__, 'guard_new_terms' ), 10, 2 );
	}

	/**
	 * The classic tag box creates any group name typed into it, as long as the
	 * user may assign groups. Only people who may manage groups create them.
	 * WP-CLI and other code running without a user are not affected.
	 */
	public static function guard_new_terms( $term, $taxonomy ) {
		$ours = array( GenoRoots_Family_Group_Taxonomy::TAXONOMY, GenoRoots_Collection_Taxonomy::TAXONOMY );
		if ( in_array( $taxonomy, $ours, true ) && get_current_user_id() && ! current_user_can( self::MANAGE_GROUPS ) ) {
			return new WP_Error( 'genoroots_cannot_create_group', __( 'Only administrators and editors can create groups.', 'geotour-genoroots' ) );
		}
		return $term;
	}

	/** register_taxonomy() capabilities for groups and collections. */
	public static function taxonomy_caps() {
		return array(
			'manage_terms' => self::MANAGE_GROUPS,
			'edit_terms'   => self::MANAGE_GROUPS,
			'delete_terms' => self::MANAGE_GROUPS,
			'assign_terms' => self::EDIT,
		);
	}

	public static function maybe_install() {
		if ( (int) get_option( 'genoroots_caps_version' ) !== self::VERSION ) {
			self::install();
			update_option( 'genoroots_caps_version', self::VERSION );
		}
	}

	/**
	 * Adds capabilities, never removes them, so whatever a site changed with
	 * a role plugin survives an update.
	 */
	public static function install() {
		foreach ( array( 'administrator', 'editor' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				foreach ( array_merge( self::EDITOR_CAPS, self::FULL_CAPS ) as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, self::ROLE_NAME, array_fill_keys( self::EDITOR_CAPS, true ) );
		} else {
			foreach ( self::EDITOR_CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/** Role names are translated in WordPress's own text domain; ours lives here. */
	public static function translate_role_name( $translation, $text, $context, $domain ) {
		if ( $context === 'User role' && $text === self::ROLE_NAME && $domain === 'default' ) {
			return __( 'Genealogy editor', 'geotour-genoroots' );
		}
		return $translation;
	}
}
