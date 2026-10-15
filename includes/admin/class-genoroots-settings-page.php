<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GenoRoots → Settings: the words in the public addresses, the Piwigo
 * gallery address, and a button to link people to their Piwigo tags
 * (preview first, then apply).
 */
class GenoRoots_Settings_Page {

	const SLUG         = 'genoroots-settings';
	// Its own option group: options.php saves every option in the submitted
	// group, so one shared group would blank the other form's option.
	const ADDRESSES    = 'genoroots-addresses';
	const NONCE_ACTION = 'genoroots_piwigo_sync';
	const EDITOR       = 'genoroots-editor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . GenoRoots_Person_CPT::POST_TYPE,
			__( 'GenoRoots settings', 'geotour-genoroots' ),
			__( 'Settings', 'geotour-genoroots' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting( self::SLUG, GenoRoots_Piwigo::OPTION, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $value ) {
				return untrailingslashit( esc_url_raw( trim( (string) $value ) ) );
			},
			'default'           => '',
		) );
		register_setting( self::EDITOR, GenoRoots_Field_Editor::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				$value = (array) $value;
				return array(
					'page_id'     => absint( $value['page_id'] ?? 0 ),
					'editor_only' => empty( $value['editor_only'] ) ? 0 : 1,
				);
			},
			'default'           => GenoRoots_Field_Editor::DEFAULTS,
		) );
		register_setting( self::ADDRESSES, GenoRoots_Slugs::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( 'GenoRoots_Slugs', 'sanitize' ),
			'default'           => GenoRoots_Slugs::DEFAULTS,
		) );
	}

	private static function render_editor() {
		$settings = GenoRoots_Field_Editor::settings();
		$name     = GenoRoots_Field_Editor::OPTION;
		$url      = GenoRoots_Field_Editor::page_url();
		?>
		<h2><?php esc_html_e( 'Field editor', 'geotour-genoroots' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: the shortcode */
				esc_html__( 'A phone-friendly editor for the tree, without the admin. Put %s on a page.', 'geotour-genoroots' ),
				'<code>[genoroots_field_editor]</code>'
			);
			?>
			<?php if ( $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open the editor', 'geotour-genoroots' ); ?></a>
			<?php endif; ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( self::EDITOR ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="genoroots-editor-page"><?php esc_html_e( 'Editor page', 'geotour-genoroots' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages( array(
							'name'              => esc_attr( $name . '[page_id]' ),
							'id'                => 'genoroots-editor-page',
							'selected'          => (int) $settings['page_id'],
							'show_option_none'  => esc_html__( 'The first page with the shortcode', 'geotour-genoroots' ),
							'option_none_value' => '0',
						) );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Genealogy editors', 'geotour-genoroots' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name . '[editor_only]' ); ?>" value="1" <?php checked( (int) $settings['editor_only'], 1 ); ?>>
							<?php esc_html_e( 'Use the field editor only: send them there after signing in and instead of the admin', 'geotour-genoroots' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Which collections an editor may change is set on their user profile.', 'geotour-genoroots' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save editor settings', 'geotour-genoroots' ) ); ?>
		</form>
		<?php
	}

	private static function render_addresses() {
		$saved    = GenoRoots_Slugs::saved();
		$filtered = has_filter( 'genoroots_slugs' );
		$fields   = array(
			'people'     => array( __( 'Family tree page', 'geotour-genoroots' ), '' ),
			'person'     => array( __( 'Person pages', 'geotour-genoroots' ), '<name>/' ),
			'group'      => array( __( 'Group pages', 'geotour-genoroots' ), '<group>/' ),
			'collection' => array( __( 'Collection pages', 'geotour-genoroots' ), '<collection>/' ),
		);
		?>
		<h2><?php esc_html_e( 'Addresses', 'geotour-genoroots' ); ?></h2>
		<p><?php esc_html_e( 'The words in the public web addresses. Changing one moves those pages, and links to the old address stop working.', 'geotour-genoroots' ); ?></p>
		<?php if ( $filtered ) : ?>
			<p><em><?php esc_html_e( 'A theme or plugin changes these words with the genoroots_slugs filter, so the addresses in use may differ from the ones below.', 'geotour-genoroots' ); ?></em></p>
		<?php endif; ?>
		<?php settings_errors( GenoRoots_Slugs::OPTION ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( self::ADDRESSES ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => list( $label, $tail ) ) : ?>
					<tr>
						<th scope="row"><label for="genoroots-slug-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" class="regular-text code" style="width:14em" id="genoroots-slug-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( GenoRoots_Slugs::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( urldecode( $saved[ $key ] ) ); ?>" placeholder="<?php echo esc_attr( GenoRoots_Slugs::DEFAULTS[ $key ] ); ?>"><code>/<?php echo esc_html( $tail ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Save addresses', 'geotour-genoroots' ) ); ?>
		</form>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'geotour-genoroots' ) );
		}

		$plan    = null;
		$applied = null;
		$error   = '';
		if ( isset( $_POST['genoroots_piwigo_preview'] ) || isset( $_POST['genoroots_piwigo_apply'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			try {
				$plan = GenoRoots_Piwigo::plan( GenoRoots_Piwigo::fetch_tags( GenoRoots_Piwigo::base_url() ) );
				if ( isset( $_POST['genoroots_piwigo_apply'] ) ) {
					$applied = GenoRoots_Piwigo::apply( $plan );
				}
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}

		$last = get_option( 'genoroots_piwigo_last_sync' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GenoRoots settings', 'geotour-genoroots' ); ?></h1>

			<?php self::render_addresses(); ?>
			<?php self::render_editor(); ?>

			<h2><?php esc_html_e( 'Piwigo photo archive', 'geotour-genoroots' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="genoroots-piwigo-url"><?php esc_html_e( 'Gallery address', 'geotour-genoroots' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="genoroots-piwigo-url" name="<?php echo esc_attr( GenoRoots_Piwigo::OPTION ); ?>" value="<?php echo esc_attr( GenoRoots_Piwigo::base_url() ); ?>" placeholder="https://pics.example.org">
							<p class="description"><?php esc_html_e( 'People are matched to Piwigo tags by the number in brackets, e.g. "Maria Papadaki [384]" → GenoRoots ID I384.', 'geotour-genoroots' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'geotour-genoroots' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Link people to their photos', 'geotour-genoroots' ); ?></h2>
			<p>
				<?php
				if ( $last ) {
					printf(
						/* translators: 1: date, 2: people linked, 3: people total */
						esc_html__( 'Last sync: %1$s — %2$d of %3$d people have photos.', 'geotour-genoroots' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] ) ),
						(int) $last['linked'],
						(int) $last['people']
					);
				} else {
					esc_html_e( 'Not synced yet.', 'geotour-genoroots' );
				}
				?>
				<?php esc_html_e( 'Also available as: wp genoroots piwigo-sync', 'geotour-genoroots' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Preview', 'geotour-genoroots' ), 'secondary', 'genoroots_piwigo_preview', false ); ?>
				<?php submit_button( __( 'Sync now', 'geotour-genoroots' ), 'primary', 'genoroots_piwigo_apply', false ); ?>
			</form>

			<?php if ( $error ) : ?>
				<p class="genoroots-sync-result" style="padding:10px 12px;background:#fff;border-left:4px solid #d63638;"><?php echo esc_html( $error ); ?></p>
			<?php elseif ( $plan ) : ?>
				<?php self::render_plan( $plan, $applied ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_plan( array $plan, $applied ) {
		?>
		<?php // Plain content, not a .notice: notice-manager plugins move those into their own panel. ?>
		<p class="genoroots-sync-result" style="padding:10px 12px;background:#fff;border-left:4px solid <?php echo $applied === null ? '#72aee6' : '#00a32a'; ?>;">
			<?php
			printf(
				/* translators: 1: linked, 2: people, 3: changes */
				esc_html__( '%1$d of %2$d people have a Piwigo tag. %3$d need updating.', 'geotour-genoroots' ),
				(int) $plan['linked'],
				(int) $plan['people'],
				count( $plan['changes'] )
			);
			if ( $applied !== null ) {
				echo ' <strong>' . esc_html__( 'Saved.', 'geotour-genoroots' ) . '</strong>';
			}
			?>
		</p>

		<?php foreach ( array( 'conflicts' => __( 'Several tags for one person', 'geotour-genoroots' ), 'unknown' => __( 'Tags with a number that is not in the tree', 'geotour-genoroots' ) ) as $key => $title ) : ?>
			<?php if ( $plan[ $key ] ) : ?>
				<h3><?php echo esc_html( $title ); ?></h3>
				<ul class="ul-disc">
					<?php foreach ( $plan[ $key ] as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $plan['changes'] ) : ?>
			<h3><?php esc_html_e( 'Changes', 'geotour-genoroots' ); ?></h3>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Person', 'geotour-genoroots' ); ?></th>
					<th><?php esc_html_e( 'Piwigo tag', 'geotour-genoroots' ); ?></th>
					<th><?php esc_html_e( 'Photos', 'geotour-genoroots' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $plan['changes'] as $person_id => $change ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $person_id ) ); ?>"><?php echo esc_html( $change['title'] ); ?></a></td>
							<td><?php echo esc_html( ( $change['from']['piwigo_tag_id'] ?: '—' ) . ' → ' . ( $change['to']['piwigo_tag_id'] ?: '—' ) ); ?></td>
							<td><?php echo esc_html( $change['to']['piwigo_photo_count'] ?: '0' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
