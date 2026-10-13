<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage GeoTour GenoRoots data via WP-CLI.
 */
class GenoRoots_CLI {

	/**
	 * Import a GEDCOM 5.5 file into WordPress.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the .ged file to import.
	 *
	 * [--collection=<slug>]
	 * : Put everyone in this collection (created if missing) and keep the
	 * file's IDs apart from other files' ("slug:I1"). Use it for each
	 * separate family network on a site; leave it out for one shared tree.
	 *
	 * [--collection-name=<name>]
	 * : Name for a new collection. Default: the slug.
	 *
	 * [--add-only]
	 * : Extend the tree without changing it: people already here are left as
	 * they are (used only to attach new records), families already here only
	 * gain new children and empty fields, everything else is created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots import tests/fixtures/sample-family.ged
	 *     wp genoroots import venizelos.ged --collection=venizelos --collection-name="Venizelos"
	 *     wp genoroots import venizelos-additions.ged --collection=venizelos --add-only
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function import( $args, $assoc_args = array() ) {
		if ( ! class_exists( 'ACF' ) ) {
			\WP_CLI::error( 'GeoTour GenoRoots requires the free Advanced Custom Fields plugin to be installed and active.' );
			return;
		}

		list( $path ) = $args;

		if ( ! file_exists( $path ) ) {
			\WP_CLI::error( "File not found: {$path}" );
			return;
		}

		require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-person-cpt.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-family-cpt.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/content/fields-person.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/content/fields-family.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/content/relationships.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/import/class-gedcom-parser.php';
		require_once GENOROOTS_PLUGIN_DIR . 'includes/import/class-gedcom-importer.php';

		$collection = null;
		if ( ! empty( $assoc_args['collection'] ) ) {
			$slug       = sanitize_title( $assoc_args['collection'] );
			$collection = GenoRoots_Collection_Taxonomy::term( $slug );
			if ( ! $collection ) {
				$created = wp_insert_term( $assoc_args['collection-name'] ?? $assoc_args['collection'], GenoRoots_Collection_Taxonomy::TAXONOMY, array( 'slug' => $slug ) );
				if ( is_wp_error( $created ) ) {
					WP_CLI::error( 'Collection: ' . $created->get_error_message() );
				}
				$collection = get_term( $created['term_id'], GenoRoots_Collection_Taxonomy::TAXONOMY );
				WP_CLI::log( sprintf( 'Created collection "%s".', $collection->name ) );
			}
		}

		$add_only = ! empty( $assoc_args['add-only'] );
		$importer = new GenoRoots_Gedcom_Importer( $collection, $add_only );
		$report   = $importer->import_file( $path );

		if ( $add_only ) {
			\WP_CLI::success( sprintf(
				'Add only. People: %d created, %d already here (unchanged). Families: %d created, %d extended, %d unchanged.',
				$report['people_created'],
				$report['people_kept'],
				$report['families_created'],
				$report['families_extended'],
				$report['families_kept']
			) );
		} else {
			\WP_CLI::success( sprintf(
				'People: %d created, %d updated. Families: %d created, %d updated.',
				$report['people_created'],
				$report['people_updated'],
				$report['families_created'],
				$report['families_updated']
			) );
		}

		if ( ! empty( $report['skipped_lines'] ) ) {
			\WP_CLI::warning( count( $report['skipped_lines'] ) . ' unparseable line(s) skipped.' );
		}
		foreach ( array_merge( $report['missing_references'], $report['warnings'] ) as $message ) {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Export people and families as a GEDCOM 5.5 file.
	 *
	 * Same dialect as `import` reads (_PERIOD, _GROUP, _ARTICLE, _STATUS), so
	 * the file can be imported again here or on another site. Published
	 * records only. The file contains personal data: keep it out of any
	 * web-served folder.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Where to write the .ged. Default: standard output.
	 *
	 * [--collection=<slug>]
	 * : Only this collection's people.
	 *
	 * [--group=<slug>]
	 * : Only this group's members (within the collection, if one is given).
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots export ~/venizelos.ged --collection=venizelos
	 *     wp genoroots export ~/mitsotakis.ged --collection=venizelos --group=mitsotakis
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function export( $args, $assoc_args = array() ) {
		$collection = null;
		$group      = null;
		if ( ! empty( $assoc_args['collection'] ) ) {
			$collection = GenoRoots_Gedcom_Exporter::collection_term( $assoc_args['collection'] );
			if ( ! $collection ) {
				WP_CLI::error( "No such collection: {$assoc_args['collection']}" );
			}
		}
		if ( ! empty( $assoc_args['group'] ) ) {
			$group = GenoRoots_Gedcom_Exporter::group_term( $assoc_args['group'] );
			if ( ! $group ) {
				WP_CLI::error( "No such group: {$assoc_args['group']}" );
			}
		}

		$exporter = new GenoRoots_Gedcom_Exporter( $collection, $group );
		$result   = $exporter->export();
		if ( ! $result['people'] ) {
			WP_CLI::error( 'Nobody matches this selection.' );
		}

		if ( empty( $args[0] ) ) {
			echo $result['gedcom']; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		if ( false === file_put_contents( $args[0], $result['gedcom'] ) ) {
			WP_CLI::error( "Could not write {$args[0]}" );
		}
		WP_CLI::success( sprintf( '%d people, %d families → %s', $result['people'], $result['families'], $args[0] ) );
	}

	/**
	 * Export all GenoRoots data to a portable JSON bundle.
	 *
	 * Groups, people, families, their fields and group memberships, keyed so
	 * another site can import them. The file contains personal data: keep it
	 * out of any web-served folder.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Where to write the bundle (.json).
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots export-bundle ~/genoroots-bundle.json
	 *
	 * @subcommand export-bundle
	 */
	public function export_bundle( $args ) {
		$this->require_loaded();
		list( $path ) = $args;
		try {
			$result = GenoRoots_Bundle::export();
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}

		$json = wp_json_encode( $result['bundle'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::error( "Could not write {$path}" );
		}

		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		$counts = $result['bundle']['counts'];
		\WP_CLI::success( sprintf(
			'Exported %d groups, %d people, %d families, %d group memberships to %s (checksum %s…).',
			$counts['groups'],
			$counts['people'],
			$counts['families'],
			$counts['memberships'],
			$path,
			substr( $result['bundle']['checksum'], 0, 12 )
		) );
	}

	/**
	 * Import a bundle made by export-bundle.
	 *
	 * Creates or updates groups, people and families, re-links them to this
	 * site's post IDs and rebuilds the family links the tree reads. Safe to
	 * run again; nothing on this site is deleted.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The bundle (.json).
	 *
	 * [--dry-run]
	 * : Report what would be created or updated; write nothing.
	 *
	 * [--rehearse]
	 * : Do the whole import and verify it inside a transaction, then roll
	 * everything back. The real thing, minus the commit.
	 *
	 * [--skip-groups]
	 * : Leave groups and group memberships on this site as they are.
	 *
	 * [--only-groups]
	 * : Apply only the bundle's groups and the memberships of people who are
	 * already on this site; people and families are not touched.
	 *
	 * [--no-transaction]
	 * : Write as you go instead of all-or-nothing (only if needed).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots import-bundle ~/genoroots-bundle.json --dry-run
	 *     wp genoroots import-bundle ~/genoroots-bundle.json --rehearse
	 *     wp genoroots import-bundle ~/genoroots-bundle.json
	 *     wp genoroots import-bundle ~/curated-groups.json --only-groups
	 *
	 * @subcommand import-bundle
	 */
	public function import_bundle( $args, $assoc_args ) {
		$this->require_loaded();
		list( $path ) = $args;
		$this->run_import( $this->read_bundle( $path ), $assoc_args, 'wp genoroots verify-bundle ' . $path );
	}

	/**
	 * Convert the "Genealogical Tree" plugin's people, families and groups
	 * on this site into GenoRoots.
	 *
	 * Reads gt-member, gt-family and gt-family-group straight from the
	 * database (the old plugin may be active or not) and leaves them
	 * untouched. People keep their number as GenoRoots ID (member 384 →
	 * I384), so photo keywords like "Name [384]" and Piwigo tags stay
	 * valid. Copies birth/death/marriage dates and places, notes, portraits
	 * (featured images), photo-archive links and Piwigo tag IDs. Safe to run
	 * again: it updates in place.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be created or updated; write nothing.
	 *
	 * [--rehearse]
	 * : Do the whole conversion and verify it inside a transaction, then
	 * roll everything back.
	 *
	 * [--skip-groups]
	 * : Don't copy the old plugin's family groups (e.g. when curated groups
	 * come from a bundle with --only-groups afterwards).
	 *
	 * [--save-bundle=<file>]
	 * : Also write the converted data as a bundle file (for verify-bundle,
	 * or to import it on another site).
	 *
	 * [--no-transaction]
	 * : Write as you go instead of all-or-nothing (only if needed).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots import-legacy --dry-run
	 *     wp genoroots import-legacy --rehearse
	 *     wp genoroots import-legacy --save-bundle=~/legacy.json
	 *
	 * @subcommand import-legacy
	 */
	public function import_legacy( $args, $assoc_args ) {
		$this->require_loaded();
		if ( ! GenoRoots_Legacy_GTree::is_present() ) {
			\WP_CLI::error( 'No Genealogical Tree members (gt-member) found on this site.' );
		}
		$result = GenoRoots_Legacy_GTree::build_bundle();
		foreach ( $result['notes'] as $note ) {
			\WP_CLI::warning( $note );
		}

		$next = 'check it at ' . home_url( '/' . GenoRoots_Slugs::get( 'people' ) . '/' );
		$save = \WP_CLI\Utils\get_flag_value( $assoc_args, 'save-bundle', '' );
		if ( $save ) {
			$json = wp_json_encode( $result['bundle'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === file_put_contents( $save, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				\WP_CLI::error( "Could not write {$save}" );
			}
			\WP_CLI::log( "Converted data saved to {$save}." );
			$next = 'wp genoroots verify-bundle ' . $save . ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-groups', false ) ? ' --skip-groups' : '' );
		}

		$this->run_import( $result['bundle'], $assoc_args, $next );
	}

	/**
	 * Check this site against a bundle, record by record.
	 *
	 * Groups, people, fields, memberships, family links and the tree's
	 * back-references. Records here that are not in the bundle are listed,
	 * not changed.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The bundle (.json).
	 *
	 * [--skip-groups]
	 * : Don't compare groups and memberships.
	 *
	 * [--only-groups]
	 * : Compare only groups and memberships.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots verify-bundle ~/genoroots-bundle.json
	 *
	 * @subcommand verify-bundle
	 */
	public function verify_bundle( $args, $assoc_args ) {
		$this->require_loaded();
		list( $path ) = $args;
		$bundle       = $this->read_bundle( $path );
		try {
			$result = GenoRoots_Bundle::verify( $bundle, array(
				'skip_groups' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-groups', false ),
				'only_groups' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-groups', false ),
			) );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		$this->print_verify( $result );
		if ( $result['errors'] ) {
			\WP_CLI::error( sprintf( '%d difference(s) found.', count( $result['errors'] ) ) );
		}
		\WP_CLI::success( sprintf(
			'This site matches the bundle: %d groups, %d people, %d families.',
			$result['checked']['groups'],
			$result['checked']['people'],
			$result['checked']['families']
		) );
	}

	/**
	 * Check that this site is ready for GenoRoots and a bundle import.
	 *
	 * PHP/WordPress/ACF versions, InnoDB (needed for all-or-nothing import
	 * and --rehearse), permalinks, URL clashes with /mele/, /melos/ and
	 * /oikogeneia/, existing GenoRoots data, caches to purge afterwards.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots preflight
	 */
	public function preflight() {
		global $wpdb, $wp_rewrite;
		$rows = array();
		$add  = function ( $check, $status, $detail ) use ( &$rows ) {
			$rows[] = array( 'check' => $check, 'status' => $status, 'detail' => $detail );
		};

		$add( 'PHP', version_compare( PHP_VERSION, '8.2', '>=' ) ? 'ok' : 'FAIL', PHP_VERSION . ' (plugin needs 8.2+)' );
		$add( 'WordPress', version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ? 'ok' : 'FAIL', get_bloginfo( 'version' ) . ' (needs 6.0+)' );
		$add( 'ACF', class_exists( 'ACF' ) ? 'ok' : 'FAIL', class_exists( 'ACF' ) ? 'Advanced Custom Fields ' . ( defined( 'ACF_VERSION' ) ? ACF_VERSION : '' ) : 'install and activate Advanced Custom Fields' );

		$tables  = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options );
		$engines = $wpdb->get_results( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode( "','", array_map( 'esc_sql', $tables ) ) . "')" ); // phpcs:ignore
		$bad     = array();
		foreach ( $engines as $engine ) {
			if ( strcasecmp( (string) $engine->ENGINE, 'InnoDB' ) !== 0 ) {
				$bad[] = $engine->TABLE_NAME . '=' . $engine->ENGINE;
			}
		}
		$add( 'InnoDB tables', $bad ? 'warn' : 'ok', $bad ? 'no transactions / --rehearse: ' . implode( ', ', $bad ) : 'all-or-nothing import and --rehearse available' );

		$add( 'Permalinks', get_option( 'permalink_structure' ) ? 'ok' : 'FAIL', get_option( 'permalink_structure' ) ?: 'plain permalinks: the family-tree addresses will not work' );

		foreach ( array( 'people', 'person', 'group', 'collection' ) as $which ) {
			$slug = GenoRoots_Slugs::get( $which );
			$clash = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status IN ('publish','private','draft') AND post_type NOT IN ('genoroots_person','genoroots_family','revision','nav_menu_item')",
				$slug
			) );
			$add( "URL /{$slug}/", $clash ? 'warn' : 'ok', $clash
				? sprintf( 'already used by %s; GenoRoots will take over this URL', implode( ', ', array_map( function ( $c ) { return "{$c->post_type} #{$c->ID}"; }, $clash ) ) )
				: 'free' );
		}

		$people   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'genoroots_person' AND post_status <> 'auto-draft'" );
		$families = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'genoroots_family' AND post_status <> 'auto-draft'" );
		$groups   = taxonomy_exists( 'genoroots_family_group' )
			? (int) wp_count_terms( array( 'taxonomy' => 'genoroots_family_group', 'hide_empty' => false ) )
			: 0;
		$add( 'Existing GenoRoots data', $people || $families || $groups ? 'info' : 'ok', sprintf( '%d people, %d families, %d groups (import matches and updates these)', $people, $families, $groups ) );

		$rules = get_option( 'rewrite_rules' );
		$people_rule = GenoRoots_Slugs::get( 'people' ) . '/?$';
		$registered  = is_array( $rules ) && isset( $rules[ $people_rule ] );
		$add( 'Rewrite rules', $registered ? 'ok' : 'warn', $registered ? '/' . GenoRoots_Slugs::get( 'people' ) . '/ registered' : 'run: wp rewrite flush' );

		$add( 'Object cache', wp_using_ext_object_cache() ? 'info' : 'ok', wp_using_ext_object_cache() ? 'persistent cache: run wp cache flush after importing' : 'none' );

		$page_caches = array_filter( array(
			'WP Super Cache' => function_exists( 'wp_cache_clear_cache' ),
			'W3 Total Cache' => function_exists( 'w3tc_flush_all' ),
			'LiteSpeed'      => defined( 'LSCWP_V' ),
			'WP Rocket'      => function_exists( 'rocket_clean_domain' ),
		) );
		$add( 'Page cache', $page_caches ? 'info' : 'ok', $page_caches ? implode( ', ', array_keys( $page_caches ) ) . ': purge after importing' : 'none detected' );

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'detail' ) );

		$failed = array_filter( $rows, function ( $row ) {
			return $row['status'] === 'FAIL';
		} );
		if ( $failed ) {
			\WP_CLI::error( 'Not ready: fix the FAIL rows first.' );
		}
		\WP_CLI::success( 'Ready for: wp genoroots import-bundle <file> --dry-run' );
	}

	/**
	 * Link people to their photos in a Piwigo gallery.
	 *
	 * Reads the gallery's tags (public API, read-only) and matches each tag's
	 * "[N]" to the person with GenoRoots ID IN, e.g. "Μαρία Παπαδάκη
	 * [384]" → I384. Updates each person's Piwigo tag ID, photo-archive link
	 * and photo count; clears them for people with no tag. Reports several
	 * tags for one person, and numbers that are not in the tree.
	 *
	 * ## OPTIONS
	 *
	 * [--piwigo=<address>]
	 * : Gallery address; saved as the setting unless --dry-run. (Not --url:
	 * that is WP-CLI's own option for choosing the site.)
	 *
	 * [--dry-run]
	 * : Show what would change; write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp genoroots piwigo-sync --piwigo=https://pics.example.org --dry-run
	 *     wp genoroots piwigo-sync
	 *
	 * @subcommand piwigo-sync
	 */
	public function piwigo_sync( $args, $assoc_args ) {
		$this->require_loaded();
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$url     = untrailingslashit( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'piwigo', GenoRoots_Piwigo::base_url() ) );

		try {
			$tags = GenoRoots_Piwigo::fetch_tags( $url );
			$plan = GenoRoots_Piwigo::plan( $tags );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		\WP_CLI::log( sprintf( '%s: %d tags (%d without a [number]).', $url, count( $tags ), $plan['without_id'] ) );

		foreach ( $plan['conflicts'] as $line ) {
			\WP_CLI::warning( $line );
		}
		foreach ( $plan['unknown'] as $line ) {
			\WP_CLI::warning( $line );
		}

		$rows = array();
		foreach ( $plan['changes'] as $person_id => $change ) {
			$rows[] = array(
				'person' => $change['title'],
				'tag'    => ( $change['from']['piwigo_tag_id'] ?: '-' ) . ' → ' . ( $change['to']['piwigo_tag_id'] ?: '-' ),
				'photos' => $change['to']['piwigo_photo_count'] ?: '0',
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'person', 'tag', 'photos' ) );
		}

		$summary = sprintf( '%d of %d people have photos; %d to update.', $plan['linked'], $plan['people'], count( $plan['changes'] ) );
		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run: ' . $summary . ' Nothing was written.' );
			return;
		}
		if ( $url !== GenoRoots_Piwigo::base_url() ) {
			update_option( GenoRoots_Piwigo::OPTION, $url );
		}
		GenoRoots_Piwigo::apply( $plan );
		\WP_CLI::success( $summary . ' Saved.' );
	}

	/** Shared by import-bundle and import-legacy: confirm, import, report. */
	private function run_import( array $bundle, array $assoc_args, $next_step ) {
		$dry_run     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$rehearse    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'rehearse', false );
		$skip_groups = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-groups', false );
		$only_groups = \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-groups', false );
		if ( $skip_groups && $only_groups ) {
			\WP_CLI::error( '--skip-groups and --only-groups cannot be used together.' );
		}

		\WP_CLI::log( sprintf(
			'Source: %s (%s): %d groups, %d people, %d families%s.',
			$bundle['source'],
			$bundle['exported_at'],
			$bundle['counts']['groups'],
			$bundle['counts']['people'],
			$bundle['counts']['families'],
			$skip_groups ? ' — groups skipped' : ( $only_groups ? ' — groups and memberships only' : '' )
		) );

		if ( ! $dry_run && ! $rehearse ) {
			\WP_CLI::confirm( sprintf( 'Import into %s?', home_url() ), $assoc_args );
		}

		GenoRoots_Bundle::set_logger( function ( $message ) {
			\WP_CLI::log( $message );
		} );

		try {
			$report = GenoRoots_Bundle::import( $bundle, array(
				'dry_run'     => $dry_run,
				'rehearse'    => $rehearse,
				'skip_groups' => $skip_groups,
				'only_groups' => $only_groups,
				'transaction' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'transaction', true ),
			) );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( 'Import failed, nothing was saved: ' . $error->getMessage() );
		}

		\WP_CLI\Utils\format_items( 'table', array(
			array( 'what' => 'groups', 'create' => $report['groups']['create'], 'update' => $report['groups']['update'] ),
			array( 'what' => 'people', 'create' => $report['people']['create'], 'update' => $report['people']['update'] ),
			array( 'what' => 'families', 'create' => $report['families']['create'], 'update' => $report['families']['update'] ),
		), array( 'what', 'create', 'update' ) );

		foreach ( array_unique( $report['warnings'] ) as $warning ) {
			\WP_CLI::warning( $warning );
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run: nothing was written.' );
			return;
		}
		if ( $rehearse ) {
			$this->print_verify( $report['verify'] );
			if ( $report['verify']['errors'] ) {
				\WP_CLI::error( 'Rehearsal found problems (all changes were rolled back).' );
			}
			\WP_CLI::success( 'Rehearsal passed: the import works on this site. All changes were rolled back.' );
			return;
		}
		\WP_CLI::success( 'Imported. Next: ' . $next_step );
	}

	/** The plugin only loads its classes when ACF is active. */
	private function require_loaded() {
		if ( ! class_exists( 'GenoRoots_Bundle' ) ) {
			WP_CLI::error( 'GenoRoots is not loaded: install and activate Advanced Custom Fields, then run `wp genoroots preflight`.' );
		}
	}

	private function read_bundle( $path ) {
		if ( ! is_readable( $path ) ) {
			\WP_CLI::error( "Cannot read {$path}" );
		}
		$bundle   = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$problems = GenoRoots_Bundle::validate( $bundle );
		if ( $problems ) {
			\WP_CLI::error( implode( ' ', $problems ) );
		}
		return $bundle;
	}

	private function print_verify( array $result ) {
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		foreach ( array_slice( $result['errors'], 0, 50 ) as $error ) {
			\WP_CLI::log( \WP_CLI::colorize( '%R✗%n ' ) . $error );
		}
		if ( count( $result['errors'] ) > 50 ) {
			\WP_CLI::log( sprintf( '… and %d more.', count( $result['errors'] ) - 50 ) );
		}
	}
}

\WP_CLI::add_command( 'genoroots', 'GenoRoots_CLI' );
