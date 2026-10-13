<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import source for sites moving off the "Genealogical Tree" plugin
 * (post types gt-member / gt-family, taxonomy gt-family-group).
 *
 * Reads that plugin's rows straight from the database, so it works whether
 * or not the old plugin is still active, and turns them into a GenoRoots
 * bundle for GenoRoots_Bundle::import(). Nothing of the old data is changed.
 *
 * IDs: the old plugin's GEDCOM export uses its post IDs as record ids
 * (member 384 is @I384@), so people become "I384" and families "F386" —
 * the same keys a GEDCOM import of that export produces, and the number
 * already used in photo keywords and Piwigo tags ("Name [384]").
 */
class GenoRoots_Legacy_GTree {

	const MEMBER   = 'gt-member';
	const FAMILY   = 'gt-family';
	const TAXONOMY = 'gt-family-group';

	/** Old fields with no GenoRoots counterpart, reported rather than copied. */
	const NOT_COPIED = array( 'phone', 'email', 'address' );

	public static function is_present() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", self::MEMBER ) );
	}

	/**
	 * @return array { bundle: array, notes: string[] }
	 */
	public static function build_bundle() {
		global $wpdb;
		$notes    = array();
		$statuses = "'" . implode( "','", GenoRoots_Bundle::STATUSES ) . "'";

		// Groups and memberships (direct SQL: the taxonomy may not be registered any more).
		$terms = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, tt.description, tt.term_taxonomy_id
			FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE tt.taxonomy = %s ORDER BY t.term_id",
			self::TAXONOMY
		) );
		$groups   = array();
		$slug_for = array(); // term_taxonomy_id => decoded slug
		foreach ( $terms as $term ) {
			$slug                                  = urldecode( $term->slug );
			$slug_for[ (int) $term->term_taxonomy_id ] = $slug;
			$groups[]                              = array(
				'slug'        => $slug,
				'name'        => $term->name,
				'description' => (string) $term->description,
				'meta'        => array(),
			);
		}
		$member_groups = array(); // member ID => [slug]
		if ( $slug_for ) {
			$rows = $wpdb->get_results(
				"SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}
				WHERE term_taxonomy_id IN (" . implode( ',', array_map( 'intval', array_keys( $slug_for ) ) ) . ')'
			);
			foreach ( $rows as $row ) {
				$member_groups[ (int) $row->object_id ][] = $slug_for[ (int) $row->term_taxonomy_id ];
			}
		}

		// People.
		$members = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_name, post_status, post_date, post_date_gmt, menu_order
			FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($statuses) ORDER BY ID", // phpcs:ignore
			self::MEMBER
		) );
		$people      = array();
		$member_keys = array();
		$not_copied  = array_fill_keys( self::NOT_COPIED, 0 );
		foreach ( $members as $member ) {
			$id                 = (int) $member->ID;
			$member_keys[ $id ] = 'ged:I' . $id;
			$meta               = self::meta_rows( $id );

			$birth = self::event( $meta, 'BIRT' );
			$death = self::event( $meta, 'DEAT' );
			$sex   = strtoupper( (string) ( $meta['sex'][0] ?? '' ) );

			$fields = array(
				'gedcom_id'         => 'I' . $id,
				'sex'               => in_array( $sex, array( 'M', 'F' ), true ) ? $sex : 'U',
				'birth_date_raw'    => $birth['date'],
				'birth_place'       => $birth['plac'],
				'death_date_raw'    => $death['date'],
				'death_place'       => $death['plac'],
				'notes'             => self::notes( $meta ),
				'photo_archive_url' => trim( (string) ( $meta['photo_archive_link'][0] ?? '' ) ),
				'piwigo_tag_id'     => trim( (string) ( $meta['id_tag'][0] ?? '' ) ),
			);

			foreach ( self::NOT_COPIED as $key ) {
				if ( trim( (string) ( $meta[ $key ][0] ?? '' ) ) !== '' ) {
					$not_copied[ $key ]++;
				}
			}

			$slugs = $member_groups[ $id ] ?? array();
			sort( $slugs );
			$thumb = (int) ( $meta['_thumbnail_id'][0] ?? 0 );

			$people[] = array(
				'key'        => $member_keys[ $id ],
				'title'      => $member->post_title,
				'slug'       => $member->post_name,
				'status'     => $member->post_status,
				'date'       => $member->post_date,
				'date_gmt'   => $member->post_date_gmt,
				'menu_order' => (int) $member->menu_order,
				'fields'     => $fields,
				'groups'     => $slugs,
				'portrait'   => $thumb ? array(
					'id'   => $thumb,
					'file' => (string) get_post_meta( $thumb, '_wp_attached_file', true ),
				) : null,
			);
		}
		foreach ( $not_copied as $key => $count ) {
			if ( $count ) {
				$notes[] = sprintf( '%d member(s) have a %s: not copied (no GenoRoots field; contact details of people stay private).', $count, $key );
			}
		}

		// Families.
		$family_posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_name, post_status, post_date, post_date_gmt, menu_order
			FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($statuses) ORDER BY ID", // phpcs:ignore
			self::FAMILY
		) );
		$families = array();
		foreach ( $family_posts as $family ) {
			$id   = (int) $family->ID;
			$meta = self::meta_rows( $id );
			$marr = self::event( $meta, 'MARR' );

			$link = function ( $member_id ) use ( $member_keys, $id, &$notes ) {
				$member_id = (int) $member_id;
				if ( ! $member_id ) {
					return null;
				}
				if ( ! isset( $member_keys[ $member_id ] ) ) {
					$notes[] = sprintf( 'Family %d links to member %d, which does not exist; link dropped.', $id, $member_id );
					return null;
				}
				return $member_keys[ $member_id ];
			};

			$families[] = array(
				'key'        => 'ged:F' . $id,
				'title'      => $family->post_title,
				'slug'       => $family->post_name,
				'status'     => $family->post_status,
				'date'       => $family->post_date,
				'date_gmt'   => $family->post_date_gmt,
				'menu_order' => (int) $family->menu_order,
				'fields'     => array(
					'gedcom_id'         => 'F' . $id,
					'marriage_date_raw' => $marr['date'],
					'marriage_place'    => $marr['plac'],
				),
				'spouse1'    => $link( $meta['husb'][0] ?? 0 ),
				'spouse2'    => $link( $meta['wife'][0] ?? 0 ),
				'children'   => array_values( array_filter( array_map( $link, $meta['chil'] ?? array() ) ) ),
			);
		}

		return array(
			'bundle' => GenoRoots_Bundle::build_bundle( array(
				'groups'   => $groups,
				'people'   => $people,
				'families' => $families,
			), home_url() . ' (Genealogical Tree plugin data)' ),
			'notes'  => $notes,
		);
	}

	/** All meta of a post as key => [values], in insertion order (keeps child order). */
	private static function meta_rows( $post_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$post_id
		) );
		$meta = array();
		foreach ( $rows as $row ) {
			$meta[ $row->meta_key ][] = maybe_unserialize( $row->meta_value );
		}
		return $meta;
	}

	/** First event with this GEDCOM tag that has a date or a place. */
	private static function event( array $meta, $tag ) {
		foreach ( $meta['even'] ?? array() as $event ) {
			if ( is_array( $event ) && strtoupper( (string) ( $event['tag'] ?? '' ) ) === $tag ) {
				$date = trim( (string) ( $event['date'] ?? '' ) );
				$plac = trim( (string) ( $event['plac'] ?? '' ) );
				if ( $date !== '' || $plac !== '' ) {
					return array( 'date' => $date, 'plac' => $plac );
				}
			}
		}
		return array( 'date' => '', 'plac' => '' );
	}

	/** The old plugin's three free-text places, joined into one Notes field. */
	private static function notes( array $meta ) {
		$parts = array();
		foreach ( $meta['additional_info'] ?? array() as $info ) {
			if ( is_string( $info ) && trim( $info ) !== '' ) {
				$parts[] = trim( $info );
			}
		}
		foreach ( $meta['note'] ?? array() as $note ) {
			$text = is_array( $note ) ? trim( (string) ( $note['note'] ?? '' ) ) : trim( (string) $note );
			if ( $text !== '' ) {
				$parts[] = $text;
			}
		}
		foreach ( $meta['additional_fields'] ?? array() as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$name  = trim( (string) ( $extra['name'] ?? '' ) );
			$value = trim( (string) ( $extra['value'] ?? '' ) );
			if ( $name !== '' || $value !== '' ) {
				$parts[] = $name !== '' ? "{$name}: {$value}" : $value;
			}
		}
		return implode( "\n\n", array_unique( $parts ) );
	}
}
