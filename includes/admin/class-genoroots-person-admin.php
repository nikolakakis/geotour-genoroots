<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Person edit screen: a side box with what an editor needs at a glance —
 * the GenoRoots ID and ready-to-copy photo keyword, the Piwigo link, and
 * the person's relatives and families as links. Read-only.
 */
class GenoRoots_Person_Admin {

	/** Filled in by imports and the Piwigo sync; shown in the side box instead. */
	const MANAGED_FIELDS = array( 'gedcom_id', 'piwigo_tag_id', 'piwigo_photo_count', 'photo_archive_url' );

	public static function init() {
		add_action( 'add_meta_boxes_' . GenoRoots_Person_CPT::POST_TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
		// Not rendered, so not submitted: saving the form can't overwrite them.
		foreach ( self::MANAGED_FIELDS as $name ) {
			add_filter( 'acf/prepare_field/name=' . $name, '__return_false' );
		}
	}

	public static function add_box() {
		add_meta_box( 'genoroots-person-info', __( 'Person details', 'geotour-genoroots' ), array( __CLASS__, 'render_box' ), null, 'side', 'high' );
	}

	public static function render_box( $post ) {
		$id      = (int) $post->ID;
		$gedcom  = (string) get_post_meta( $id, 'gedcom_id', true );
		$number  = preg_match( '/^I(\d+)$/', $gedcom, $m ) ? $m[1] : '';
		$photos  = (string) get_post_meta( $id, 'photo_archive_url', true );
		$count   = (int) get_post_meta( $id, 'piwigo_photo_count', true );
		$keyword = $number ? self::keyword_name( $post->post_title ) . ' [' . $number . ']' : '';
		?>
		<div class="gnr-admin-box">
			<p class="gnr-admin-box__row">
				<span class="gnr-admin-box__label"><?php esc_html_e( 'ID', 'geotour-genoroots' ); ?></span>
				<strong><?php echo $number ? esc_html( $number ) : esc_html__( 'not assigned yet', 'geotour-genoroots' ); ?></strong>
			</p>

			<?php if ( $keyword ) : ?>
				<p class="gnr-admin-box__row gnr-admin-box__row--stack">
					<span class="gnr-admin-box__label"><?php esc_html_e( 'Photo keyword', 'geotour-genoroots' ); ?></span>
					<span class="gnr-admin-box__copy">
						<code><?php echo esc_html( $keyword ); ?></code>
						<button type="button" class="button button-small" data-copy="<?php echo esc_attr( $keyword ); ?>"><?php esc_html_e( 'Copy', 'geotour-genoroots' ); ?></button>
					</span>
				</p>
			<?php endif; ?>

			<p class="gnr-admin-box__row">
				<span class="gnr-admin-box__label"><?php esc_html_e( 'Photos', 'geotour-genoroots' ); ?></span>
				<?php if ( $photos ) : ?>
					<a href="<?php echo esc_url( $photos ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( _n( '%d photo', '%d photos', $count, 'geotour-genoroots' ), $count ) ); ?> ↗</a>
				<?php else : ?>
					<span class="gnr-admin-box__muted"><?php esc_html_e( 'none in the archive', 'geotour-genoroots' ); ?></span>
				<?php endif; ?>
			</p>

			<?php
			self::people_row( __( 'Parents', 'geotour-genoroots' ), GenoRoots_Relatives::get_parents( $id ) );
			self::people_row( __( 'Spouses', 'geotour-genoroots' ), GenoRoots_Relatives::get_spouses( $id ) );
			self::people_row( __( 'Children', 'geotour-genoroots' ), GenoRoots_Relatives::get_children( $id ) );

			$families = array_unique( array_filter( array_map( 'intval', array_merge(
				(array) get_post_meta( $id, '_famc_family_id' ),
				(array) get_post_meta( $id, '_fams_family_id' )
			) ) ) );
			if ( $families ) :
				?>
				<p class="gnr-admin-box__row gnr-admin-box__row--stack">
					<span class="gnr-admin-box__label"><?php esc_html_e( 'Families (edit links)', 'geotour-genoroots' ); ?></span>
					<?php foreach ( $families as $family_id ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $family_id ) ); ?>"><?php echo esc_html( get_the_title( $family_id ) ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( get_post_status( $id ) === 'publish' ) : ?>
				<p class="gnr-admin-box__row">
					<a href="<?php echo esc_url( add_query_arg( 'person', $id, get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View in the tree', 'geotour-genoroots' ); ?> ↗</a>
				</p>
			<?php endif; ?>
		</div>
		<script>
			document.querySelectorAll( '#genoroots-person-info [data-copy]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => {
					navigator.clipboard.writeText( button.dataset.copy ).then( () => {
						const label = button.textContent;
						button.textContent = '<?php echo esc_js( __( 'Copied', 'geotour-genoroots' ) ); ?>';
						setTimeout( () => { button.textContent = label; }, 1500 );
					} );
				} );
			} );
		</script>
		<?php
	}

	private static function people_row( $label, array $ids ) {
		if ( ! $ids ) {
			return;
		}
		?>
		<p class="gnr-admin-box__row gnr-admin-box__row--stack">
			<span class="gnr-admin-box__label"><?php echo esc_html( $label ); ?></span>
			<?php foreach ( $ids as $person_id ) : ?>
				<a href="<?php echo esc_url( get_edit_post_link( $person_id ) ); ?>"><?php echo esc_html( get_the_title( $person_id ) ); ?></a>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/** Titles are "Surname Given"; photo keywords are "Given Surname". */
	private static function keyword_name( $title ) {
		$title = trim( (string) $title );
		$space = strpos( $title, ' ' );
		return $space === false ? $title : trim( substr( $title, $space + 1 ) ) . ' ' . substr( $title, 0, $space );
	}

	public static function styles() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== GenoRoots_Person_CPT::POST_TYPE ) {
			return;
		}
		?>
		<style>
			.acf-field.gnr-acf-hidden { display: none !important; }
			.gnr-admin-box__row { display: flex; justify-content: space-between; gap: 8px; margin: 0 0 10px; }
			.gnr-admin-box__row--stack { flex-direction: column; gap: 2px; }
			.gnr-admin-box__label { color: #646970; }
			.gnr-admin-box__muted { color: #8c8f94; }
			.gnr-admin-box__copy { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
			.gnr-admin-box__copy code { padding: 2px 6px; font-size: 12px; }
		</style>
		<?php
	}
}
