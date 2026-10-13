<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words in GenoRoots' public addresses, in one place.
 *
 * Set under People → Settings → Addresses (option `genoroots_slugs`). A new
 * install starts with English words. A site that ran GenoRoots before the
 * words became a setting keeps the Greek ones it had (see seed()).
 *
 * The `genoroots_slugs` filter still runs last, so a theme can override
 * the setting:
 *
 *   add_filter( 'genoroots_slugs', fn( $s ) => array_merge( $s, array(
 *       'people' => 'family-trees', 'person' => 'person',
 *       'group'  => 'family',       'collection' => 'family-trees/c',
 *   ) ) );
 *
 * Rewrite rules are re-flushed automatically when the words change.
 */
class GenoRoots_Slugs {

	const OPTION = 'genoroots_slugs';

	/** New installs. */
	const DEFAULTS = array(
		'people'     => 'family-tree',  // the archive: groups explorer + tree
		'person'     => 'person',       // one person's page
		'group'      => 'family-group', // a group's term page
		'collection' => 'collection',   // a collection's own explorer + tree
	);

	/** The words every site used before they became a setting (Kamilari's). */
	const LEGACY = array(
		'people'     => 'mele',
		'person'     => 'melos',
		'group'      => 'oikogeneia',
		'collection' => 'syllogi',
	);

	/** @var array|null */
	private static $slugs = null;

	/**
	 * Saves the words on the first run of 1.0+. Before 1.0 they were fixed to
	 * LEGACY, so a site that already ran the plugin (it has a rewrite stamp)
	 * gets LEGACY and its addresses don't change on update; a new install
	 * gets DEFAULTS.
	 */
	public static function seed() {
		if ( get_option( self::OPTION, null ) === null ) {
			$existing = get_option( 'genoroots_rewrite_version', null ) !== null;
			update_option( self::OPTION, $existing ? self::LEGACY : self::DEFAULTS );
		}
	}

	/** The saved words, filled in from DEFAULTS where a word is missing. */
	public static function saved() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array();
		return array_merge( self::DEFAULTS, array_filter( $saved, 'strlen' ) );
	}

	public static function all() {
		if ( self::$slugs === null ) {
			self::$slugs = (array) apply_filters( 'genoroots_slugs', self::saved() );
		}
		return self::$slugs;
	}

	public static function get( $key ) {
		$slugs = self::all();
		return isset( $slugs[ $key ] ) ? trim( (string) $slugs[ $key ], '/' ) : $key;
	}

	/** Changes whenever any address word changes (drives the rewrite flush). */
	public static function signature() {
		return md5( wp_json_encode( self::all() ) );
	}

	/**
	 * Settings sanitizer: each word is a lowercase slug ("collection" may
	 * have a "/" for a nested address); blanks fall back to the default;
	 * two words may not be the same.
	 */
	public static function sanitize( $value ) {
		$value = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$parts = array_filter( array_map( 'sanitize_title', explode( '/', (string) ( $value[ $key ] ?? '' ) ) ), 'strlen' );
			$word  = implode( $key === 'collection' ? '/' : '-', $parts );
			$clean[ $key ] = $word !== '' ? $word : $default;
		}
		if ( count( array_unique( $clean ) ) < count( $clean ) ) {
			add_settings_error( self::OPTION, 'genoroots-slugs-duplicate', __( 'Each address word must be different. Nothing was changed.', 'geotour-genoroots' ) );
			return self::saved();
		}
		return $clean;
	}
}
