<?php
/**
 * Plugin Name:       GeoTour GenoRoots
 * Plugin URI:        https://github.com/nikolakakis/geotour-genoroots
 * Description:       Family trees for WordPress: GEDCOM 5.5 import and export, a groups explorer and an interactive tree, person pages, and conversion from Genealogical Tree.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Manolis Nikolakakis
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       geotour-genoroots
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GENOROOTS_VERSION', '1.0.0' );
// Bump whenever a CPT's or taxonomy's public/rewrite settings change, so
// genoroots_maybe_flush_rewrites() re-flushes on an already-active install.
define( 'GENOROOTS_REWRITE_VERSION', 4 ); // 4: collections
define( 'GENOROOTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GENOROOTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GENOROOTS_PLUGIN_FILE', __FILE__ );

/**
 * Everything here needs ACF (free tier — Post Object/Relationship fields
 * are enough, no Pro license required). Bail with an admin notice instead
 * of fataling on a site that hasn't installed it yet.
 */
function genoroots_init() {
	if ( ! class_exists( 'ACF' ) ) {
		add_action( 'admin_notices', 'genoroots_missing_acf_notice' );
		return;
	}

	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-i18n.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-slugs.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-person-cpt.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-family-cpt.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-family-group-taxonomy.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/class-genoroots-collection-taxonomy.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/fields-person.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/fields-family.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/relationships.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/relatives.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/class-genoroots-ids.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/class-genoroots-related.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/content/class-genoroots-dates.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/import/class-gedcom-parser.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/import/class-gedcom-importer.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-admin-help.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-import-page.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/export/class-gedcom-exporter.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-export-page.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/rest/class-genoroots-rest-controller.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/rest/class-genoroots-groups-controller.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/sync/class-genoroots-bundle.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/sync/class-genoroots-legacy-gtree.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/integrations/class-genoroots-piwigo.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-settings-page.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-person-admin.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/admin/class-genoroots-relations-admin.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/frontend/class-genoroots-frontend.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/frontend/class-genoroots-shortcode.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/frontend/class-genoroots-legacy-redirects.php';
	require_once GENOROOTS_PLUGIN_DIR . 'includes/template-loader.php';

	GenoRoots_Slugs::seed();
	GenoRoots_I18n::init();
	GenoRoots_Person_CPT::init();
	GenoRoots_Family_CPT::init();
	GenoRoots_Family_Group_Taxonomy::init();
	GenoRoots_Collection_Taxonomy::init();
	GenoRoots_Relationships::init();
	GenoRoots_Import_Page::init();
	GenoRoots_Export_Page::init();
	GenoRoots_Settings_Page::init();
	GenoRoots_Person_Admin::init();
	GenoRoots_Relations_Admin::init();
	GenoRoots_Ids::init();
	GenoRoots_Related::init();
	GenoRoots_Rest_Controller::init();
	GenoRoots_Groups_Controller::init();
	GenoRoots_Frontend::init();
	GenoRoots_Shortcode::init();
	GenoRoots_Legacy_Redirects::init();
}
add_action( 'plugins_loaded', 'genoroots_init' );

/**
 * register_activation_hook() only fires on activation — it won't re-run
 * when a CPT's public/rewrite settings change on an already-active
 * install, so new public URLs would 404 until a manual
 * deactivate/reactivate. This flushes once whenever
 * GENOROOTS_REWRITE_VERSION is bumped or a site renames the address words
 * (Settings → Addresses, or the genoroots_slugs filter).
 */
function genoroots_maybe_flush_rewrites() {
	if ( ! class_exists( 'GenoRoots_Slugs' ) ) {
		return; // ACF missing: nothing registered.
	}
	$want = GENOROOTS_REWRITE_VERSION . ':' . GenoRoots_Slugs::signature();
	if ( (string) get_option( 'genoroots_rewrite_version' ) !== $want ) {
		flush_rewrite_rules();
		update_option( 'genoroots_rewrite_version', $want );
	}
}
add_action( 'init', 'genoroots_maybe_flush_rewrites', 20 );

function genoroots_missing_acf_notice() {
	$install_url = self_admin_url( 'plugin-install.php?s=advanced-custom-fields&tab=search&type=term' );
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: link to install ACF */
				esc_html__( 'GeoTour GenoRoots requires the free Advanced Custom Fields plugin. %s', 'geotour-genoroots' ),
				'<a href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install it now', 'geotour-genoroots' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once GENOROOTS_PLUGIN_DIR . 'includes/cli/class-genoroots-cli.php';
}

function genoroots_activate() {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'genoroots_activate' );

function genoroots_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'genoroots_deactivate' );
