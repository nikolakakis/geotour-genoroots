<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Σχέσεις" box on the person edit screen: parents, marriages (spouse,
 * date, place, children) and a new marriage, all edited from the person.
 *
 * Underneath, relations live in Family records (a couple + their
 * children), as in GEDCOM. This box creates, updates and links those
 * records, then rebuilds the family links the tree reads. Changing this
 * person's parents moves only this person; siblings are left alone.
 */
class GenoRoots_Relations_Admin {

	const NONCE = 'genoroots_relations';

	const F_SPOUSE1  = 'field_genoroots_family_spouse1';
	const F_SPOUSE2  = 'field_genoroots_family_spouse2';
	const F_CHILDREN = 'field_genoroots_family_children';
	const F_DATE     = 'field_genoroots_family_marriage_date_raw';
	const F_PLACE    = 'field_genoroots_family_marriage_place';

	public static function init() {
		add_action( 'add_meta_boxes_' . GenoRoots_Person_CPT::POST_TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . GenoRoots_Person_CPT::POST_TYPE, array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function add_box() {
		add_meta_box( 'genoroots-relations', __( 'Relations', 'geotour-genoroots' ), array( __CLASS__, 'render' ), null, 'normal', 'high' );
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	public static function render( $post ) {
		$id      = (int) $post->ID;
		$options = self::people_options( $id );
		$famc    = (int) get_post_meta( $id, '_famc_family_id', true );
		$father  = $famc ? (int) get_field( 'spouse1', $famc, false ) : 0;
		$mother  = $famc ? (int) get_field( 'spouse2', $famc, false ) : 0;
		$siblings = $famc ? array_diff( array_map( 'intval', (array) get_field( 'children', $famc, false ) ), array( $id ) ) : array();
		$fams    = array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_fams_family_id' ) ) ) );

		wp_nonce_field( self::NONCE, 'genoroots_relations_nonce' );
		?>
		<div class="gnr-rel">
			<h4><?php esc_html_e( 'Parents', 'geotour-genoroots' ); ?></h4>
			<div class="gnr-rel__grid">
				<label><?php esc_html_e( 'Father', 'geotour-genoroots' ); ?>
					<?php self::select( 'gnr_rel[father]', $father, $options ); ?>
				</label>
				<label><?php esc_html_e( 'Mother', 'geotour-genoroots' ); ?>
					<?php self::select( 'gnr_rel[mother]', $mother, $options ); ?>
				</label>
			</div>
			<p class="gnr-rel__note">
				<?php esc_html_e( 'Siblings:', 'geotour-genoroots' ); ?>
				<?php echo $siblings ? self::links( $siblings ) : esc_html__( 'none — siblings are people with the same parents', 'geotour-genoroots' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>

			<h4><?php esc_html_e( 'Marriages', 'geotour-genoroots' ); ?></h4>
			<?php if ( ! $fams ) : ?>
				<p class="gnr-rel__note"><?php esc_html_e( 'None yet.', 'geotour-genoroots' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $fams as $family_id ) : ?>
				<?php
				$s1       = (int) get_field( 'spouse1', $family_id, false );
				$s2       = (int) get_field( 'spouse2', $family_id, false );
				$spouse   = $s1 === $id ? $s2 : $s1;
				$children = array_map( 'intval', (array) get_field( 'children', $family_id, false ) );
				$base     = 'gnr_rel[marriages][' . $family_id . ']';
				?>
				<div class="gnr-rel__marriage">
					<div class="gnr-rel__grid gnr-rel__grid--3">
						<label><?php esc_html_e( 'Spouse', 'geotour-genoroots' ); ?>
							<?php self::select( $base . '[spouse]', $spouse, $options ); ?>
						</label>
						<label><?php esc_html_e( 'Marriage date', 'geotour-genoroots' ); ?>
							<input type="text" name="<?php echo esc_attr( $base . '[date]' ); ?>" value="<?php echo esc_attr( (string) get_field( 'marriage_date_raw', $family_id, false ) ); ?>">
						</label>
						<label><?php esc_html_e( 'Marriage place', 'geotour-genoroots' ); ?>
							<input type="text" name="<?php echo esc_attr( $base . '[place]' ); ?>" value="<?php echo esc_attr( (string) get_field( 'marriage_place', $family_id, false ) ); ?>">
						</label>
					</div>
					<div class="gnr-rel__children">
						<span class="gnr-rel__label"><?php echo esc_html_x( 'Children', 'of a marriage', 'geotour-genoroots' ); ?></span>
						<?php foreach ( $children as $child ) : ?>
							<label class="gnr-rel__child">
								<a href="<?php echo esc_url( get_edit_post_link( $child ) ); ?>"><?php echo esc_html( get_the_title( $child ) ); ?></a>
								<span class="gnr-rel__remove"><input type="checkbox" name="<?php echo esc_attr( $base . '[remove_children][]' ); ?>" value="<?php echo esc_attr( $child ); ?>"> <?php esc_html_e( 'remove', 'geotour-genoroots' ); ?></span>
							</label>
						<?php endforeach; ?>
						<label class="gnr-rel__add"><?php esc_html_e( 'Add a child', 'geotour-genoroots' ); ?>
							<?php self::select( $base . '[add_child]', 0, $options ); ?>
						</label>
					</div>
					<label class="gnr-rel__delete">
						<input type="checkbox" name="<?php echo esc_attr( $base . '[delete]' ); ?>" value="1">
						<?php esc_html_e( 'Delete this marriage (the people stay)', 'geotour-genoroots' ); ?>
					</label>
				</div>
			<?php endforeach; ?>

			<div class="gnr-rel__grid">
				<label><?php esc_html_e( 'New marriage with', 'geotour-genoroots' ); ?>
					<?php self::select( 'gnr_rel[new_spouse]', 0, $options ); ?>
				</label>
			</div>
			<p class="gnr-rel__note"><?php esc_html_e( 'Changes are saved when you press Update.', 'geotour-genoroots' ); ?></p>
		</div>
		<?php
	}

	/** One <option> list of everyone but this person, built once per page. */
	private static function people_options( $exclude ) {
		$people = get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$options = array();
		foreach ( $people as $person ) {
			if ( (int) $person->ID === (int) $exclude ) {
				continue;
			}
			$year   = preg_match( '/(\d{4})/', (string) get_post_meta( $person->ID, 'birth_date_raw', true ), $m ) ? ' (' . $m[1] . ')' : '';
			$gedcom = (string) get_post_meta( $person->ID, 'gedcom_id', true );
			$number = preg_match( '/^I(\d+)$/', $gedcom, $n ) ? ' [' . $n[1] . ']' : '';
			$options[ (int) $person->ID ] = $person->post_title . $year . $number;
		}
		return $options;
	}

	private static function select( $name, $selected, array $options ) {
		echo '<select name="' . esc_attr( $name ) . '" class="gnr-rel__select"><option value="0">—</option>';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $value, selected( (int) $selected, (int) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private static function links( array $ids ) {
		return implode( ', ', array_map( function ( $person_id ) {
			return '<a href="' . esc_url( get_edit_post_link( $person_id ) ) . '">' . esc_html( get_the_title( $person_id ) ) . '</a>';
		}, $ids ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== GenoRoots_Person_CPT::POST_TYPE || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		// Searchable dropdowns with the select2 that ACF ships, if available.
		if ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'select2' );
		}
		wp_add_inline_script( 'jquery-core', "jQuery(function($){ if ($.fn.select2) { $('.gnr-rel__select').select2({ width: '100%' }); } });" );
		wp_register_style( 'genoroots-relations', false, array(), GENOROOTS_VERSION );
		wp_enqueue_style( 'genoroots-relations' );
		wp_add_inline_style( 'genoroots-relations', '
			.gnr-rel h4 { margin: 16px 0 8px; font-size: 14px; }
			.gnr-rel h4:first-child { margin-top: 4px; }
			.gnr-rel__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
			.gnr-rel__grid--3 { grid-template-columns: 2fr 1fr 1fr; }
			.gnr-rel label { display: flex; flex-direction: column; gap: 4px; }
			.gnr-rel input[type=text], .gnr-rel select { width: 100%; }
			.gnr-rel__note { color: #646970; margin: 8px 0; }
			.gnr-rel__marriage { border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin-bottom: 12px; background: #fbfbfc; }
			.gnr-rel__children { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
			.gnr-rel__label { color: #646970; }
			.gnr-rel label.gnr-rel__child { flex-direction: row; justify-content: space-between; }
			.gnr-rel__remove, .gnr-rel__delete { color: #b32d2e; font-size: 12px; }
			.gnr-rel label.gnr-rel__delete { flex-direction: row; align-items: center; margin-top: 10px; }
		' );
	}

	// ------------------------------------------------------------------
	// Saving
	// ------------------------------------------------------------------

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['genoroots_relations_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['genoroots_relations_nonce'] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['gnr_rel'] ) ? wp_unslash( $_POST['gnr_rel'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ints/text sanitised below.
		$id    = (int) $post_id;

		// One logged change, so the whole box can be undone in one step.
		try {
			GenoRoots_Changes::run( array(
				'action'    => 'admin_relations',
				'person_id' => $id,
				/* translators: %s: person name */
				'summary'   => sprintf( __( 'Relations of %s edited in the admin', 'geotour-genoroots' ), get_the_title( $id ) ),
			), function () use ( $id, $input ) {
				self::apply( $id, $input );
				return array();
			} );
		} catch ( GenoRoots_Edit_Error $error ) {
			set_transient( 'genoroots_relations_error_' . get_current_user_id(), $error->getMessage(), 60 );
		}
	}

	private static function apply( $id, array $input ) {
		// Parents: find or create the family of that couple and move this person into it.
		$famc       = GenoRoots_Family_Ops::famc( $id );
		$old_father = $famc ? (int) get_field( 'spouse1', $famc, false ) : 0;
		$old_mother = $famc ? (int) get_field( 'spouse2', $famc, false ) : 0;
		$father     = (int) ( $input['father'] ?? 0 );
		$mother     = (int) ( $input['mother'] ?? 0 );
		if ( array( $father, $mother ) !== array( $old_father, $old_mother ) ) {
			GenoRoots_Family_Ops::set_parents( $id, $father, $mother );
		}

		// Existing marriages.
		$fams = GenoRoots_Family_Ops::fams( $id );
		foreach ( (array) ( $input['marriages'] ?? array() ) as $family_id => $marriage ) {
			$family_id = (int) $family_id;
			if ( ! in_array( $family_id, $fams, true ) ) {
				continue; // not one of this person's marriages
			}
			if ( ! empty( $marriage['delete'] ) ) {
				GenoRoots_Family_Ops::dissolve( $family_id );
				continue;
			}

			$s1         = (int) get_field( 'spouse1', $family_id, false );
			$old_spouse = $s1 === $id ? (int) get_field( 'spouse2', $family_id, false ) : $s1;
			$spouse     = (int) ( $marriage['spouse'] ?? 0 );
			if ( $spouse !== $old_spouse && $spouse !== $id ) {
				GenoRoots_Family_Ops::set_spouse( $family_id, $id, $spouse );
			}
			GenoRoots_Family_Ops::update_family( $family_id, array(
				'marriage_date_raw' => $marriage['date'] ?? '',
				'marriage_place'    => $marriage['place'] ?? '',
			) );

			foreach ( array_map( 'intval', (array) ( $marriage['remove_children'] ?? array() ) ) as $child ) {
				GenoRoots_Family_Ops::unlink( $family_id, $child );
			}
			$add = (int) ( $marriage['add_child'] ?? 0 );
			if ( $add && $add !== $id && get_post_status( $family_id ) !== 'trash' ) {
				GenoRoots_Family_Ops::add_child( $id, $add, $family_id, true );
			}
		}

		// New marriage.
		$new_spouse = (int) ( $input['new_spouse'] ?? 0 );
		if ( $new_spouse && $new_spouse !== $id ) {
			GenoRoots_Family_Ops::add_spouse( $id, $new_spouse );
		}
	}

	/** A refused edit from the last save, shown once. */
	public static function notice() {
		$key     = 'genoroots_relations_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
