<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portable export/import of everything GenoRoots stores: groups, people,
 * families, their ACF fields and group memberships.
 *
 * Post IDs differ between sites, so a bundle never carries them. Records
 * are keyed by GEDCOM id ("ged:I948") or, for records created by hand,
 * by their source site and ID ("wp:genoroots.local:123"). On import each
 * key is matched (sync-key meta, then gedcom_id) or created, and every
 * relationship is re-linked to the target site's IDs.
 *
 * Importing is an upsert and safe to repeat: a second run updates in
 * place. Nothing on the target is deleted; `verify()` lists extras.
 */
class GenoRoots_Bundle {

	const FORMAT   = 'genoroots-bundle';
	const VERSION  = 1;
	const SYNC_KEY = '_genoroots_sync_key';

	const PERSON_GROUP = 'group_genoroots_person';
	// Point at posts of the exporting site; meaningless anywhere else.
	const SITE_SPECIFIC_FIELDS = array( 'related_post' );
	const FAMILY_GROUP = 'group_genoroots_family';

	/** Relationship fields on a family, re-linked by key rather than copied. */
	const FAMILY_LINKS = array( 'spouse1', 'spouse2', 'children' );

	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/** @var callable|null */
	private static $logger = null;

	public static function set_logger( $logger ) {
		self::$logger = $logger;
	}

	private static function log( $message ) {
		if ( self::$logger ) {
			call_user_func( self::$logger, $message );
		}
	}

	// ------------------------------------------------------------------
	// Export
	// ------------------------------------------------------------------

	/**
	 * @return array { bundle: array, warnings: string[] }
	 */
	public static function export() {
		self::require_acf();
		$warnings = array();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		$person_ids = self::post_ids( GenoRoots_Person_CPT::POST_TYPE );
		$family_ids = self::post_ids( GenoRoots_Family_CPT::POST_TYPE );

		$person_keys = self::assign_keys( $person_ids, $host, 'person', $warnings );
		$family_keys = self::assign_keys( $family_ids, $host, 'family', $warnings );

		$person_fields = self::field_map( self::PERSON_GROUP );
		$family_fields = self::field_map( self::FAMILY_GROUP );

		$groups = array();
		foreach ( self::all_group_terms() as $term ) {
			$meta = array();
			foreach ( get_term_meta( $term->term_id ) as $meta_key => $values ) {
				$meta[ $meta_key ] = maybe_unserialize( $values[0] );
			}
			$groups[] = array(
				'slug'        => urldecode( $term->slug ),
				'name'        => $term->name,
				'description' => $term->description,
				'meta'        => $meta,
			);
		}

		$collections = array();
		$all_collections = get_terms( array( 'taxonomy' => GenoRoots_Collection_Taxonomy::TAXONOMY, 'hide_empty' => false ) );
		foreach ( is_wp_error( $all_collections ) ? array() : $all_collections as $term ) {
			$collections[] = array(
				'slug'        => urldecode( $term->slug ),
				'name'        => $term->name,
				'description' => $term->description,
			);
		}

		$people = array();
		foreach ( $person_ids as $id ) {
			$record           = self::post_record( $id, $person_keys[ $id ] );
			$record['fields'] = self::field_values( $id, $person_fields, array() );
			$record['groups'] = GenoRoots_Groups_Controller::group_slugs_of( $id );
			sort( $record['groups'] );
			$record['collections'] = self::collection_slugs_of( $id );
			$record['portrait'] = self::portrait_of( $id );
			$people[] = $record;
		}

		$families = array();
		foreach ( $family_ids as $id ) {
			$record           = self::post_record( $id, $family_keys[ $id ] );
			$record['fields'] = self::field_values( $id, $family_fields, self::FAMILY_LINKS );
			$record['collections'] = self::collection_slugs_of( $id );

			foreach ( array( 'spouse1', 'spouse2' ) as $link ) {
				$linked            = (int) get_field( $link, $id, false );
				$record[ $link ] = null;
				if ( $linked ) {
					if ( isset( $person_keys[ $linked ] ) ) {
						$record[ $link ] = $person_keys[ $linked ];
					} else {
						$warnings[] = sprintf( 'Family %d: %s points at post %d, which is not an exported person; dropped.', $id, $link, $linked );
					}
				}
			}

			$record['children'] = array();
			foreach ( (array) get_field( 'children', $id, false ) as $child ) {
				$child = (int) $child;
				if ( ! $child ) {
					continue;
				}
				if ( isset( $person_keys[ $child ] ) ) {
					$record['children'][] = $person_keys[ $child ];
				} else {
					$warnings[] = sprintf( 'Family %d: child %d is not an exported person; dropped.', $id, $child );
				}
			}

			$families[] = $record;
		}

		return array(
			'bundle'   => self::build_bundle( array(
				'groups'      => $groups,
				'collections' => $collections,
				'people'      => $people,
				'families'    => $families,
			), home_url() ),
			'warnings' => $warnings,
		);
	}

	/** Decoded slugs of a record's collections, sorted. */
	private static function collection_slugs_of( $post_id ) {
		$terms = get_the_terms( $post_id, GenoRoots_Collection_Taxonomy::TAXONOMY );
		$slugs = ( $terms && ! is_wp_error( $terms ) ) ? array_map( function ( $term ) {
			return urldecode( $term->slug );
		}, $terms ) : array();
		sort( $slugs );
		return $slugs;
	}

	/** Sets a record's collections from the bundle, when the bundle has them. */
	private static function save_collections( $post_id, array $record, array $collection_ids, array &$report ) {
		if ( ! array_key_exists( 'collections', $record ) ) {
			return; // bundle from before collections: leave them as they are
		}
		$ids = array();
		foreach ( (array) $record['collections'] as $slug ) {
			if ( isset( $collection_ids[ $slug ] ) ) {
				$ids[] = $collection_ids[ $slug ];
			} else {
				$report['warnings'][] = sprintf( '%s: collection "%s" is not in the bundle; skipped.', $record['key'], $slug );
			}
		}
		wp_set_object_terms( $post_id, $ids, GenoRoots_Collection_Taxonomy::TAXONOMY, false );
	}

	/**
	 * Wraps bundle data (groups/people/families in the export format) with
	 * its header and checksum. Other sources (e.g. the legacy importer)
	 * build their data and go through here, then through import().
	 */
	public static function build_bundle( array $data, $source ) {
		return array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'exported_at'    => gmdate( 'c' ),
			'source'         => $source,
			'plugin_version' => GENOROOTS_VERSION,
			'counts'         => array(
				'groups'      => count( $data['groups'] ),
				'collections' => count( $data['collections'] ?? array() ),
				'people'      => count( $data['people'] ),
				'families'    => count( $data['families'] ),
				'memberships' => array_sum( array_map( function ( $p ) {
					return count( $p['groups'] );
				}, $data['people'] ) ),
			),
			'checksum'       => self::checksum( $data ),
			'data'           => $data,
		);
	}

	/** Portrait as { id, file }: the file path lets another site with the same media find it. */
	public static function portrait_of( $post_id ) {
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb ) {
			return null;
		}
		return array(
			'id'   => $thumb,
			'file' => (string) get_post_meta( $thumb, '_wp_attached_file', true ),
		);
	}

	/** Attachment ID on this site for a bundle portrait: same ID and file, else same file. */
	private static function find_attachment( array $portrait ) {
		$id = (int) ( $portrait['id'] ?? 0 );
		if ( $id && get_post_type( $id ) === 'attachment' && get_post_meta( $id, '_wp_attached_file', true ) === $portrait['file'] ) {
			return $id;
		}
		if ( empty( $portrait['file'] ) ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
			$portrait['file']
		) );
	}

	private static function post_ids( $post_type ) {
		return array_map( 'intval', get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => self::STATUSES,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) ) );
	}

	/** id => key; GEDCOM id when present and unique, otherwise source host + ID. */
	private static function assign_keys( array $ids, $host, $label, array &$warnings ) {
		$by_gedcom = array();
		foreach ( $ids as $id ) {
			$gedcom = trim( (string) get_post_meta( $id, 'gedcom_id', true ) );
			if ( $gedcom !== '' ) {
				$by_gedcom[ $gedcom ][] = $id;
			}
		}

		$keys = array();
		foreach ( $ids as $id ) {
			$gedcom = trim( (string) get_post_meta( $id, 'gedcom_id', true ) );
			if ( $gedcom !== '' && count( $by_gedcom[ $gedcom ] ) === 1 ) {
				$keys[ $id ] = 'ged:' . $gedcom;
			} else {
				if ( $gedcom !== '' ) {
					$warnings[] = sprintf( 'GEDCOM id %s is shared by several %s posts (%s); keyed by post ID instead.', $gedcom, $label, implode( ', ', $by_gedcom[ $gedcom ] ) );
				}
				$keys[ $id ] = 'wp:' . $host . ':' . $id;
			}
		}
		return $keys;
	}

	private static function post_record( $id, $key ) {
		$post = get_post( $id );
		return array(
			'key'       => $key,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'date'      => $post->post_date,
			'date_gmt'  => $post->post_date_gmt,
			'menu_order' => (int) $post->menu_order,
		);
	}

	/** Raw (unformatted) ACF values by field name, minus the given link fields. */
	private static function field_values( $id, array $field_map, array $skip ) {
		$values = array();
		foreach ( $field_map as $name => $field ) {
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			$values[ $name ] = self::normalize( get_field( $name, $id, false ) );
		}
		return $values;
	}

	private static function normalize( $value ) {
		if ( $value === null || $value === false ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : $value;
	}

	private static function checksum( array $data ) {
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	// ------------------------------------------------------------------
	// Import
	// ------------------------------------------------------------------

	/**
	 * Checks a decoded bundle before anything touches the database.
	 *
	 * @return string[] Problems; empty when the bundle is usable.
	 */
	public static function validate( $bundle ) {
		$problems = array();
		if ( ! is_array( $bundle ) || ( $bundle['format'] ?? '' ) !== self::FORMAT ) {
			return array( 'Not a GenoRoots bundle.' );
		}
		if ( (int) ( $bundle['version'] ?? 0 ) !== self::VERSION ) {
			$problems[] = sprintf( 'Bundle format version %s is not supported (expected %d).', $bundle['version'] ?? '?', self::VERSION );
		}
		if ( ! isset( $bundle['data']['groups'], $bundle['data']['people'], $bundle['data']['families'] ) ) {
			$problems[] = 'Bundle is missing its data section.';
			return $problems;
		}
		if ( self::checksum( $bundle['data'] ) !== ( $bundle['checksum'] ?? '' ) ) {
			$problems[] = 'Checksum mismatch: the file was changed or damaged in transfer.';
		}
		return $problems;
	}

	/**
	 * Upserts the bundle's groups, people and families.
	 *
	 * Options:
	 *  - dry_run:     only report what would happen.
	 *  - transaction: all-or-nothing (InnoDB); on by default.
	 *  - rehearse:    full import + verify inside a transaction, then roll back.
	 *
	 * @return array Report: counts, warnings, and (rehearse) the verify result.
	 */
	public static function import( array $bundle, array $options = array() ) {
		global $wpdb;
		self::require_acf();

		$dry_run     = ! empty( $options['dry_run'] );
		$rehearse    = ! empty( $options['rehearse'] );
		$skip_groups = ! empty( $options['skip_groups'] ); // leave groups and memberships as they are
		$only_groups = ! empty( $options['only_groups'] ); // groups + memberships of people already here
		$transaction = $rehearse || ( ! $dry_run && ( $options['transaction'] ?? true ) );

		$report = array(
			'groups'   => array( 'create' => 0, 'update' => 0 ),
			'people'   => array( 'create' => 0, 'update' => 0 ),
			'families' => array( 'create' => 0, 'update' => 0 ),
			'warnings' => array(),
			'verify'   => null,
			'mode'     => $dry_run ? 'dry-run' : ( $rehearse ? 'rehearse' : 'import' ),
		);

		if ( $transaction && ! $dry_run ) {
			$engines = self::non_innodb_tables();
			if ( $engines ) {
				if ( $rehearse ) {
					throw new RuntimeException( 'Rehearsal needs InnoDB tables to roll back; not InnoDB: ' . implode( ', ', $engines ) );
				}
				$report['warnings'][] = 'Tables not InnoDB (' . implode( ', ', $engines ) . '); importing without a transaction.';
				$transaction          = false;
			}
		}

		$person_fields = self::field_map( self::PERSON_GROUP );
		$family_fields = self::field_map( self::FAMILY_GROUP );
		$data          = $bundle['data'];

		if ( $transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			if ( ! $dry_run ) {
				wp_defer_term_counting( true );
			}

			// 1. Groups.
			$term_ids = array(); // decoded slug => term_id
			foreach ( $skip_groups ? array() : $data['groups'] as $group ) {
				$existing = get_term_by( 'slug', sanitize_title( $group['slug'] ), GenoRoots_Family_Group_Taxonomy::TAXONOMY );
				$report['groups'][ $existing ? 'update' : 'create' ]++;
				if ( $dry_run ) {
					$term_ids[ $group['slug'] ] = $existing ? (int) $existing->term_id : -1;
					continue;
				}
				if ( $existing ) {
					$result = wp_update_term( $existing->term_id, GenoRoots_Family_Group_Taxonomy::TAXONOMY, array(
						'name'        => $group['name'],
						'description' => $group['description'],
					) );
				} else {
					$result = wp_insert_term( $group['name'], GenoRoots_Family_Group_Taxonomy::TAXONOMY, array(
						'slug'        => $group['slug'],
						'description' => $group['description'],
					) );
				}
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( sprintf( 'Group "%s": %s', $group['slug'], $result->get_error_message() ) );
				}
				$term_ids[ $group['slug'] ] = (int) $result['term_id'];
				foreach ( (array) $group['meta'] as $meta_key => $meta_value ) {
					update_term_meta( $result['term_id'], $meta_key, $meta_value );
				}
			}
			self::log( sprintf( 'Groups: %d created, %d updated.', $report['groups']['create'], $report['groups']['update'] ) );

			// 1b. Collections (bundles from before collections have none).
			$collection_ids = array(); // decoded slug => term_id
			foreach ( $skip_groups ? array() : ( $data['collections'] ?? array() ) as $collection ) {
				$existing = GenoRoots_Collection_Taxonomy::term( $collection['slug'] );
				if ( $dry_run ) {
					$collection_ids[ $collection['slug'] ] = $existing ? (int) $existing->term_id : -1;
					continue;
				}
				$result = $existing
					? wp_update_term( $existing->term_id, GenoRoots_Collection_Taxonomy::TAXONOMY, array( 'name' => $collection['name'], 'description' => $collection['description'] ) )
					: wp_insert_term( $collection['name'], GenoRoots_Collection_Taxonomy::TAXONOMY, array( 'slug' => $collection['slug'], 'description' => $collection['description'] ) );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( sprintf( 'Collection "%s": %s', $collection['slug'], $result->get_error_message() ) );
				}
				$collection_ids[ $collection['slug'] ] = (int) $result['term_id'];
			}

			// 2. People.
			$person_ids = array(); // key => target post ID
			foreach ( $data['people'] as $i => $person ) {
				$existing = self::find_post( GenoRoots_Person_CPT::POST_TYPE, $person );
				if ( $only_groups && ! $existing ) {
					$report['warnings'][] = sprintf( 'Person %s (%s) is not on this site; group memberships skipped.', $person['key'], $person['title'] );
					continue;
				}
				$report['people'][ $existing ? 'update' : 'create' ]++;
				if ( $dry_run ) {
					$person_ids[ $person['key'] ] = $existing ?: -1;
					self::warn_unknown_fields( $person['fields'], $person_fields, 'person', $report );
					if ( ! $only_groups && ! empty( $person['portrait'] ) && ! self::find_attachment( $person['portrait'] ) ) {
						$report['warnings'][] = sprintf( 'Person %s: portrait %s is not in this media library; would be skipped.', $person['key'], $person['portrait']['file'] );
					}
					continue;
				}
				if ( $only_groups ) {
					$post_id = $existing;
				} else {
					$post_id = self::save_post( GenoRoots_Person_CPT::POST_TYPE, $person, $existing );
					self::save_fields( $post_id, $person['fields'], $person_fields, array(), $report, 'person' );
					if ( array_key_exists( 'portrait', $person ) ) {
						self::save_portrait( $post_id, $person, $report );
					}
				}
				$person_ids[ $person['key'] ] = $post_id;
				if ( $skip_groups ) {
					continue;
				}

				$group_term_ids = array();
				foreach ( $person['groups'] as $slug ) {
					if ( isset( $term_ids[ $slug ] ) ) {
						$group_term_ids[] = $term_ids[ $slug ];
					} else {
						$report['warnings'][] = sprintf( 'Person %s: group "%s" is not in the bundle; skipped.', $person['key'], $slug );
					}
				}
				wp_set_object_terms( $post_id, $group_term_ids, GenoRoots_Family_Group_Taxonomy::TAXONOMY, false );
				self::save_collections( $post_id, $person, $collection_ids, $report );
				if ( ( $i + 1 ) % 50 === 0 ) {
					self::log( sprintf( 'People: %d/%d…', $i + 1, count( $data['people'] ) ) );
				}
			}
			self::log( sprintf( 'People: %d created, %d updated.', $report['people']['create'], $report['people']['update'] ) );

			// 3. Families, re-linked to the target's person IDs.
			$family_ids = array();
			foreach ( $only_groups ? array() : $data['families'] as $family ) {
				$existing = self::find_post( GenoRoots_Family_CPT::POST_TYPE, $family );
				$report['families'][ $existing ? 'update' : 'create' ]++;

				$links = array(
					'spouse1'  => self::resolve_link( $family['spouse1'], $person_ids, $family['key'], $report ),
					'spouse2'  => self::resolve_link( $family['spouse2'], $person_ids, $family['key'], $report ),
					'children' => array_values( array_filter( array_map( function ( $child ) use ( $person_ids, $family, &$report ) {
						return self::resolve_link( $child, $person_ids, $family['key'], $report );
					}, $family['children'] ) ) ),
				);

				if ( $dry_run ) {
					self::warn_unknown_fields( $family['fields'], $family_fields, 'family', $report );
					continue;
				}
				$post_id = self::save_post( GenoRoots_Family_CPT::POST_TYPE, $family, $existing );
				self::save_fields( $post_id, $family['fields'], $family_fields, self::FAMILY_LINKS, $report, 'family' );
				if ( ! $skip_groups ) {
					self::save_collections( $post_id, $family, $collection_ids, $report );
				}
				update_field( $family_fields['spouse1']['key'], $links['spouse1'] ?: '', $post_id );
				update_field( $family_fields['spouse2']['key'], $links['spouse2'] ?: '', $post_id );
				update_field( $family_fields['children']['key'], $links['children'], $post_id );
				$family_ids[] = $post_id;
			}
			self::log( sprintf( 'Families: %d created, %d updated.', $report['families']['create'], $report['families']['update'] ) );

			if ( ! $dry_run ) {
				// 4. Person → family back-references (what the tree reads).
				foreach ( $family_ids as $family_id ) {
					GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
				}
				wp_defer_term_counting( false );
				self::log( 'Back-references rebuilt, group counts updated.' );
			}

			if ( $rehearse ) {
				$report['verify'] = self::verify( $bundle, array(
					'skip_groups' => $skip_groups,
					'only_groups' => $only_groups,
				) );
				$wpdb->query( 'ROLLBACK' );
				wp_cache_flush(); // drop cached rows that no longer exist
				self::log( 'Rehearsal finished: everything rolled back.' );
			} elseif ( $transaction ) {
				$wpdb->query( 'COMMIT' );
			}
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_flush();
			}
			throw $error;
		}

		if ( ! $dry_run && ! $rehearse ) {
			GenoRoots_Groups_Controller::flush();
		}

		return $report;
	}

	private static function find_post( $post_type, array $record ) {
		$by_key = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => self::SYNC_KEY,
			'meta_value'     => $record['key'],
		) );
		if ( $by_key ) {
			return (int) $by_key[0];
		}
		$gedcom = $record['fields']['gedcom_id'] ?? '';
		if ( $gedcom !== '' ) {
			$by_gedcom = get_posts( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => 'gedcom_id',
				'meta_value'     => $gedcom,
			) );
			if ( $by_gedcom ) {
				return (int) $by_gedcom[0];
			}
		}
		return 0;
	}

	private static function save_post( $post_type, array $record, $existing_id ) {
		$post_id = wp_insert_post( array(
			'ID'            => $existing_id,
			'post_type'     => $post_type,
			'post_title'    => $record['title'],
			'post_name'     => $record['slug'],
			'post_status'   => in_array( $record['status'], self::STATUSES, true ) ? $record['status'] : 'draft',
			'post_date'     => $record['date'],
			'post_date_gmt' => $record['date_gmt'],
			'menu_order'    => (int) ( $record['menu_order'] ?? 0 ),
		), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new RuntimeException( sprintf( '%s %s: %s', $post_type, $record['key'], is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert failed' ) );
		}
		update_post_meta( $post_id, self::SYNC_KEY, $record['key'] );
		return (int) $post_id;
	}

	/** Mirrors the bundle's portrait: set it when the file is in this media library, clear it when there is none. */
	private static function save_portrait( $post_id, array $person, array &$report ) {
		if ( empty( $person['portrait'] ) ) {
			delete_post_thumbnail( $post_id );
			return;
		}
		$attachment = self::find_attachment( $person['portrait'] );
		if ( $attachment ) {
			set_post_thumbnail( $post_id, $attachment );
		} else {
			$report['warnings'][] = sprintf( 'Person %s: portrait %s is not in this media library; skipped.', $person['key'], $person['portrait']['file'] );
		}
	}

	private static function save_fields( $post_id, array $values, array $field_map, array $skip, array &$report, $label ) {
		foreach ( $values as $name => $value ) {
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			if ( ! isset( $field_map[ $name ] ) ) {
				$report['warnings'][] = sprintf( 'Unknown %s field "%s" (not defined on this site); skipped.', $label, $name );
				continue;
			}
			update_field( $field_map[ $name ]['key'], $value, $post_id );
		}
	}

	private static function warn_unknown_fields( array $values, array $field_map, $label, array &$report ) {
		foreach ( array_keys( $values ) as $name ) {
			if ( ! isset( $field_map[ $name ] ) ) {
				$report['warnings'][] = sprintf( 'Unknown %s field "%s" (not defined on this site); would be skipped.', $label, $name );
			}
		}
	}

	private static function resolve_link( $key, array $person_ids, $family_key, array &$report ) {
		if ( $key === null || $key === '' ) {
			return 0;
		}
		if ( isset( $person_ids[ $key ] ) ) {
			return $person_ids[ $key ];
		}
		$report['warnings'][] = sprintf( 'Family %s links to %s, which is not in the bundle; dropped.', $family_key, $key );
		return 0;
	}

	private static function non_innodb_tables() {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options );
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
			$tables
		) );
		$bad = array();
		foreach ( $rows as $row ) {
			if ( strcasecmp( (string) $row->ENGINE, 'InnoDB' ) !== 0 ) {
				$bad[] = $row->TABLE_NAME . ' (' . $row->ENGINE . ')';
			}
		}
		return $bad;
	}

	// ------------------------------------------------------------------
	// Verify
	// ------------------------------------------------------------------

	/**
	 * Compares this site against a bundle, record by record.
	 *
	 * @return array { errors: string[], warnings: string[], checked: array }
	 */
	public static function verify( array $bundle, array $options = array() ) {
		self::require_acf();
		$skip_groups = ! empty( $options['skip_groups'] );
		$only_groups = ! empty( $options['only_groups'] );
		$data        = $bundle['data'];
		$errors   = array();
		$warnings = array();

		// Groups.
		foreach ( $skip_groups ? array() : $data['groups'] as $group ) {
			$term = get_term_by( 'slug', sanitize_title( $group['slug'] ), GenoRoots_Family_Group_Taxonomy::TAXONOMY );
			if ( ! $term ) {
				$errors[] = sprintf( 'Group "%s" is missing.', $group['slug'] );
			} elseif ( $term->name !== $group['name'] ) {
				$errors[] = sprintf( 'Group "%s": name is "%s", expected "%s".', $group['slug'], $term->name, $group['name'] );
			}
		}

		// People.
		$person_ids = array();
		$person_fields = self::field_map( self::PERSON_GROUP );
		foreach ( $data['people'] as $person ) {
			$id = self::find_post( GenoRoots_Person_CPT::POST_TYPE, $person );
			if ( ! $id ) {
				$errors[] = sprintf( 'Person %s (%s) is missing.', $person['key'], $person['title'] );
				continue;
			}
			$person_ids[ $person['key'] ] = $id;
			if ( ! $only_groups ) {
				self::compare_post( $id, $person, $errors );
				self::compare_fields( $id, $person, $person_fields, array(), $errors );
				if ( array_key_exists( 'portrait', $person ) ) {
					$actual   = self::portrait_of( $id );
					$expected = $person['portrait'] ? self::find_attachment( $person['portrait'] ) : 0;
					if ( ( $actual ? $actual['id'] : 0 ) !== $expected ) {
						$errors[] = sprintf( 'Person %s: portrait is %s, expected %s.', $person['key'], $actual ? $actual['file'] : 'none', $person['portrait'] ? $person['portrait']['file'] : 'none' );
					}
				}
			}
			$groups = GenoRoots_Groups_Controller::group_slugs_of( $id );
			sort( $groups );
			if ( ! $skip_groups && $groups !== $person['groups'] ) {
				$errors[] = sprintf( 'Person %s: groups [%s], expected [%s].', $person['key'], implode( ', ', $groups ), implode( ', ', $person['groups'] ) );
			}
		}

		// Families and their links.
		$family_fields = self::field_map( self::FAMILY_GROUP );
		$expect_fams   = array(); // person post ID => family post IDs
		$expect_famc   = array(); // person post ID => family post IDs
		foreach ( $only_groups ? array() : $data['families'] as $family ) {
			$id = self::find_post( GenoRoots_Family_CPT::POST_TYPE, $family );
			if ( ! $id ) {
				$errors[] = sprintf( 'Family %s (%s) is missing.', $family['key'], $family['title'] );
				continue;
			}
			self::compare_post( $id, $family, $errors );
			self::compare_fields( $id, $family, $family_fields, self::FAMILY_LINKS, $errors );

			foreach ( array( 'spouse1', 'spouse2' ) as $link ) {
				$expected = $family[ $link ] !== null ? ( $person_ids[ $family[ $link ] ] ?? -1 ) : 0;
				$actual   = (int) get_field( $link, $id, false );
				if ( $actual !== (int) $expected ) {
					$errors[] = sprintf( 'Family %s: %s links to post %d, expected %d.', $family['key'], $link, $actual, $expected );
				}
				if ( $expected > 0 ) {
					$expect_fams[ $expected ][] = $id;
				}
			}
			$expected_children = array_map( function ( $key ) use ( $person_ids ) {
				return $person_ids[ $key ] ?? -1;
			}, $family['children'] );
			$actual_children   = array_map( 'intval', (array) get_field( 'children', $id, false ) );
			if ( $actual_children !== $expected_children ) {
				$errors[] = sprintf( 'Family %s: children [%s], expected [%s].', $family['key'], implode( ', ', $actual_children ), implode( ', ', $expected_children ) );
			}
			foreach ( $expected_children as $child_id ) {
				if ( $child_id > 0 ) {
					$expect_famc[ $child_id ][] = $id;
				}
			}
		}

		// Back-references the tree actually reads.
		foreach ( $only_groups ? array() : $person_ids as $key => $id ) {
			$fams = array_map( 'intval', (array) get_post_meta( $id, '_fams_family_id' ) );
			$famc = array_map( 'intval', (array) get_post_meta( $id, '_famc_family_id' ) );
			sort( $fams );
			sort( $famc );
			$want_fams = array_values( array_unique( $expect_fams[ $id ] ?? array() ) );
			$want_famc = array_values( array_unique( $expect_famc[ $id ] ?? array() ) );
			sort( $want_fams );
			sort( $want_famc );
			if ( $fams !== $want_fams || $famc !== $want_famc ) {
				$errors[] = sprintf( 'Person %s: family links out of date (run the import again to rebuild them).', $key );
			}
		}

		// Extras on this site (reported, never deleted).
		$bundle_keys = array_merge( array_keys( $person_ids ), array_column( $data['families'], 'key' ) );
		foreach ( array( GenoRoots_Person_CPT::POST_TYPE, GenoRoots_Family_CPT::POST_TYPE ) as $post_type ) {
			foreach ( self::post_ids( $post_type ) as $id ) {
				$key    = (string) get_post_meta( $id, self::SYNC_KEY, true );
				$gedcom = (string) get_post_meta( $id, 'gedcom_id', true );
				if ( ! in_array( $key, $bundle_keys, true ) && ! in_array( 'ged:' . $gedcom, $bundle_keys, true ) ) {
					$warnings[] = sprintf( '%s %d ("%s") exists here but not in the bundle.', $post_type, $id, get_the_title( $id ) );
				}
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'checked'  => array(
				'groups'   => count( $data['groups'] ),
				'people'   => count( $data['people'] ),
				'families' => count( $data['families'] ),
			),
		);
	}

	private static function compare_post( $id, array $record, array &$errors ) {
		$post = get_post( $id );
		if ( $post->post_title !== $record['title'] ) {
			$errors[] = sprintf( '%s: title is "%s", expected "%s".', $record['key'], $post->post_title, $record['title'] );
		}
		if ( $post->post_status !== $record['status'] ) {
			$errors[] = sprintf( '%s: status is %s, expected %s.', $record['key'], $post->post_status, $record['status'] );
		}
		if ( $post->post_name !== $record['slug'] ) {
			$errors[] = sprintf( '%s: slug is "%s", expected "%s" (the URL changed).', $record['key'], urldecode( $post->post_name ), urldecode( $record['slug'] ) );
		}
	}

	private static function compare_fields( $id, array $record, array $field_map, array $skip, array &$errors ) {
		foreach ( $record['fields'] as $name => $expected ) {
			if ( in_array( $name, $skip, true ) || ! isset( $field_map[ $name ] ) ) {
				continue;
			}
			$actual = self::normalize( get_field( $name, $id, false ) );
			if ( $actual !== $expected ) {
				$errors[] = sprintf( '%s: field %s is "%s", expected "%s".', $record['key'], $name, is_scalar( $actual ) ? $actual : wp_json_encode( $actual ), is_scalar( $expected ) ? $expected : wp_json_encode( $expected ) );
			}
		}
	}

	// ------------------------------------------------------------------
	// Shared
	// ------------------------------------------------------------------

	private static function require_acf() {
		if ( ! function_exists( 'acf_get_fields' ) ) {
			throw new RuntimeException( 'Advanced Custom Fields must be installed and active.' );
		}
	}

	/** name => [ key, type ] for an ACF field group registered by the plugin. */
	private static function field_map( $group_key ) {
		$map = array();
		foreach ( (array) acf_get_fields( $group_key ) as $field ) {
			if ( in_array( $field['type'], array( 'accordion', 'tab', 'message' ), true ) || $field['name'] === '' ) {
				continue; // layout only, holds no data
			}
			if ( in_array( $field['name'], self::SITE_SPECIFIC_FIELDS, true ) ) {
				continue; // a post ID of this site; never moved between sites
			}
			$map[ $field['name'] ] = array(
				'key'  => $field['key'],
				'type' => $field['type'],
			);
		}
		if ( ! $map ) {
			throw new RuntimeException( sprintf( 'ACF field group %s is not registered; is the plugin active?', $group_key ) );
		}
		return $map;
	}

	private static function all_group_terms() {
		$terms = get_terms( array(
			'taxonomy'   => GenoRoots_Family_Group_Taxonomy::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'term_id',
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}
}
