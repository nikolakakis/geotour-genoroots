<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translations. Source strings are English; languages/geotour-genoroots-el.po
 * carries the Greek. The site's language (Polylang sets it per page) picks
 * the catalogue, so the plugin needs no multilingual plugin of its own.
 *
 * After editing the .po:  wp i18n make-mo languages
 */
class GenoRoots_I18n {

	public static function init() {
		// Before ACF's acf/init (init:5), where the person fields are registered.
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'geotour-genoroots', false, dirname( plugin_basename( GENOROOTS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Strings for the tree and the groups explorer (src/js/i18n.js).
	 * Counted strings are sent as [ singular, plural ] with %d.
	 */
	public static function script_strings() {
		return array(
			// Tree.
			'profile'       => __( 'Full profile →', 'geotour-genoroots' ),
			'fullTree'      => __( 'Open in the full tree →', 'geotour-genoroots' ),
			'readMore'      => __( 'Read more →', 'geotour-genoroots' ),
			/* translators: %s: year of birth, for people still living */
			'bornYear'      => __( 'b. %s', 'geotour-genoroots' ),
			'photos'        => __( 'Photos ↗', 'geotour-genoroots' ),
			/* translators: %d: number of photos */
			'photosCount'   => __( 'Photos (%d) ↗', 'geotour-genoroots' ),
			'zoomIn'        => __( 'Zoom in', 'geotour-genoroots' ),
			'zoomOut'       => __( 'Zoom out', 'geotour-genoroots' ),
			'center'        => __( 'Back to the main person', 'geotour-genoroots' ),
			'fit'           => __( 'Show the whole tree', 'geotour-genoroots' ),
			'treeHint'      => __( 'Tap a person to bring them to the centre', 'geotour-genoroots' ),
			'moreUp'        => __( 'Parents not shown', 'geotour-genoroots' ),
			'moreDown'      => __( 'Spouses or children not shown', 'geotour-genoroots' ),
			'moreFamily'    => __( 'There are relatives not shown — tap to see them', 'geotour-genoroots' ),
			'loadError'     => __( 'Could not load. Please try again.', 'geotour-genoroots' ),
			'groups'        => __( 'Groups', 'geotour-genoroots' ),
			/* translators: %s: group name */
			'removeLens'    => __( 'Remove highlight: %s', 'geotour-genoroots' ),
			/* translators: %s: person name */
			'focusIn'       => __( 'belongs to this group: %s', 'geotour-genoroots' ),

			// Groups explorer.
			'allGroups'     => __( 'All groups', 'geotour-genoroots' ),
			'findGroup'     => __( 'Find a group…', 'geotour-genoroots' ),
			'smallGroups'   => __( 'Small groups', 'geotour-genoroots' ),
			'noGroups'      => __( 'There are no groups yet.', 'geotour-genoroots' ),
			'noMatch'       => __( 'No group matches.', 'geotour-genoroots' ),
			'groupsHint'    => __( 'Tap a group to see its members', 'geotour-genoroots' ),
			'members'       => array(
				_n( '%d member', '%d members', 1, 'geotour-genoroots' ),
				_n( '%d member', '%d members', 2, 'geotour-genoroots' ),
			),
			'shared'        => array(
				_n( '%d shared member', '%d shared members', 1, 'geotour-genoroots' ),
				_n( '%d shared member', '%d shared members', 2, 'geotour-genoroots' ),
			),
			// Short form on the overlap lens.
			'sharedShort'   => array(
				_x( '%d shared', 'lens label, one', 'geotour-genoroots' ),
				_x( '%d shared', 'lens label, many', 'geotour-genoroots' ),
			),
			'personTip'     => __( 'Click a person to open their family tree', 'geotour-genoroots' ),
			'relatedTitle'  => __( 'Members shared with', 'geotour-genoroots' ),
			'searchMembers' => __( 'Search members…', 'geotour-genoroots' ),
			'more'          => __( 'More', 'geotour-genoroots' ),
			'noMembers'     => __( 'No members.', 'geotour-genoroots' ),
			/* translators: %s: group name */
			'removeGroup'   => __( 'Remove: %s', 'geotour-genoroots' ),
			'close'         => __( 'Close', 'geotour-genoroots' ),
		);
	}
}
