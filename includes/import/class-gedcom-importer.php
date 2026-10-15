<?php
/**
 * GenoRoots_Gedcom_Importer
 *
 * Turns a GenoRoots_Gedcom_Parser result into genoroots_person /
 * genoroots_family posts. Idempotent: re-importing the same file updates
 * existing posts (matched by the `gedcom_id` field) instead of duplicating
 * them.
 *
 * With a collection, every record joins it and its ID is namespaced
 * ("venizelos:I01"): unrelated files all number their people @I1@, @I2@…,
 * and without the namespace a second file would overwrite the first.
 *
 * Add-only mode extends a tree without changing what is already there:
 * people already on the site are only anchors (nothing about them is
 * rewritten), families already on the site only gain what they lack (new
 * children, an empty spouse slot, an empty marriage date or place), and
 * everything else in the file is created as usual.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenoRoots_Gedcom_Importer {

	/** @var GenoRoots_Gedcom_Parser */
	private $parser;

	/** @var WP_Term|null */
	private $collection = null;

	/** @var string Prefix for stored IDs ("slug:" with a collection). */
	private $id_prefix = '';

	/** @var bool Leave existing people and families as they are; only add. */
	private $add_only = false;

	/**
	 * @param WP_Term|null $collection Collection the imported records join.
	 * @param bool         $add_only   Only add; never change existing records.
	 */
	public function __construct( $collection = null, $add_only = false ) {
		$this->add_only = (bool) $add_only;
		$this->parser = new GenoRoots_Gedcom_Parser();
		if ( $collection instanceof WP_Term ) {
			$this->collection = $collection;
			$this->id_prefix  = urldecode( $collection->slug ) . ':';
		}
	}

	/** The ID stored on the post for a GEDCOM xref. */
	private function stored_id( $xref ) {
		return $this->id_prefix . $xref;
	}

	private function join_collection( $post_id ) {
		if ( $this->collection && $post_id ) {
			wp_set_object_terms( $post_id, (int) $this->collection->term_id, GenoRoots_Collection_Taxonomy::TAXONOMY, true );
		}
	}

	/**
	 * @param string $path Path to a .ged file.
	 * @return array Import report.
	 */
	public function import_file( $path ) {
		$parsed = $this->parser->parse_file( $path );

		$report = array(
			'people_created'     => 0,
			'people_updated'     => 0,
			'people_kept'        => 0, // add-only: existing, left as they were
			'families_created'   => 0,
			'families_updated'   => 0,
			'families_extended'  => 0, // add-only: existing, gained children/fields
			'families_kept'      => 0, // add-only: existing, nothing to add
			'people_total'       => 0,
			'families_total'     => 0,
			'skipped_lines'      => $parsed['skipped'],
			'missing_references' => array(),
			'warnings'           => array(),
		);

		// Pass 1: people, so their post IDs exist before families reference them.
		$person_post_ids = array(); // gedcom_id => post_id
		foreach ( $parsed['individuals'] as $gedcom_id => $indi ) {
			$person_post_ids[ $gedcom_id ] = $this->upsert_person( $gedcom_id, $indi, $report );
		}

		// Pass 2: families.
		$family_post_ids = array();
		foreach ( $parsed['families'] as $gedcom_id => $fam ) {
			$family_post_ids[ $gedcom_id ] = $this->upsert_family( $gedcom_id, $fam, $person_post_ids, $report );
		}

		// Pass 3: back-reference meta on each person, read from the family
		// fields just saved in pass 2.
		foreach ( $family_post_ids as $family_id ) {
			if ( $family_id ) {
				GenoRoots_Relationships::rebuild_backrefs_for_family( $family_id );
			}
		}

		$report['people_total']   = count( $person_post_ids );
		$report['families_total'] = count( $family_post_ids );

		return $report;
	}

	private function find_existing_post_by_gedcom_id( $post_type, $gedcom_id ) {
		$existing = get_posts( array(
			'post_type'      => $post_type,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_key'       => 'gedcom_id',
			'meta_value'     => $gedcom_id,
		) );
		return $existing ? (int) $existing[0] : 0;
	}

	private function upsert_person( $gedcom_id, array $indi, array &$report ) {
		// GEDCOM marks the surname with slashes anywhere in the name: "Given /Surname/"
		// (the standard) or "/Surname/ Given". Drop the marks, keep the order.
		$title = isset( $indi['name'] ) ? trim( preg_replace( '/\s+/u', ' ', str_replace( '/', ' ', $indi['name'] ) ) ) : '';
		$title = ( $title !== '' ) ? $title : $gedcom_id;

		$existing_id = $this->find_existing_post_by_gedcom_id( GenoRoots_Person_CPT::POST_TYPE, $this->stored_id( $gedcom_id ) );

		if ( $existing_id && $this->add_only ) {
			$report['people_kept']++;
			return $existing_id; // an anchor for the new records
		}

		$post_id = wp_insert_post( array(
			'ID'          => $existing_id,
			'post_type'   => GenoRoots_Person_CPT::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$existing_id ? $report['people_updated']++ : $report['people_created']++;

		update_field( 'gedcom_id', $this->stored_id( $gedcom_id ), $post_id );
		$this->join_collection( $post_id );
		update_field( 'sex', isset( $indi['sex'] ) && $indi['sex'] !== '' ? $indi['sex'] : 'U', $post_id );
		update_field( 'birth_date_raw', $indi['birt']['date'] ?? '', $post_id );
		update_field( 'birth_place', $indi['birt']['place'] ?? '', $post_id );
		update_field( 'death_date_raw', $indi['deat']['date'] ?? '', $post_id );
		update_field( 'death_place', $indi['deat']['place'] ?? '', $post_id );

		// Written only when the file has them, so values set by hand in the
		// admin (e.g. a related article) survive a re-import that lacks them.
		if ( isset( $indi['buri'] ) ) {
			update_field( 'burial_place', $indi['buri']['place'] ?? '', $post_id );
		}
		if ( isset( $indi['notes'] ) ) {
			update_field( 'notes', implode( "\n\n", $indi['notes'] ), $post_id );
		}
		if ( isset( $indi['sources'] ) ) {
			update_field( 'sources', implode( "\n", $indi['sources'] ), $post_id );
		}
		foreach ( array( 'father_name', 'nickname' ) as $key ) {
			if ( isset( $indi[ $key ] ) ) {
				update_field( $key, $indi[ $key ], $post_id );
			}
		}
		if ( isset( $indi['period'] ) ) {
			update_field( 'period', $indi['period'], $post_id );
		}
		if ( ! empty( $indi['groups'] ) ) {
			$this->join_groups( $post_id, $indi['groups'] );
		}
		if ( isset( $indi['article'] ) ) {
			$this->link_article( $post_id, $gedcom_id, $indi['article'], $report );
		}

		return $post_id;
	}

	/** _GROUP names: the group with that name (or slug), created if missing. Adds, never removes. */
	private function join_groups( $post_id, array $names ) {
		$tax      = GenoRoots_Family_Group_Taxonomy::TAXONOMY;
		$term_ids = array();
		foreach ( array_unique( $names ) as $name ) {
			$term = get_term_by( 'name', $name, $tax ) ?: get_term_by( 'slug', sanitize_title( $name ), $tax );
			if ( ! $term ) {
				$created = wp_insert_term( $name, $tax );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}
		if ( $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, $tax, true );
		}
	}

	/**
	 * _ARTICLE: an address on this site ("people/eleftherios-venizelos" or a
	 * full URL), resolved here, so the same file links correctly on every site.
	 */
	private function link_article( $post_id, $gedcom_id, $address, array &$report ) {
		$url    = preg_match( '#^https?://#i', $address ) ? $address : home_url( '/' . ltrim( $address, '/' ) );
		$target = url_to_postid( $url );
		if ( ! $target || get_post_type( $target ) === GenoRoots_Person_CPT::POST_TYPE ) {
			$report['warnings'][] = sprintf( 'Person %s: related article "%s" was not found on this site; not linked.', $gedcom_id, $address );
			return;
		}
		update_field( GenoRoots_Related::FIELD_KEY, $target, $post_id );
	}

	private function upsert_family( $gedcom_id, array $fam, array $person_post_ids, array &$report ) {
		$existing_id = $this->find_existing_post_by_gedcom_id( GenoRoots_Family_CPT::POST_TYPE, $this->stored_id( $gedcom_id ) );

		if ( $existing_id && $this->add_only ) {
			return $this->extend_family( $existing_id, $gedcom_id, $fam, $person_post_ids, $report );
		}

		$spouse1_id = isset( $fam['husb'] ) ? $this->resolve_person_ref( $fam['husb'], $person_post_ids, $gedcom_id, $report ) : 0;
		$spouse2_id = isset( $fam['wife'] ) ? $this->resolve_person_ref( $fam['wife'], $person_post_ids, $gedcom_id, $report ) : 0;

		$post_id = wp_insert_post( array(
			'ID'          => $existing_id,
			'post_type'   => GenoRoots_Family_CPT::POST_TYPE,
			'post_title'  => $this->build_family_title( $spouse1_id, $spouse2_id, $gedcom_id ),
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$existing_id ? $report['families_updated']++ : $report['families_created']++;

		update_field( 'gedcom_id', $this->stored_id( $gedcom_id ), $post_id );
		$this->join_collection( $post_id );
		update_field( 'spouse1', $spouse1_id ?: null, $post_id );
		update_field( 'spouse2', $spouse2_id ?: null, $post_id );

		$children_ids = array();
		foreach ( (array) ( $fam['chil'] ?? array() ) as $child_gedcom_id ) {
			$child_post_id = $this->resolve_person_ref( $child_gedcom_id, $person_post_ids, $gedcom_id, $report );
			if ( $child_post_id ) {
				$children_ids[] = $child_post_id;
			}
		}
		update_field( 'children', $children_ids, $post_id );

		update_field( 'marriage_date_raw', $fam['marr']['date'] ?? '', $post_id );
		update_field( 'marriage_place', $fam['marr']['place'] ?? '', $post_id );
		if ( isset( $fam['status'] ) && in_array( $fam['status'], array( 'confirmed', 'probable', 'claimed' ), true ) ) {
			update_field( 'status', $fam['status'], $post_id );
		}

		return $post_id;
	}

	/**
	 * Add-only: give an existing family what the file has and it lacks.
	 * Never removes or overwrites anything.
	 */
	private function extend_family( $family_id, $gedcom_id, array $fam, array $person_post_ids, array &$report ) {
		$changed = false;
		$current = array(
			'spouse1' => (int) get_post_meta( $family_id, 'spouse1', true ),
			'spouse2' => (int) get_post_meta( $family_id, 'spouse2', true ),
		);
		foreach ( array( 'spouse1' => 'husb', 'spouse2' => 'wife' ) as $field => $tag ) {
			if ( empty( $fam[ $tag ] ) ) {
				continue;
			}
			$wanted = $this->resolve_person_ref( $fam[ $tag ], $person_post_ids, $gedcom_id, $report );
			if ( ! $wanted || in_array( $wanted, $current, true ) ) {
				continue;
			}
			if ( ! $current[ $field ] ) {
				update_field( $field, $wanted, $family_id );
				$current[ $field ] = $wanted;
				$changed           = true;
			} else {
				$report['warnings'][] = sprintf(
					'Family %1$s: the file names %2$s as a spouse, but the family already has %3$s; kept as it is.',
					$gedcom_id,
					$fam[ $tag ],
					get_the_title( $current[ $field ] )
				);
			}
		}

		$children = array_map( 'intval', (array) get_post_meta( $family_id, 'children', true ) );
		foreach ( (array) ( $fam['chil'] ?? array() ) as $child_gedcom_id ) {
			$child = $this->resolve_person_ref( $child_gedcom_id, $person_post_ids, $gedcom_id, $report );
			if ( $child && ! in_array( $child, $children, true ) ) {
				$children[] = $child;
				$changed    = true;
			}
		}
		if ( $changed ) {
			update_field( 'children', array_values( array_filter( $children ) ), $family_id );
		}

		foreach ( array( 'marriage_date_raw' => 'date', 'marriage_place' => 'place' ) as $field => $key ) {
			$value = $fam['marr'][ $key ] ?? '';
			if ( $value !== '' && trim( (string) get_post_meta( $family_id, $field, true ) ) === '' ) {
				update_field( $field, $value, $family_id );
				$changed = true;
			}
		}

		if ( $changed ) {
			wp_update_post( array(
				'ID'         => $family_id,
				'post_title' => $this->build_family_title( $current['spouse1'], $current['spouse2'], $gedcom_id ),
			) );
			$report['families_extended']++;
		} else {
			$report['families_kept']++;
		}
		return $family_id;
	}

	/**
	 * Resolves a GEDCOM xref to a Person post ID.
	 *
	 * Kamilari's source export splits one interconnected tree into several
	 * per-surname .ged files that share a single xref space and reference
	 * each other's individuals — so a file's own parsed individuals aren't
	 * the whole story. Falls back to a lookup against posts already
	 * imported from a previously-processed file before treating a
	 * reference as genuinely missing.
	 */
	private function resolve_person_ref( $gedcom_id, array $person_post_ids, $context_family_gedcom_id, array &$report ) {
		if ( ! empty( $person_post_ids[ $gedcom_id ] ) ) {
			return $person_post_ids[ $gedcom_id ];
		}

		$existing = $this->find_existing_post_by_gedcom_id( GenoRoots_Person_CPT::POST_TYPE, $this->stored_id( $gedcom_id ) );
		if ( $existing ) {
			return $existing;
		}

		$report['missing_references'][] = sprintf(
			'Family %1$s references individual %2$s, which has no INDI record in this file or any previously imported file.',
			$context_family_gedcom_id,
			$gedcom_id
		);
		return 0;
	}

	private function build_family_title( $spouse1_id, $spouse2_id, $gedcom_id ) {
		$names = array_filter( array(
			$spouse1_id ? get_the_title( $spouse1_id ) : '',
			$spouse2_id ? get_the_title( $spouse2_id ) : '',
		) );
		return $names ? implode( ' & ', $names ) : sprintf( 'Family %s', $gedcom_id );
	}
}
