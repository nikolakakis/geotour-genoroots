<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permanent redirects from the "Genealogical Tree" plugin's URLs, so old
 * links and search results keep working after that plugin is deactivated:
 *
 *   /gt-member/<slug>/        → the person's /melos/ page (matched by old
 *                               post ID → GenoRoots ID, then by slug)
 *   /gt-family-group/<slug>/  → /mele/?groups=<slug>
 *   /gt-tree/…, anything else → /mele/
 *
 * Inactive while the old plugin is active (its post type is registered),
 * so switching over is just deactivating it.
 */
class GenoRoots_Legacy_Redirects {

	const OLD_TYPE = 'gt-member';
	const BASES    = array( 'gt-member', 'gt-family-group', 'gt-tree', 'gt-family' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	public static function maybe_redirect() {
		if ( post_type_exists( self::OLD_TYPE ) ) {
			return; // the old plugin is still active and serves its own pages
		}

		// Plain-permalink form: ?gt-member=<slug>.
		foreach ( self::BASES as $base ) {
			if ( isset( $_GET[ $base ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::go( self::target( $base, sanitize_text_field( wp_unslash( $_GET[ $base ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		$path  = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$parts = array_values( array_filter( array_map( 'urldecode', explode( '/', $path ) ), 'strlen' ) );
		if ( count( $parts ) > 1 && preg_match( '/^[a-z]{2}$/', $parts[0] ) && in_array( $parts[1], self::BASES, true ) ) {
			array_shift( $parts ); // language prefix (Polylang), e.g. /en/gt-member/…
		}
		if ( ! $parts || ! in_array( $parts[0], self::BASES, true ) ) {
			return;
		}
		self::go( self::target( $parts[0], $parts[1] ?? '' ) );
	}

	/** Where an old URL goes now. */
	public static function target( $base, $slug ) {
		$tree = get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE );

		if ( $base === 'gt-member' && $slug !== '' ) {
			$person = self::person_for_old_member( $slug );
			return $person ? get_permalink( $person ) : $tree;
		}
		if ( $base === 'gt-family-group' && $slug !== '' ) {
			$term = get_term_by( 'slug', sanitize_title( $slug ), GenoRoots_Family_Group_Taxonomy::TAXONOMY );
			return $term ? add_query_arg( 'groups', rawurlencode( urldecode( $term->slug ) ), $tree ) : $tree;
		}
		return $tree;
	}

	private static function go( $url ) {
		if ( $url ) {
			wp_safe_redirect( $url, 301, 'GenoRoots' );
			exit;
		}
	}

	/**
	 * The GenoRoots person for an old member slug: through the old post's ID
	 * (member 384 → GenoRoots ID I384), falling back to the same slug.
	 */
	private static function person_for_old_member( $slug ) {
		global $wpdb;
		$slug   = sanitize_title( $slug );
		$old_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1",
			self::OLD_TYPE,
			$slug
		) );

		$query = array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);
		if ( $old_id ) {
			$found = get_posts( $query + array(
				'meta_key'   => 'gedcom_id',
				'meta_value' => 'I' . $old_id,
			) );
			if ( $found ) {
				return (int) $found[0];
			}
		}
		$found = get_posts( $query + array( 'name' => $slug ) );
		return $found ? (int) $found[0] : 0;
	}
}
