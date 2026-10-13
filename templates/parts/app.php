<?php
/**
 * The groups explorer + tree app, with its name search. Used by the
 * /mele/ archive template and the [genoroots_app] shortcode.
 *
 * ?person=ID opens the tree; otherwise the groups explorer (?groups=a,b
 * preselects groups; old ?group=slug links land on that group).
 *
 * Set $genoroots_collection (a collection slug) before including to scope
 * the explorer and the search to one collection.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$genoroots_person_param = isset( $_GET['person'] ) ? absint( $_GET['person'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

/**
 * The name search box. A site can turn it off, e.g. one in several languages
 * where names are translated but the search only knows the stored spelling:
 *   add_filter( 'genoroots_name_search', '__return_false' );
 */
if ( apply_filters( 'genoroots_name_search', true ) ) :
	?>
	<div class="gnr-search">
		<input
			type="search"
			id="genoroots-search-input"
			class="gnr-search__input"
			placeholder="<?php esc_attr_e( 'Search for a name…', 'geotour-genoroots' ); ?>"
			autocomplete="off"
		>
		<ul id="genoroots-search-results" class="gnr-search__results" hidden></ul>
	</div>
	<?php
endif;
?>

<div class="gnr-app" data-collection="<?php echo esc_attr( isset( $genoroots_collection ) ? (string) $genoroots_collection : '' ); ?>">
	<section class="gnr-app__view gnr-app-shell" data-view="groups" <?php echo $genoroots_person_param ? 'hidden' : ''; ?>>
		<div class="gnr-explorer"></div>
	</section>
	<section class="gnr-app__view gnr-app-shell" data-view="tree" <?php echo $genoroots_person_param ? '' : 'hidden'; ?>>
		<div class="gnr-focus-chart" data-app-managed="1"></div>
	</section>
</div>
