<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenoRoots_Frontend {

	const HANDLE = 'genoroots-main';

	private static $enqueued = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_filter( 'post_type_archive_title', array( __CLASS__, 'archive_title' ), 10, 2 );
		add_filter( 'get_the_archive_title', array( __CLASS__, 'collection_title' ) );
	}

	/** A collection page is titled by its name, without "Collection:" in front. */
	public static function collection_title( $title ) {
		return is_tax( GenoRoots_Collection_Taxonomy::TAXONOMY ) ? single_term_title( '', false ) : $title;
	}

	/** The /mele/ page is the family tree, not a list of "People" (the admin label). */
	public static function archive_title( $title, $post_type ) {
		return $post_type === GenoRoots_Person_CPT::POST_TYPE
			? __( 'Family Tree', 'geotour-genoroots' )
			: $title;
	}

	/**
	 * Where "Open in the full tree" and ?person= links go: the collection's
	 * page when there is one (the collection page itself, or a person's
	 * collection), else the main /mele/ page.
	 */
	public static function full_tree_url() {
		$collection = null;
		if ( is_tax( GenoRoots_Collection_Taxonomy::TAXONOMY ) ) {
			$collection = get_queried_object();
		} elseif ( is_singular( GenoRoots_Person_CPT::POST_TYPE ) ) {
			$collection = GenoRoots_Collection_Taxonomy::of_person( get_queried_object_id() );
		}
		if ( $collection instanceof WP_Term ) {
			$link = get_term_link( $collection );
			if ( ! is_wp_error( $link ) ) {
				return $link;
			}
		}
		return get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE );
	}

	/** Auto-enqueue on the plugin's own templates. */
	public static function maybe_enqueue() {
		if ( is_singular( GenoRoots_Person_CPT::POST_TYPE ) || is_post_type_archive( GenoRoots_Person_CPT::POST_TYPE ) || is_tax( GenoRoots_Collection_Taxonomy::TAXONOMY ) ) {
			self::enqueue();
		}
	}

	/**
	 * Ensures the bundle is enqueued regardless of the current page —
	 * called directly by the [genoroots_tree] shortcode, which can appear
	 * on any post/page. Idempotent: wp_enqueue_script/style dedupe by
	 * handle, and the internal flag skips repeat wp_localize_script calls.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$manifest_path = GENOROOTS_PLUGIN_DIR . 'build/.vite/manifest.json';
		$js_path       = 'build/main.js';
		$css_path      = 'build/main.css';

		if ( file_exists( $manifest_path ) ) {
			$manifest = json_decode( file_get_contents( $manifest_path ), true );
			$entry    = $manifest['src/index.js'] ?? null;
			if ( $entry ) {
				$js_path  = 'build/' . $entry['file'];
				$css_path = ! empty( $entry['css'][0] ) ? 'build/' . $entry['css'][0] : $css_path;
			}
		}

		// The bundle's file name never changes, so version by build time or
		// browsers keep serving the previous build.
		$js_version  = (string) ( @filemtime( GENOROOTS_PLUGIN_DIR . $js_path ) ?: GENOROOTS_VERSION );
		$css_version = (string) ( @filemtime( GENOROOTS_PLUGIN_DIR . $css_path ) ?: GENOROOTS_VERSION );

		wp_enqueue_script( self::HANDLE, GENOROOTS_PLUGIN_URL . $js_path, array(), $js_version, true );
		wp_enqueue_style( self::HANDLE, GENOROOTS_PLUGIN_URL . $css_path, array(), $css_version );

		wp_localize_script( self::HANDLE, 'GenoRootsAPI', array(
			'root'       => esc_url_raw( rest_url() ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'archiveUrl' => self::full_tree_url(),
			'i18n'       => GenoRoots_I18n::script_strings(),
			// How names are stored, so cards can show given name and surname:
			// 'surname-first' ("Νικολακάκης Μανώλης") or 'given-first' ("Eleftherios Venizelos").
			'nameOrder'  => apply_filters( 'genoroots_name_order', 'surname-first' ) === 'given-first' ? 'given-first' : 'surname-first',
		) );
	}
}
