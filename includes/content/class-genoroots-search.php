<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finding people by what they are called: name, name in Latin characters,
 * father's name and nickname, or the GenoRoots number ("384", "I384").
 *
 * Every word must match one of those, so "μανώλης γεωργίου" finds the
 * Manolis whose father was Georgios. The site's collation decides accents
 * and case (utf8mb4_unicode_ci treats "ά" as "α" and "Α").
 */
class GenoRoots_Search {

	const META = array( 'name_latin', 'father_name', 'nickname' );

	/**
	 * @param string $query
	 * @param array  $args statuses (string[]), collection (term ID or 0),
	 *                     collections (term IDs, any of), limit.
	 * @return int[] Person IDs, best matches first.
	 */
	public static function people( $query, array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'statuses'    => array( 'publish' ),
			'collection'  => 0,
			'collections' => array(),
			'limit'       => 20,
		) );

		$words = array_slice( array_filter( preg_split( '/\s+/u', trim( (string) $query ) ) ), 0, 5 );
		if ( ! $words ) {
			return array();
		}

		$joins = array();
		foreach ( self::META as $i => $key ) {
			$joins[] = $wpdb->prepare( "LEFT JOIN {$wpdb->postmeta} m{$i} ON m{$i}.post_id = p.ID AND m{$i}.meta_key = %s", $key );
		}
		$where = array(
			$wpdb->prepare( 'p.post_type = %s', GenoRoots_Person_CPT::POST_TYPE ),
			"p.post_status IN ('" . implode( "','", array_map( 'esc_sql', (array) $args['statuses'] ) ) . "')",
		);

		// The GenoRoots number alone: an exact match.
		if ( count( $words ) === 1 && preg_match( '/^[Ii]?(\d+)$/', $words[0], $number ) ) {
			$where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} g WHERE g.post_id = p.ID AND g.meta_key = 'gedcom_id' AND g.meta_value = %s)", 'I' . $number[1] );
		} else {
			foreach ( $words as $word ) {
				$like    = '%' . $wpdb->esc_like( $word ) . '%';
				$where[] = $wpdb->prepare( '(p.post_title LIKE %s OR m0.meta_value LIKE %s OR m1.meta_value LIKE %s OR m2.meta_value LIKE %s)', $like, $like, $like, $like );
			}
		}

		// One chosen collection, else any of a list (an editor's allowed collections).
		$terms = $args['collection'] ? array( (int) $args['collection'] ) : array_filter( array_map( 'intval', (array) $args['collections'] ) );
		if ( $terms ) {
			$where[] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = p.ID AND tt.taxonomy = '" . esc_sql( GenoRoots_Collection_Taxonomy::TAXONOMY ) . "' AND tt.term_id IN (" . implode( ',', $terms ) . '))';
		}

		$starts = $wpdb->prepare( 'p.post_title LIKE %s', $wpdb->esc_like( $words[0] ) . '%' );
		$sql    = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p " . implode( ' ', $joins )
			. ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY ({$starts}) DESC, p.post_title ASC LIMIT " . max( 1, min( 50, (int) $args['limit'] ) );

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- built from prepared parts
	}
}
