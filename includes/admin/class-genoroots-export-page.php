<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * People → Export GEDCOM: pick a collection and/or a group, download a .ged.
 * The download goes through admin-post.php so the file is sent before any
 * admin page output. The file contains personal data: administrators only.
 */
class GenoRoots_Export_Page {

	const ACTION = 'genoroots_export_gedcom';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . GenoRoots_Person_CPT::POST_TYPE,
			__( 'Export GEDCOM', 'geotour-genoroots' ),
			__( 'Export GEDCOM', 'geotour-genoroots' ),
			'manage_options',
			'genoroots-export',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'geotour-genoroots' ) );
		}
		$collections = GenoRoots_Collection_Taxonomy::listed();
		$groups      = get_terms( array(
			'taxonomy'   => GenoRoots_Family_Group_Taxonomy::TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		$groups = is_array( $groups ) ? $groups : array();

		GenoRoots_Admin_Help::styles();
		?>
		<div class="wrap gnr-admin">
			<h1><?php esc_html_e( 'Export GEDCOM', 'geotour-genoroots' ); ?></h1>
			<p class="gnr-admin__intro"><?php esc_html_e( 'Downloads people and families as a GEDCOM 5.5 file: to keep a backup, to open the tree in a genealogy program, or to move it to another site that uses GenoRoots.', 'geotour-genoroots' ); ?></p>

			<div class="gnr-admin__grid">
				<div class="card">
					<h2><?php esc_html_e( 'What to export', 'geotour-genoroots' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<?php wp_nonce_field( self::ACTION ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="genoroots_export_collection"><?php esc_html_e( 'Collection', 'geotour-genoroots' ); ?></label></th>
								<td>
									<select name="collection" id="genoroots_export_collection">
										<option value=""><?php esc_html_e( 'All', 'geotour-genoroots' ); ?></option>
										<?php foreach ( $collections as $term ) : ?>
											<option value="<?php echo esc_attr( urldecode( $term->slug ) ); ?>"><?php echo esc_html( $term->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="genoroots_export_group"><?php esc_html_e( 'Group', 'geotour-genoroots' ); ?></label></th>
								<td>
									<select name="group" id="genoroots_export_group">
										<option value=""><?php esc_html_e( 'All', 'geotour-genoroots' ); ?></option>
										<?php foreach ( $groups as $term ) : ?>
											<option value="<?php echo esc_attr( urldecode( $term->slug ) ); ?>"><?php echo esc_html( sprintf( '%s (%d)', $term->name, $term->count ) ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'With both chosen, you get the group\'s members within that collection.', 'geotour-genoroots' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Download .ged', 'geotour-genoroots' ) ); ?>
					</form>

					<?php if ( $collections ) : ?>
						<h2><?php esc_html_e( 'Whole collections', 'geotour-genoroots' ); ?></h2>
						<table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Collection', 'geotour-genoroots' ); ?></th>
								<th><?php esc_html_e( 'People', 'geotour-genoroots' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $collections as $term ) : ?>
								<tr>
									<td><?php echo esc_html( $term->name ); ?></td>
									<td><?php echo (int) self::people_in( $term ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
											<input type="hidden" name="collection" value="<?php echo esc_attr( urldecode( $term->slug ) ); ?>">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">
											<button type="submit" class="button"><?php esc_html_e( 'Download', 'geotour-genoroots' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Step by step', 'geotour-genoroots' ); ?></h2>
					<?php
					GenoRoots_Admin_Help::steps( array(
						__( '<strong>Choose what to export:</strong> a collection, a group, or both. "All" in both exports everyone.', 'geotour-genoroots' ),
						__( '<strong>Download.</strong> The file is named after your choice and today\'s date, e.g. <code>genoroots-venizelos-2026-10-04.ged</code>.', 'geotour-genoroots' ),
						__( '<strong>Open it</strong> in a genealogy program, keep it as a backup, or import it on another site with Import GEDCOM.', 'geotour-genoroots' ),
						__( '<strong>Keep it private.</strong> It contains personal data, including about living people. Do not publish it or send it openly.', 'geotour-genoroots' ),
					) );
					?>
				</div>
			</div>
			<?php self::render_help(); ?>
		</div>
		<?php
	}

	private static function people_in( WP_Term $collection ) {
		$query = new WP_Query( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => GenoRoots_Collection_Taxonomy::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => (int) $collection->term_id,
				),
			),
		) );
		return (int) $query->found_posts;
	}

	private static function render_help() {
		GenoRoots_Admin_Help::sections( __( 'Help', 'geotour-genoroots' ), array(
			array(
				'title' => __( 'What the file contains', 'geotour-genoroots' ),
				'body'  => array(
					__( 'Published people and families only; drafts and the trash are left out. For each person:', 'geotour-genoroots' ),
					array(
						'list' => array(
							__( 'name, sex, birth, death and burial (dates exactly as stored);', 'geotour-genoroots' ),
							__( 'notes (one NOTE per paragraph) and sources (one SOUR per line);', 'geotour-genoroots' ),
							__( 'period, groups and related article, as <code>_PERIOD</code>, <code>_GROUP</code> and <code>_ARTICLE</code>;', 'geotour-genoroots' ),
							__( 'the name in Latin characters, if filled in, as a second NAME;', 'geotour-genoroots' ),
							__( 'the portrait, as a link to the image (<code>OBJE</code>/<code>FILE</code>), not the image itself.', 'geotour-genoroots' ),
						),
					),
					__( 'For each family: the spouses, the children, the marriage, and how certain the link is (<code>_STATUS</code>). Photo-archive links and translations are not included.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'Choosing a collection or a group', 'geotour-genoroots' ),
				'body'  => array(
					array(
						'list' => array(
							__( '<strong>A collection:</strong> everyone in that tree.', 'geotour-genoroots' ),
							__( '<strong>A group:</strong> its members, from every collection.', 'geotour-genoroots' ),
							__( '<strong>Both:</strong> the group\'s members within that collection, e.g. the Mitsotakis group within the Venizelos collection.', 'geotour-genoroots' ),
						),
					),
					__( 'A family is included when at least two of its members are in the selection, and it lists only those members. So a group export keeps the marriages and parent–child links among its members, and the file never points to someone who is not in it.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'IDs and importing the file again', 'geotour-genoroots' ),
				'body'  => array(
					__( 'People and families keep their GenoRoots IDs. When everyone in the file belongs to the same collection, its prefix is left out (<code>venizelos:I08</code> is written as <code>@I08@</code>).', 'geotour-genoroots' ),
					__( 'Importing the file back into the same collection updates the same people; nothing is duplicated and nothing changes. On another site, import it into a collection with the same address (slug), so that later imports update the same people there too.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'Opening the file in genealogy programs', 'geotour-genoroots' ),
				'body'  => array(
					__( 'The file is standard GEDCOM 5.5 in UTF-8, which genealogy programs (desktop or online) can open. They read names, dates, places, families, notes and sources.', 'geotour-genoroots' ),
					__( 'The GenoRoots tags (<code>_PERIOD</code>, <code>_GROUP</code>, <code>_ARTICLE</code>, <code>_STATUS</code>) are not standard: most programs ignore them or keep them as notes. The portrait is a link to the image on this site.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'Personal data', 'geotour-genoroots' ),
				'body'  => array(
					__( 'The file contains names, dates and family relations, including of living people. Store it privately, do not upload it to a public folder of the website, and delete copies you no longer need. Only administrators can download it.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'From the command line', 'geotour-genoroots' ),
				'body'  => array(
					__( 'The same export runs with WP-CLI; without a file name it prints to the screen:', 'geotour-genoroots' ),
					array( 'pre' => "wp genoroots export venizelos.ged --collection=venizelos\nwp genoroots export mitsotakis.ged --collection=venizelos --group=mitsotakis" ),
				),
			),
		) );
	}

	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'geotour-genoroots' ) );
		}
		check_admin_referer( self::ACTION );

		$collection_slug = sanitize_text_field( wp_unslash( $_POST['collection'] ?? '' ) );
		$group_slug      = sanitize_text_field( wp_unslash( $_POST['group'] ?? '' ) );
		$exporter        = new GenoRoots_Gedcom_Exporter(
			GenoRoots_Gedcom_Exporter::collection_term( $collection_slug ),
			GenoRoots_Gedcom_Exporter::group_term( $group_slug )
		);
		$result = $exporter->export();
		if ( ! $result['people'] ) {
			wp_die( esc_html__( 'Nobody matches this selection.', 'geotour-genoroots' ), '', array( 'back_link' => true ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $exporter->file_name() . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $result['gedcom']; // phpcs:ignore WordPress.Security.EscapeOutput -- a GEDCOM file, not HTML
		exit;
	}
}
