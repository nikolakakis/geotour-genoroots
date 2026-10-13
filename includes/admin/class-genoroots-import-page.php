<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * People → Import GEDCOM: upload a .ged, choose its collection, check it
 * first (nothing saved) or import it. Same importer as `wp genoroots import`.
 */
class GenoRoots_Import_Page {

	const NONCE_ACTION   = 'genoroots_import_gedcom';
	const NEW_COLLECTION = '__new';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . GenoRoots_Person_CPT::POST_TYPE,
			__( 'Import GEDCOM', 'geotour-genoroots' ),
			__( 'Import GEDCOM', 'geotour-genoroots' ),
			'manage_options',
			'genoroots-import',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'geotour-genoroots' ) );
		}

		GenoRoots_Admin_Help::styles();
		echo '<div class="wrap gnr-admin">';
		echo '<h1>' . esc_html__( 'Import GEDCOM', 'geotour-genoroots' ) . '</h1>';
		echo '<p class="gnr-admin__intro">' . esc_html__( 'Adds people and families from a GEDCOM file, the standard exchange format of genealogy programs. Importing the same file again updates the people and families it created; it never makes duplicates.', 'geotour-genoroots' ) . '</p>';

		if ( ! empty( $_POST['genoroots_import_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$result = self::handle_upload();
			if ( $result ) {
				self::render_result( $result );
			}
		}

		echo '<div class="gnr-admin__grid">';
		self::render_form();
		echo '<div class="card"><h2>' . esc_html__( 'Step by step', 'geotour-genoroots' ) . '</h2>';
		GenoRoots_Admin_Help::steps( array(
			__( '<strong>Prepare the file.</strong> GEDCOM 5.5 or 5.5.1, saved as UTF-8. Most genealogy programs have an "Export → GEDCOM" command.', 'geotour-genoroots' ),
			__( '<strong>Choose the collection.</strong> One collection per separate family network (e.g. one family tree). Choose "No collection" for a single shared tree, such as the families of one village. Import the same file into the same collection every time.', 'geotour-genoroots' ),
			__( '<strong>Check the file first.</strong> Leave "Only check the file" ticked: you will see how many people and families it has, how many already exist here, and any problems. Nothing is saved.', 'geotour-genoroots' ),
			__( '<strong>Import.</strong> Untick the box and upload the file again.', 'geotour-genoroots' ),
			__( '<strong>Review.</strong> Open a few people, give the collection a description, and add portraits in each person\'s Portrait box.', 'geotour-genoroots' ),
		) );
		echo '</div></div>';

		self::render_help();
		echo '</div>';
	}

	// ---- Form ----

	private static function render_form() {
		$collections = GenoRoots_Collection_Taxonomy::listed();
		?>
		<div class="card">
			<h2><?php esc_html_e( 'File and collection', 'geotour-genoroots' ); ?></h2>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="genoroots_gedcom_file"><?php esc_html_e( 'GEDCOM file', 'geotour-genoroots' ); ?></label></th>
						<td><input type="file" name="genoroots_gedcom_file" id="genoroots_gedcom_file" accept=".ged" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="genoroots_import_collection"><?php esc_html_e( 'Collection', 'geotour-genoroots' ); ?></label></th>
						<td>
							<select name="collection" id="genoroots_import_collection">
								<option value=""><?php esc_html_e( 'No collection (one shared tree)', 'geotour-genoroots' ); ?></option>
								<?php foreach ( $collections as $term ) : ?>
									<option value="<?php echo esc_attr( urldecode( $term->slug ) ); ?>"><?php echo esc_html( sprintf( '%s (%s)', $term->name, urldecode( $term->slug ) ) ); ?></option>
								<?php endforeach; ?>
								<option value="<?php echo esc_attr( self::NEW_COLLECTION ); ?>"><?php esc_html_e( 'New collection…', 'geotour-genoroots' ); ?></option>
							</select>
							<div class="gnr-admin__new-collection" id="genoroots_new_collection" hidden>
								<label for="genoroots_collection_name"><?php esc_html_e( 'Name', 'geotour-genoroots' ); ?></label>
								<input type="text" class="regular-text" name="collection_name" id="genoroots_collection_name" placeholder="Venizelos">
								<label for="genoroots_collection_slug"><?php esc_html_e( 'Address (slug)', 'geotour-genoroots' ); ?></label>
								<input type="text" class="regular-text" name="collection_slug" id="genoroots_collection_slug" placeholder="venizelos">
								<p class="description"><?php esc_html_e( 'Short, in Latin letters. Used in the page address and as the prefix of every ID (venizelos:I08). Leave empty to make one from the name.', 'geotour-genoroots' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Existing records', 'geotour-genoroots' ); ?></th>
						<td>
							<label><input type="checkbox" name="add_only" value="1"> <?php esc_html_e( 'Add only: don\'t change people and families already here', 'geotour-genoroots' ); ?></label>
							<p class="description"><?php esc_html_e( 'For extending a tree. People already here are left exactly as they are; families already here only gain new children and fields that are empty. Leave unticked to update the tree from the file.', 'geotour-genoroots' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Check first', 'geotour-genoroots' ); ?></th>
						<td>
							<label><input type="checkbox" name="dry_run" value="1" checked> <?php esc_html_e( 'Only check the file (nothing is saved)', 'geotour-genoroots' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Upload', 'geotour-genoroots' ), 'primary', 'genoroots_import_submit' ); ?>
			</form>
			<script>
				( function () {
					var select = document.getElementById( 'genoroots_import_collection' );
					var box = document.getElementById( 'genoroots_new_collection' );
					var sync = function () { box.hidden = select.value !== '<?php echo esc_js( self::NEW_COLLECTION ); ?>'; };
					select.addEventListener( 'change', sync );
					sync();
				} )();
			</script>
		</div>
		<?php
	}

	// ---- Handling ----

	/** @return array|null ['mode' => 'check'|'import', ...] */
	private static function handle_upload() {
		$file = $_FILES['genoroots_gedcom_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::notice( 'error', __( 'Please choose a .ged file to upload.', 'geotour-genoroots' ) );
			return null;
		}
		if ( strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) !== 'ged' ) {
			self::notice( 'error', __( 'The file must be a GEDCOM file ending in .ged.', 'geotour-genoroots' ) );
			return null;
		}

		$dry_run    = ! empty( $_POST['dry_run'] );
		$add_only   = ! empty( $_POST['add_only'] );
		$choice     = sanitize_text_field( wp_unslash( $_POST['collection'] ?? '' ) );
		$collection = null;
		$new        = null; // [name, slug] of a collection still to create

		if ( $choice === self::NEW_COLLECTION ) {
			$name = sanitize_text_field( wp_unslash( $_POST['collection_name'] ?? '' ) );
			$slug = sanitize_title( sanitize_text_field( wp_unslash( $_POST['collection_slug'] ?? '' ) ) ?: $name );
			if ( $name === '' || $slug === '' ) {
				self::notice( 'error', __( 'Give the new collection a name.', 'geotour-genoroots' ) );
				return null;
			}
			$collection = GenoRoots_Collection_Taxonomy::term( $slug ); // already there: use it
			if ( ! $collection ) {
				$new = array( $name, $slug );
			}
		} elseif ( $choice !== '' ) {
			$collection = GenoRoots_Collection_Taxonomy::term( $choice );
			if ( ! $collection ) {
				self::notice( 'error', __( 'That collection no longer exists.', 'geotour-genoroots' ) );
				return null;
			}
		}

		$prefix = $collection ? urldecode( $collection->slug ) . ':' : ( $new ? urldecode( $new[1] ) . ':' : '' );
		$label  = $collection ? $collection->name : ( $new ? $new[0] : __( 'No collection', 'geotour-genoroots' ) );

		try {
			if ( $dry_run ) {
				return array(
					'mode'          => 'check',
					'label'         => $label,
					'is_new'        => (bool) $new,
					'has_collection' => $collection || $new,
					'add_only'       => $add_only,
				) + self::check( $file['tmp_name'], $prefix, $add_only );
			}
			if ( $new ) {
				$created = wp_insert_term( $new[0], GenoRoots_Collection_Taxonomy::TAXONOMY, array( 'slug' => $new[1] ) );
				if ( is_wp_error( $created ) ) {
					self::notice( 'error', $created->get_error_message() );
					return null;
				}
				$collection = get_term( $created['term_id'], GenoRoots_Collection_Taxonomy::TAXONOMY );
			}
			$importer = new GenoRoots_Gedcom_Importer( $collection, $add_only );
			return array(
				'mode'       => 'import',
				'label'      => $label,
				'collection' => $collection,
				'add_only'   => $add_only,
			) + $importer->import_file( $file['tmp_name'] );
		} catch ( \Throwable $e ) {
			/* translators: %s: the underlying error message */
			self::notice( 'error', sprintf( __( 'Import failed: %s', 'geotour-genoroots' ), $e->getMessage() ) );
			return null;
		}
	}

	/** What an import would do, without saving anything. */
	private static function check( $path, $prefix, $add_only = false ) {
		global $wpdb;
		$parser = new GenoRoots_Gedcom_Parser();
		$parsed = $parser->parse_file( $path );

		// Stored ID => post ID, for every person and family on the site.
		$existing = array_column(
			(array) $wpdb->get_results( "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = 'gedcom_id'", ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'post_id',
			'meta_value'
		);
		$count    = static function ( array $records ) use ( $existing, $prefix ) {
			$found = 0;
			foreach ( array_keys( $records ) as $xref ) {
				$found += isset( $existing[ $prefix . $xref ] ) ? 1 : 0;
			}
			return array( count( $records ), $found );
		};
		list( $people, $people_found )     = $count( $parsed['individuals'] );
		list( $families, $families_found ) = $count( $parsed['families'] );

		$groups   = array();
		$problems = array();
		foreach ( $parsed['individuals'] as $xref => $indi ) {
			foreach ( (array) ( $indi['groups'] ?? array() ) as $group ) {
				$groups[ $group ] = true;
			}
			if ( ! empty( $indi['article'] ) ) {
				$url    = preg_match( '#^https?://#i', $indi['article'] ) ? $indi['article'] : home_url( '/' . ltrim( $indi['article'], '/' ) );
				$target = url_to_postid( $url );
				if ( ! $target || get_post_type( $target ) === GenoRoots_Person_CPT::POST_TYPE ) {
					/* translators: 1: person ID, 2: address */
					$problems[] = sprintf( __( 'Person %1$s: related article "%2$s" was not found on this site; the person will be imported without the link.', 'geotour-genoroots' ), $xref, $indi['article'] );
				}
			}
		}
		foreach ( $parsed['families'] as $xref => $fam ) {
			$members = array_merge( array_filter( array( $fam['husb'] ?? '', $fam['wife'] ?? '' ) ), (array) ( $fam['chil'] ?? array() ) );
			foreach ( $members as $member ) {
				if ( ! isset( $parsed['individuals'][ $member ] ) && ! isset( $existing[ $prefix . $member ] ) ) {
					/* translators: 1: family ID, 2: person ID */
					$problems[] = sprintf( __( 'Family %1$s names %2$s, who is not in the file or on this site; that link will be skipped.', 'geotour-genoroots' ), $xref, $member );
				}
			}
		}
		// The same ID for someone else (a numbering mistake) would attach new
		// people to the wrong person: compare the names.
		$plain = static function ( $name ) {
			return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) ) ) );
		};
		foreach ( $parsed['individuals'] as $xref => $indi ) {
			$post_id = $existing[ $prefix . $xref ] ?? 0;
			if ( $post_id && $plain( $indi['name'] ?? '' ) !== $plain( get_the_title( $post_id ) ) ) {
				/* translators: 1: person ID, 2: name in the file, 3: name on the site */
				$problems[] = sprintf( __( 'Person %1$s is "%2$s" in the file but "%3$s" on this site. Check the ID: with the same ID they are treated as the same person.', 'geotour-genoroots' ), $xref, trim( preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', $indi['name'] ?? '' ) ) ), html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) );
			}
		}

		// Add only: what each family already here would gain.
		$additions = array();
		if ( $add_only ) {
			$name_of = static function ( $xref ) use ( $parsed ) {
				return trim( preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', $parsed['individuals'][ $xref ]['name'] ?? $xref ) ) );
			};
			foreach ( $parsed['families'] as $xref => $fam ) {
				$family_id = (int) ( $existing[ $prefix . $xref ] ?? 0 );
				if ( ! $family_id ) {
					continue;
				}
				$have    = array_map( 'intval', (array) get_post_meta( $family_id, 'children', true ) );
				$spouses = array( (int) get_post_meta( $family_id, 'spouse1', true ), (int) get_post_meta( $family_id, 'spouse2', true ) );
				$gains   = array();
				foreach ( (array) ( $fam['chil'] ?? array() ) as $child ) {
					$child_id = (int) ( $existing[ $prefix . $child ] ?? 0 );
					if ( ! $child_id || ! in_array( $child_id, $have, true ) ) {
						/* translators: %s: a person's name */
						$gains[] = sprintf( __( 'child %s', 'geotour-genoroots' ), $name_of( $child ) );
					}
				}
				foreach ( array( 'husb' => 0, 'wife' => 1 ) as $tag => $slot ) {
					if ( ! empty( $fam[ $tag ] ) && ! $spouses[ $slot ] ) {
						/* translators: %s: a person's name */
						$gains[] = sprintf( __( 'spouse %s', 'geotour-genoroots' ), $name_of( $fam[ $tag ] ) );
					}
				}
				if ( ( $fam['marr']['date'] ?? '' ) !== '' && trim( (string) get_post_meta( $family_id, 'marriage_date_raw', true ) ) === '' ) {
					$gains[] = __( 'marriage date', 'geotour-genoroots' );
				}
				if ( ( $fam['marr']['place'] ?? '' ) !== '' && trim( (string) get_post_meta( $family_id, 'marriage_place', true ) ) === '' ) {
					$gains[] = __( 'marriage place', 'geotour-genoroots' );
				}
				if ( $gains ) {
					/* translators: 1: family name, 2: what is added */
					$additions[] = sprintf( __( '%1$s: adds %2$s', 'geotour-genoroots' ), html_entity_decode( get_the_title( $family_id ), ENT_QUOTES, 'UTF-8' ), implode( ', ', $gains ) );
				}
			}
		}

		$tax          = GenoRoots_Family_Group_Taxonomy::TAXONOMY;
		$known_groups = array();
		foreach ( array_keys( $groups ) as $group ) {
			$known_groups[ $group ] = (bool) ( get_term_by( 'name', $group, $tax ) ?: get_term_by( 'slug', sanitize_title( $group ), $tax ) );
		}

		return array(
			'people'         => $people,
			'people_found'   => $people_found,
			'families'       => $families,
			'families_found' => $families_found,
			'groups'         => $known_groups,
			'problems'       => $problems,
			'additions'      => $additions,
			'skipped_lines'  => $parsed['skipped'],
		);
	}

	// ---- Output ----

	private static function notice( $type, $message ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function render_result( array $r ) {
		echo '<div class="card gnr-admin__report">';
		if ( $r['mode'] === 'check' ) {
			/* translators: %s: collection name */
			echo '<h2>' . esc_html( sprintf( __( 'File check: %s', 'geotour-genoroots' ), $r['label'] ) ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Nothing has been saved.', 'geotour-genoroots' ) . '</strong></p><ul class="ul-disc">';
			if ( $r['add_only'] ) {
				/* translators: 1: people in the file, 2: of them already here, 3: new */
				echo '<li>' . esc_html( sprintf( __( 'People: %1$d in the file: %2$d already here (left unchanged), %3$d new.', 'geotour-genoroots' ), $r['people'], $r['people_found'], $r['people'] - $r['people_found'] ) ) . '</li>';
				/* translators: 1: families in the file, 2: of them already here, 3: new */
				echo '<li>' . esc_html( sprintf( __( 'Families: %1$d in the file: %2$d already here (only gain what they lack), %3$d new.', 'geotour-genoroots' ), $r['families'], $r['families_found'], $r['families'] - $r['families_found'] ) ) . '</li>';
			} else {
				/* translators: 1: people in the file, 2: of them already here, 3: new */
				echo '<li>' . esc_html( sprintf( __( 'People: %1$d in the file: %2$d already here (will be updated), %3$d new.', 'geotour-genoroots' ), $r['people'], $r['people_found'], $r['people'] - $r['people_found'] ) ) . '</li>';
				/* translators: 1: families in the file, 2: of them already here, 3: new */
				echo '<li>' . esc_html( sprintf( __( 'Families: %1$d in the file: %2$d already here (will be updated), %3$d new.', 'geotour-genoroots' ), $r['families'], $r['families_found'], $r['families'] - $r['families_found'] ) ) . '</li>';
			}
			if ( $r['is_new'] ) {
				echo '<li>' . esc_html__( 'The collection will be created on import.', 'geotour-genoroots' ) . '</li>';
			}
			if ( $r['groups'] ) {
				$names = array();
				foreach ( $r['groups'] as $group => $known ) {
					$names[] = $known ? $group : $group . ' (' . __( 'new', 'geotour-genoroots' ) . ')';
				}
				/* translators: %s: comma-separated group names */
				echo '<li>' . esc_html( sprintf( __( 'Groups: %s', 'geotour-genoroots' ), implode( ', ', $names ) ) ) . '</li>';
			}
			echo '</ul>';
			if ( $r['additions'] ) {
				echo '<h3>' . esc_html__( 'Families already here that will gain', 'geotour-genoroots' ) . '</h3><ul class="ul-disc">';
				foreach ( $r['additions'] as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			}
			if ( $r['people_found'] === 0 && $r['people'] > 0 && $r['has_collection'] && ! $r['is_new'] ) {
				echo '<p>' . esc_html__( 'None of these people are in this collection yet. If you imported this file before, check that you chose the same collection; otherwise you will get a second copy.', 'geotour-genoroots' ) . '</p>';
			}
			self::render_problems( $r['problems'], $r['skipped_lines'] );
			if ( ! $r['problems'] ) {
				echo '<p class="gnr-admin__ok">' . esc_html__( 'No problems found. Untick "Only check the file" and upload it again to import.', 'geotour-genoroots' ) . '</p>';
			}
		} else {
			/* translators: %s: collection name */
			echo '<h2>' . esc_html( sprintf( __( 'Imported: %s', 'geotour-genoroots' ), $r['label'] ) ) . '</h2><ul class="ul-disc">';
			if ( $r['add_only'] ) {
				/* translators: 1: created count, 2: unchanged count */
				echo '<li>' . esc_html( sprintf( __( 'People: %1$d created, %2$d already here (left unchanged)', 'geotour-genoroots' ), $r['people_created'], $r['people_kept'] ) ) . '</li>';
				/* translators: 1: created count, 2: extended count, 3: unchanged count */
				echo '<li>' . esc_html( sprintf( __( 'Families: %1$d created, %2$d extended, %3$d unchanged', 'geotour-genoroots' ), $r['families_created'], $r['families_extended'], $r['families_kept'] ) ) . '</li></ul>';
			} else {
				/* translators: 1: created count, 2: updated count, 3: total in file */
				echo '<li>' . esc_html( sprintf( __( 'People: %1$d created, %2$d updated (%3$d total in file)', 'geotour-genoroots' ), $r['people_created'], $r['people_updated'], $r['people_total'] ) ) . '</li>';
				/* translators: 1: created count, 2: updated count, 3: total in file */
				echo '<li>' . esc_html( sprintf( __( 'Families: %1$d created, %2$d updated (%3$d total in file)', 'geotour-genoroots' ), $r['families_created'], $r['families_updated'], $r['families_total'] ) ) . '</li></ul>';
			}
			self::render_problems( array_merge( $r['missing_references'], $r['warnings'] ), $r['skipped_lines'] );
			echo '<p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . GenoRoots_Person_CPT::POST_TYPE ) ) . '">' . esc_html__( 'See the people', 'geotour-genoroots' ) . '</a> ';
			if ( ! empty( $r['collection'] ) ) {
				echo '<a class="button" href="' . esc_url( get_edit_term_link( $r['collection']->term_id, GenoRoots_Collection_Taxonomy::TAXONOMY ) ) . '">' . esc_html__( 'Edit the collection\'s description', 'geotour-genoroots' ) . '</a> ';
				$link = get_term_link( $r['collection'] );
				if ( ! is_wp_error( $link ) ) {
					echo '<a class="button" href="' . esc_url( $link ) . '">' . esc_html__( 'View the tree', 'geotour-genoroots' ) . '</a>';
				}
			}
			echo '</p>';
		}
		echo '</div>';
	}

	private static function render_problems( array $problems, array $skipped ) {
		if ( $problems ) {
			echo '<h3>' . esc_html__( 'Problems', 'geotour-genoroots' ) . '</h3><ul class="ul-disc">';
			foreach ( $problems as $message ) {
				echo '<li>' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $skipped ) {
			/* translators: %d: number of lines */
			echo '<h3>' . esc_html( sprintf( __( 'Lines that are not GEDCOM (skipped): %d', 'geotour-genoroots' ), count( $skipped ) ) ) . '</h3><ul class="ul-disc">';
			foreach ( array_slice( $skipped, 0, 20 ) as $line ) {
				echo '<li>' . esc_html( sprintf( '%d: %s', (int) $line['line'], $line['content'] ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	// ---- Help ----

	private static function render_help() {
		GenoRoots_Admin_Help::sections( __( 'Help', 'geotour-genoroots' ), array(
			array(
				'title' => __( 'Collections and IDs', 'geotour-genoroots' ),
				'body'  => array(
					__( 'Every person and family keeps the ID it has in the file (<code>@I08@</code> becomes <code>I08</code>). Imported into a collection, the ID gets the collection\'s prefix (<code>venizelos:I08</code>), so two files that both number their people I1, I2… never overwrite each other.', 'geotour-genoroots' ),
					__( 'A re-import finds people and families by these IDs and updates them. So import a file into the <strong>same collection every time</strong>. Importing it into another collection, or without one, creates a second copy.', 'geotour-genoroots' ),
					__( 'Use a collection for each separate family network; each gets its own page and its own groups explorer. A site with one tree (e.g. the families of one village) can do without collections.', 'geotour-genoroots' ),
					__( 'On sites linked to a photo archive, the numbers are the ones written in photo captions ("Name [384]"). They never change.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'What is read from the file', 'geotour-genoroots' ),
				'body'  => array(
					array(
						'table' => array(
							array( __( 'GEDCOM', 'geotour-genoroots' ), __( 'Stored as', 'geotour-genoroots' ) ),
							array(
								array( '<code>NAME</code>', __( 'Name: the first NAME line; slashes around the surname are removed. Later NAME lines are ignored.', 'geotour-genoroots' ) ),
								array( '<code>SEX</code>', __( 'Sex: M, F; anything else is Unknown.', 'geotour-genoroots' ) ),
								array( '<code>BIRT</code>, <code>DEAT</code> → <code>DATE</code>, <code>PLAC</code>', __( 'Birth and death: date and place.', 'geotour-genoroots' ) ),
								array( '<code>BURI</code> → <code>PLAC</code>', __( 'Burial place.', 'geotour-genoroots' ) ),
								array( '<code>NOTE</code> (+ <code>CONT</code>, <code>CONC</code>)', __( 'Notes. Several NOTE lines become paragraphs.', 'geotour-genoroots' ) ),
								array( '<code>SOUR</code>', __( 'Sources, one per line. Only written citations; pointers to source records (<code>@S1@</code>) are not followed.', 'geotour-genoroots' ) ),
								array( '<code>FAM</code>: <code>HUSB</code>, <code>WIFE</code>, <code>CHIL</code>', __( 'Families: the two spouses and the children.', 'geotour-genoroots' ) ),
								array( '<code>MARR</code> → <code>DATE</code>, <code>PLAC</code>', __( 'Marriage date and place.', 'geotour-genoroots' ) ),
							),
						),
					),
					__( 'Any other tag (occupations, residences, media…) is skipped without error.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'GenoRoots tags', 'geotour-genoroots' ),
				'body'  => array(
					__( 'Four extra tags carry what standard GEDCOM has no place for. Genealogy programs ignore them; GenoRoots reads them.', 'geotour-genoroots' ),
					array(
						'list' => array(
							__( '<code>_GROUP</code> on a person: a group they belong to, created if missing. One line per group; a person can be in the family they were born into and the one they married into.', 'geotour-genoroots' ),
							__( '<code>_PERIOD</code> on a person: the historical period of their life, e.g. "Late Ottoman period / Cretan insurrections".', 'geotour-genoroots' ),
							__( '<code>_ARTICLE</code> on a person: a page on this site about them, as an address without the domain (<code>people/eleftherios-venizelos</code>). The person\'s page then links to it.', 'geotour-genoroots' ),
							__( '<code>_STATUS</code> on a family: how certain the link is: <code>confirmed</code>, <code>probable</code> or <code>claimed</code> (family tradition). The last two are shown next to the relation.', 'geotour-genoroots' ),
						),
					),
					array( 'pre' => "0 @I05@ INDI\n1 NAME Nikos (Psaronikos) Xylouris\n1 SEX M\n1 BIRT\n2 DATE 7 JUL 1936\n2 PLAC Anogeia, Rethymno, Crete, Greece\n1 _PERIOD Civil War and post-war / Dictatorship\n1 NOTE First paragraph of the biography…\n1 SOUR Wikipedia (Greek), «Νίκος Ξυλούρης». https://el.wikipedia.org/…\n1 _GROUP Xylouris\n1 _ARTICLE people/nikos-xylouris\n1 FAMC @F03@\n0 @F03@ FAM\n1 HUSB @I02@\n1 WIFE @I04@\n1 CHIL @I05@\n1 _STATUS confirmed" ),
				),
			),
			array(
				'title' => __( 'Dates and names', 'geotour-genoroots' ),
				'body'  => array(
					__( 'Dates are kept exactly as written: <code>1908</code>, <code>DEC 1936</code>, <code>ABT 1850</code>, <code>BET 1850 AND 1855</code>. Nothing is guessed. On the site they are shown in the page language ("c. 1850", "Δεκέμβριος 1936").', 'geotour-genoroots' ),
					__( 'Names are shown as written. A good pattern: given name, any nickname in parentheses, surname; for a married woman, her married surname and her maiden surname in parentheses: <code>Eleni Venizelou (Nostraki)</code>. Use initials to tell namesakes apart: <code>Giorgos N. Xylouris</code>.', 'geotour-genoroots' ),
				),
			),
			array(
				'title' => __( 'What a re-import changes', 'geotour-genoroots' ),
				'body'  => array(
					array(
						'list' => array(
							__( '<strong>Always rewritten from the file:</strong> name, sex, birth, death, and each family\'s spouses, children and marriage.', 'geotour-genoroots' ),
							__( '<strong>Written only when the file has them:</strong> burial place, notes, sources, period, related article, certainty. A value you added by hand survives a re-import of a file that doesn\'t have it.', 'geotour-genoroots' ),
							__( '<strong>Groups</strong> are added, never removed. Take someone out of a group by hand.', 'geotour-genoroots' ),
							__( '<strong>Never touched:</strong> portraits, and people who are no longer in the file (delete those by hand).', 'geotour-genoroots' ),
							__( 'Imported people and families are published straight away.', 'geotour-genoroots' ),
						),
					),
				),
			),
			array(
				'title' => __( 'Extending a tree without changing it (add only)', 'geotour-genoroots' ),
				'body'  => array(
					__( 'Tick "Add only" to add new people to a tree that is already here, e.g. a new generation or a branch found later, while leaving everything already here exactly as it is, including corrections made by hand.', 'geotour-genoroots' ),
					array(
						'list' => array(
							__( '<strong>People already here</strong> (same ID in the same collection) are left unchanged. The file only needs their ID and name, so the new people can be attached to them.', 'geotour-genoroots' ),
							__( '<strong>Families already here</strong> only gain what they lack: new children, a spouse where the place is empty, a marriage date or place where it is empty. Nothing is removed or overwritten; a different spouse in the file is reported, not applied.', 'geotour-genoroots' ),
							__( '<strong>New people and families</strong> are created as usual.', 'geotour-genoroots' ),
						),
					),
					__( 'Use new IDs for new people, above the highest ID in the tree. The check lists what each existing family will gain, and warns when an ID in the file belongs to someone with a different name here: that usually means a numbering mistake.', 'geotour-genoroots' ),
					__( 'To correct existing people, edit them in the admin, or import without "Add only".', 'geotour-genoroots' ),
					array( 'pre' => "0 @I09@ INDI\n1 NAME Mikis Theodorakis\n1 FAMS @F05@\n0 @I20@ INDI\n1 NAME Name of a new child\n1 SEX F\n1 FAMC @F05@\n0 @F05@ FAM\n1 HUSB @I09@\n1 CHIL @I20@" ),
				),
			),
			array(
				'title' => __( 'After importing', 'geotour-genoroots' ),
				'body'  => array(
					array(
						'list' => array(
							__( 'Open a few people and check names, dates and relations.', 'geotour-genoroots' ),
							__( 'Give the collection a description (Collections, in this menu); it is shown on the tree\'s page.', 'geotour-genoroots' ),
							__( 'Add portraits in each person\'s Portrait box. They appear on the tree cards and inside the group circles.', 'geotour-genoroots' ),
							__( 'If the site is multilingual, translate the new people with the site\'s translation tool.', 'geotour-genoroots' ),
						),
					),
				),
			),
			array(
				'title' => __( 'Problems and what they mean', 'geotour-genoroots' ),
				'body'  => array(
					array(
						'list' => array(
							__( '<strong>A family names someone who is not in the file:</strong> that link is skipped. Add the person to the file, or import the file that has them first, then import again.', 'geotour-genoroots' ),
							__( '<strong>Related article not found:</strong> the <code>_ARTICLE</code> address does not exist on this site. The person is imported without the link; add it later on the person\'s page.', 'geotour-genoroots' ),
							__( '<strong>Lines that are not GEDCOM:</strong> usually harmless (empty or stray lines). If there are many, the file may be damaged.', 'geotour-genoroots' ),
							__( '<strong>Greek or other letters appear as symbols:</strong> the file is not UTF-8. Export it again as UTF-8.', 'geotour-genoroots' ),
							__( '<strong>Everyone appears twice:</strong> the file was imported earlier into another collection or without one. Delete the copy you don\'t want and always use the same collection.', 'geotour-genoroots' ),
						),
					),
				),
			),
			array(
				'title' => __( 'From the command line', 'geotour-genoroots' ),
				'body'  => array(
					__( 'For large files, or on a server, the same import runs with WP-CLI:', 'geotour-genoroots' ),
					array( 'pre' => 'wp genoroots import venizelos.ged --collection=venizelos --collection-name="Venizelos"' ),
					__( 'Keep GEDCOM files outside the website\'s folders: they contain personal data.', 'geotour-genoroots' ),
				),
			),
		) );
	}
}
