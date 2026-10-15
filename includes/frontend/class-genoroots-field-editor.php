<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The field editor: [genoroots_field_editor] on any page gives signed-in
 * genealogy editors a phone-first editor for the tree (src/editor/).
 *
 * Genealogy editors are sent there instead of wp-admin, unless a site turns
 * that off (GenoRoots → Settings → Field editor). Administrators can limit
 * an editor to some collections on the user's profile.
 *
 * [genoroots_field_editor collection="venizelos"] starts in that collection.
 */
class GenoRoots_Field_Editor {

	const HANDLE = 'genoroots-editor';
	const OPTION = 'genoroots_editor';

	const DEFAULTS = array(
		'page_id'     => 0, // 0: the first page with the shortcode
		'editor_only' => 1, // genealogy editors use the field editor, not wp-admin
	);

	public static function init() {
		add_shortcode( 'genoroots_field_editor', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );
	}

	/** The editor page's address, or '' when no page has the editor. */
	public static function page_url() {
		$page_id = (int) self::settings()['page_id'];
		if ( ! $page_id || get_post_status( $page_id ) !== 'publish' ) {
			global $wpdb;
			$page_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID LIMIT 1",
				'%' . $wpdb->esc_like( '[genoroots_field_editor' ) . '%'
			) );
		}
		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Where "Edit" links point for the current user ("" when they may not
	 * edit, or no page has the editor). Add a person's ID: …#/p/123.
	 */
	public static function edit_url() {
		if ( ! is_user_logged_in() || ! current_user_can( GenoRoots_Capabilities::EDIT ) ) {
			return '';
		}
		$url = self::page_url();
		return $url ? $url . '#/p/' : '';
	}

	/** A genealogy editor and nothing more (administrators and editors keep wp-admin). */
	private static function is_field_editor( $user = null ) {
		$user = $user ?: wp_get_current_user();
		return $user instanceof WP_User && $user->exists()
			&& in_array( GenoRoots_Capabilities::ROLE, (array) $user->roles, true )
			&& ! user_can( $user, GenoRoots_Capabilities::MANAGE_GROUPS )
			&& ! user_can( $user, 'edit_posts' );
	}

	private static function editor_only() {
		return (bool) self::settings()['editor_only'] && self::page_url() !== '';
	}

	// ------------------------------------------------------------------
	// Access
	// ------------------------------------------------------------------

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ! self::is_field_editor() || ! self::editor_only() ) {
			return;
		}
		global $pagenow;
		// Their own profile (password) and the upload endpoints stay open.
		if ( in_array( $pagenow, array( 'admin-ajax.php', 'async-upload.php', 'admin-post.php', 'profile.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_field_editor( $user ) && self::editor_only() ) {
			$admin = admin_url();
			if ( $requested === '' || strpos( (string) $requested, $admin ) === 0 ) {
				return self::page_url();
			}
		}
		return $redirect_to;
	}

	public static function admin_bar( $show ) {
		return self::is_field_editor() && self::editor_only() ? false : $show;
	}

	/** The editor is personal: no page cache may keep it. */
	public static function no_cache() {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'genoroots_field_editor' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	// ------------------------------------------------------------------
	// The shortcode
	// ------------------------------------------------------------------

	public static function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'collection' => '' ), $atts, 'genoroots_field_editor' );

		if ( ! is_user_logged_in() ) {
			return '<div class="gnr-editor-gate"><p>' . esc_html__( 'Sign in to edit the family tree.', 'geotour-genoroots' ) . '</p><p><a class="gnr-editor-gate__button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'geotour-genoroots' ) . '</a></p></div>';
		}
		if ( ! current_user_can( GenoRoots_Capabilities::EDIT ) ) {
			return '<div class="gnr-editor-gate"><p>' . esc_html__( 'Your account cannot edit the family tree. Ask the site’s administrator for access.', 'geotour-genoroots' ) . '</p></div>';
		}

		$collection = GenoRoots_Collection_Taxonomy::term( (string) $atts['collection'] );
		self::enqueue( array(
			'collection' => $collection ? (int) $collection->term_id : 0,
		) );
		return '<div id="gnr-editor" class="gnr-editor" data-loading="1"><p class="gnr-editor__boot">' . esc_html__( 'Loading…', 'geotour-genoroots' ) . '</p></div>';
	}

	private static function enqueue( array $config ) {
		$js  = 'build/editor.js';
		$css = 'build/editor.css';
		wp_enqueue_script( self::HANDLE, GENOROOTS_PLUGIN_URL . $js, array(), (string) ( @filemtime( GENOROOTS_PLUGIN_DIR . $js ) ?: GENOROOTS_VERSION ), true );
		wp_enqueue_style( self::HANDLE, GENOROOTS_PLUGIN_URL . $css, array(), (string) ( @filemtime( GENOROOTS_PLUGIN_DIR . $css ) ?: GENOROOTS_VERSION ) );

		$user = wp_get_current_user();
		wp_localize_script( self::HANDLE, 'GenoRootsEditor', array(
			'root'        => esc_url_raw( rest_url( GenoRoots_Rest_Controller::NAMESPACE_ . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			// To renew the nonce when the page stays open past its lifetime.
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'user'        => array( 'id' => (int) $user->ID, 'name' => $user->display_name ),
			'canManage'   => current_user_can( GenoRoots_Capabilities::MANAGE_GROUPS ),
			'canUpload'   => current_user_can( 'upload_files' ),
			'collections' => GenoRoots_Editor_Controller::collections_for_user(),
			'restricted'  => GenoRoots_Editor_Controller::allowed_collections() !== null,
			'collection'  => $config['collection'],
			'treeUrl'     => get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE ),
			'adminUrl'    => current_user_can( 'edit_posts' ) || current_user_can( GenoRoots_Capabilities::MANAGE_GROUPS ) ? admin_url( 'post.php?action=edit&post=' ) : '',
			'logoutUrl'   => wp_logout_url( get_permalink() ),
			'i18n'        => self::strings(),
		) );
	}

	// ------------------------------------------------------------------
	// Profile: limit an editor to some collections
	// ------------------------------------------------------------------

	public static function profile_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) || ! in_array( GenoRoots_Capabilities::ROLE, (array) $user->roles, true ) ) {
			return;
		}
		$terms = get_terms( array( 'taxonomy' => GenoRoots_Collection_Taxonomy::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return; // one shared tree: nothing to limit
		}
		$chosen = array_map( 'intval', (array) get_user_meta( $user->ID, GenoRoots_Editor_Controller::USER_COLLECTIONS, true ) );
		wp_nonce_field( 'genoroots_user_collections', 'genoroots_user_collections_nonce' );
		?>
		<h2><?php esc_html_e( 'Family tree', 'geotour-genoroots' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'May edit', 'geotour-genoroots' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( $terms as $term ) : ?>
							<label style="display:block;margin-bottom:4px">
								<input type="checkbox" name="genoroots_collections[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( in_array( (int) $term->term_id, $chosen, true ) ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Leave all unticked to allow every collection.', 'geotour-genoroots' ); ?></p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['genoroots_user_collections_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['genoroots_user_collections_nonce'] ), 'genoroots_user_collections' ) ) {
			return;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $_POST['genoroots_collections'] ?? array() ) ) ) );
		if ( $ids ) {
			update_user_meta( $user_id, GenoRoots_Editor_Controller::USER_COLLECTIONS, $ids );
		} else {
			delete_user_meta( $user_id, GenoRoots_Editor_Controller::USER_COLLECTIONS );
		}
	}

	// ------------------------------------------------------------------
	// Strings for src/editor/
	// ------------------------------------------------------------------

	public static function strings() {
		return array(
			'title'           => __( 'Family tree editor', 'geotour-genoroots' ),
			'search'          => __( 'Search by name, father’s name or nickname…', 'geotour-genoroots' ),
			'backToSearch'    => __( 'Search', 'geotour-genoroots' ),
			'recent'          => __( 'Recently opened', 'geotour-genoroots' ),
			'noResults'       => __( 'Nobody found.', 'geotour-genoroots' ),
			'addPerson'       => __( '+ New person', 'geotour-genoroots' ),
			'allCollections'  => __( 'All collections', 'geotour-genoroots' ),
			'collection'      => __( 'Collection', 'geotour-genoroots' ),
			'back'            => __( 'Back', 'geotour-genoroots' ),
			'close'           => __( 'Close', 'geotour-genoroots' ),
			'cancel'          => __( 'Cancel', 'geotour-genoroots' ),
			'save'            => __( 'Save', 'geotour-genoroots' ),
			'saving'          => __( 'Saving…', 'geotour-genoroots' ),
			'saved'           => __( 'Saved', 'geotour-genoroots' ),
			'edit'            => __( 'Edit', 'geotour-genoroots' ),
			'history'         => __( 'History', 'geotour-genoroots' ),
			'allHistory'      => __( 'All recent changes', 'geotour-genoroots' ),
			'undo'            => __( 'Undo', 'geotour-genoroots' ),
			'redo'            => __( 'Redo', 'geotour-genoroots' ),
			'undone'          => __( 'Undone', 'geotour-genoroots' ),
			'undoConfirm'     => __( 'Undo this change?', 'geotour-genoroots' ),
			'noHistory'       => __( 'No changes yet.', 'geotour-genoroots' ),
			'viewPage'        => __( 'Public page ↗', 'geotour-genoroots' ),
			'openAdmin'       => __( 'Open in the admin ↗', 'geotour-genoroots' ),
			'signOut'         => __( 'Sign out', 'geotour-genoroots' ),
			'draft'           => __( 'draft', 'geotour-genoroots' ),

			'parents'         => __( 'Parents', 'geotour-genoroots' ),
			'siblings'        => __( 'Siblings', 'geotour-genoroots' ),
			'marriages'       => __( 'Spouses and children', 'geotour-genoroots' ),
			'children'        => _x( 'Children', 'of a marriage', 'geotour-genoroots' ),
			'unknownSpouse'   => __( 'Other parent unknown', 'geotour-genoroots' ),
			'noParents'       => __( 'No parents recorded.', 'geotour-genoroots' ),
			'noMarriages'     => __( 'No spouse recorded.', 'geotour-genoroots' ),
			/* translators: %s: names of parents */
			'childOf'         => __( 'child of %s', 'geotour-genoroots' ),
			/* translators: %s: names of spouses */
			'spouseOf'        => __( 'married to %s', 'geotour-genoroots' ),

			'addSpouse'       => __( '+ Spouse', 'geotour-genoroots' ),
			'addChild'        => __( '+ Child', 'geotour-genoroots' ),
			'addParent'       => __( '+ Parent', 'geotour-genoroots' ),
			'addSibling'      => __( '+ Sibling', 'geotour-genoroots' ),
			'editMarriage'    => __( 'Marriage details', 'geotour-genoroots' ),
			'removeLink'      => __( 'Remove link', 'geotour-genoroots' ),
			/* translators: 1: person, 2: family */
			'removeConfirm'   => __( 'Remove %1$s from this family? Nobody is deleted; only the link goes.', 'geotour-genoroots' ),
			'takePhoto'       => __( 'Take or choose a portrait', 'geotour-genoroots' ),
			'uploading'       => __( 'Uploading…', 'geotour-genoroots' ),
			'choosePhoto'     => __( 'Take or choose a photo', 'geotour-genoroots' ),
			'replacePhoto'    => __( 'Replace the photo', 'geotour-genoroots' ),
			'photoHint'       => __( 'Next you choose the square to keep. Only that square is saved, made small for the web.', 'geotour-genoroots' ),
			'photoUnreadable' => __( 'This photo could not be opened. Try another, or a JPEG.', 'geotour-genoroots' ),
			'cropHint'        => __( 'Drag to place the face in the circle; pinch or use the slider to zoom.', 'geotour-genoroots' ),
			'zoom'            => __( 'Zoom', 'geotour-genoroots' ),
			'rotate'          => __( 'Rotate', 'geotour-genoroots' ),

			/* translators: %s: person name */
			'spouseOfTitle'   => __( 'Spouse of %s', 'geotour-genoroots' ),
			/* translators: %s: person name */
			'childOfTitle'    => __( 'Child of %s', 'geotour-genoroots' ),
			/* translators: %s: person name */
			'parentOfTitle'   => __( 'Parent of %s', 'geotour-genoroots' ),
			/* translators: %s: person name */
			'siblingOfTitle'  => __( 'Sibling of %s', 'geotour-genoroots' ),
			'findExisting'    => __( 'First look for them in the tree', 'geotour-genoroots' ),
			'orCreate'        => __( 'Not in the tree?', 'geotour-genoroots' ),
			'createNew'       => __( 'Create a new person', 'geotour-genoroots' ),
			'whichMarriage'   => __( 'With which spouse?', 'geotour-genoroots' ),
			'duplicates'      => __( 'Is it one of these?', 'geotour-genoroots' ),
			'noneOfThese'     => __( 'No, create a new person', 'geotour-genoroots' ),
			'move'            => __( 'Yes, move them', 'geotour-genoroots' ),
			'newPerson'       => __( 'New person', 'geotour-genoroots' ),
			'editPerson'      => __( 'Edit person', 'geotour-genoroots' ),

			'name'            => __( 'Name', 'geotour-genoroots' ),
			'nameHint'        => __( 'Surname and given name, as in the rest of the tree', 'geotour-genoroots' ),
			'fatherName'      => __( 'Father’s name', 'geotour-genoroots' ),
			'nickname'        => __( 'Nickname', 'geotour-genoroots' ),
			/* translators: %s: a nickname, shown in quotes */
			'nicknameQuoted'  => _x( '“%s”', 'nickname', 'geotour-genoroots' ),
			'sex'             => __( 'Sex', 'geotour-genoroots' ),
			'male'            => __( 'Male', 'geotour-genoroots' ),
			'female'          => __( 'Female', 'geotour-genoroots' ),
			'unknown'         => __( 'Unknown', 'geotour-genoroots' ),
			'birth'           => __( 'Birth', 'geotour-genoroots' ),
			'death'           => __( 'Death', 'geotour-genoroots' ),
			'marriage'        => __( 'Marriage', 'geotour-genoroots' ),
			'date'            => __( 'Date', 'geotour-genoroots' ),
			'datePlaceholder' => __( 'e.g. 1908 or 12 MAR 1908', 'geotour-genoroots' ),
			'place'           => __( 'Place', 'geotour-genoroots' ),
			'notes'           => __( 'Notes', 'geotour-genoroots' ),
			'notesHint'       => __( 'What people remember, who told you, when', 'geotour-genoroots' ),
			'certainty'       => __( 'How sure', 'geotour-genoroots' ),
			'confirmed'       => __( 'Confirmed by sources', 'geotour-genoroots' ),
			'probable'        => __( 'Probable', 'geotour-genoroots' ),
			'claimed'         => __( 'Claimed (family tradition)', 'geotour-genoroots' ),
			'exact'           => __( 'Exact', 'geotour-genoroots' ),
			'about'           => __( 'About', 'geotour-genoroots' ),
			'before'          => __( 'Before', 'geotour-genoroots' ),
			'after'           => __( 'After', 'geotour-genoroots' ),
			'more'            => __( 'More details', 'geotour-genoroots' ),

			'unsent'          => array(
				_n( '%d change not sent', '%d changes not sent', 1, 'geotour-genoroots' ),
				_n( '%d change not sent', '%d changes not sent', 2, 'geotour-genoroots' ),
			),
			'offline'         => __( 'No connection. The change is kept on this phone.', 'geotour-genoroots' ),
			'retry'           => __( 'Send now', 'geotour-genoroots' ),
			'discard'         => __( 'Discard', 'geotour-genoroots' ),
			'discardConfirm'  => __( 'Throw this change away?', 'geotour-genoroots' ),
			'draftKept'       => __( 'Unsaved form restored', 'geotour-genoroots' ),
			'error'           => __( 'Something went wrong. Please try again.', 'geotour-genoroots' ),
			'signedOut'       => __( 'You were signed out. Sign in again; your change is kept.', 'geotour-genoroots' ),
			'conflict'        => __( 'Changed by someone else meanwhile. Here is the current version; your text is kept below.', 'geotour-genoroots' ),
			'yours'           => __( 'Your version', 'geotour-genoroots' ),
		);
	}
}
