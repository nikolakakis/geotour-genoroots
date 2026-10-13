<?php
/**
 * GenoRoots_Gedcom_Exporter
 *
 * Writes published people and families as GEDCOM 5.5, in the same dialect
 * the importer reads (_PERIOD, _GROUP, _ARTICLE, _STATUS), so an export can
 * be imported again, on this site or another. Standard genealogy programs
 * read the standard tags and ignore the underscore ones.
 *
 * Selection: a collection, a group, both (the group's members within the
 * collection), or neither (everyone). A family is kept when at least two of
 * its members are selected, and it lists only selected members, so the file
 * never points at someone who isn't in it.
 *
 * IDs: the stored GenoRoots ID, without the collection prefix when every
 * record shares one ("venizelos:I08" → @I08@), so re-importing into that
 * collection updates the same people instead of duplicating them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenoRoots_Gedcom_Exporter {

	const LINE_MAX = 200; // characters of text per line; GEDCOM allows 255

	/** @var WP_Term|null */
	private $collection;

	/** @var WP_Term|null */
	private $group;

	/** @var array<int, string> post ID => xref, filled by export() */
	private $xrefs = array();

	/**
	 * @param WP_Term|null $collection
	 * @param WP_Term|null $group
	 */
	public function __construct( $collection = null, $group = null ) {
		$this->collection = $collection instanceof WP_Term ? $collection : null;
		$this->group      = $group instanceof WP_Term ? $group : null;
	}

	/** Collection or group from a slug ('' or unknown → null). */
	public static function collection_term( $slug ) {
		return $slug !== '' ? GenoRoots_Collection_Taxonomy::term( $slug ) : null;
	}

	public static function group_term( $slug ) {
		if ( $slug === '' ) {
			return null;
		}
		$term = get_term_by( 'slug', sanitize_title( $slug ), GenoRoots_Family_Group_Taxonomy::TAXONOMY );
		return $term instanceof WP_Term ? $term : null;
	}

	/** File name for the download: "genoroots-venizelos-mitsotakis-2026-10-04.ged". */
	public function file_name() {
		$parts = array_filter( array(
			$this->collection ? urldecode( $this->collection->slug ) : '',
			$this->group ? urldecode( $this->group->slug ) : '',
		) );
		$label = $parts ? implode( '-', $parts ) : 'all';
		return sanitize_file_name( 'genoroots-' . $label . '-' . wp_date( 'Y-m-d' ) . '.ged' );
	}

	/**
	 * @return array{gedcom: string, people: int, families: int}
	 */
	public function export() {
		$people   = $this->select_people();
		$families = $this->select_families( $people );
		$this->assign_xrefs( $people, $families );

		$famc = array(); // person ID => family IDs where a child
		$fams = array(); // person ID => family IDs where a spouse
		foreach ( $families as $family_id => $members ) {
			foreach ( $members['spouses'] as $person_id ) {
				$fams[ $person_id ][] = $family_id;
			}
			foreach ( $members['children'] as $person_id ) {
				$famc[ $person_id ][] = $family_id;
			}
		}

		$lines = $this->header();
		foreach ( $people as $person_id ) {
			$lines = array_merge( $lines, $this->person( $person_id, $famc[ $person_id ] ?? array(), $fams[ $person_id ] ?? array() ) );
		}
		foreach ( $families as $family_id => $members ) {
			$lines = array_merge( $lines, $this->family( $family_id, $members ) );
		}
		$lines[] = '0 TRLR';

		return array(
			'gedcom'   => implode( "\n", $lines ) . "\n",
			'people'   => count( $people ),
			'families' => count( $families ),
		);
	}

	// ---- Selection ----

	/** @return int[] */
	private function select_people() {
		$tax_query = array();
		if ( $this->collection ) {
			$tax_query[] = array(
				'taxonomy' => GenoRoots_Collection_Taxonomy::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => (int) $this->collection->term_id,
			);
		}
		if ( $this->group ) {
			$tax_query[] = array(
				'taxonomy' => GenoRoots_Family_Group_Taxonomy::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => (int) $this->group->term_id,
			);
		}
		$args = array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( $tax_query ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Families with at least two selected members, each reduced to them.
	 *
	 * @param int[] $people
	 * @return array<int, array{spouses: int[], children: int[], husb: int, wife: int}>
	 */
	private function select_families( array $people ) {
		$selected = array_flip( $people );
		$ids      = get_posts( array(
			'post_type'      => GenoRoots_Family_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$families = array();
		foreach ( $ids as $family_id ) {
			$husb     = (int) get_post_meta( $family_id, 'spouse1', true );
			$wife     = (int) get_post_meta( $family_id, 'spouse2', true );
			$husb     = isset( $selected[ $husb ] ) ? $husb : 0;
			$wife     = isset( $selected[ $wife ] ) ? $wife : 0;
			$children = array_values( array_filter(
				array_map( 'intval', (array) get_post_meta( $family_id, 'children', true ) ),
				static function ( $id ) use ( $selected ) {
					return isset( $selected[ $id ] );
				}
			) );
			$spouses = array_values( array_filter( array( $husb, $wife ) ) );
			if ( count( $spouses ) + count( $children ) < 2 ) {
				continue;
			}
			$families[ (int) $family_id ] = compact( 'spouses', 'children', 'husb', 'wife' );
		}
		return $families;
	}

	// ---- IDs ----

	private function assign_xrefs( array $people, array $families ) {
		$stored = array();
		foreach ( $people as $id ) {
			$stored[ $id ] = (string) get_post_meta( $id, 'gedcom_id', true );
		}
		foreach ( array_keys( $families ) as $id ) {
			$stored[ $id ] = (string) get_post_meta( $id, 'gedcom_id', true );
		}

		// One shared "slug:" prefix on every record → drop it.
		$prefixes = array_unique( array_map( static function ( $value ) {
			$at = strpos( $value, ':' );
			return $at === false ? '' : substr( $value, 0, $at + 1 );
		}, $stored ) );
		$drop = count( $prefixes ) === 1 ? reset( $prefixes ) : '';

		$used = array();
		foreach ( $stored as $post_id => $value ) {
			$fallback = ( get_post_type( $post_id ) === GenoRoots_Family_CPT::POST_TYPE ? 'F' : 'I' ) . $post_id;
			$xref     = $value !== '' ? substr( $value, strlen( $drop ) ) : $fallback;
			$xref     = preg_replace( '/[^A-Za-z0-9_]+/', '_', $xref );
			if ( $xref === '' || isset( $used[ $xref ] ) ) {
				$xref = $fallback;
			}
			$used[ $xref ]           = true;
			$this->xrefs[ $post_id ] = $xref;
		}
	}

	private function ref( $post_id ) {
		return '@' . $this->xrefs[ $post_id ] . '@';
	}

	// ---- Records ----

	private function header() {
		$scope = array();
		if ( $this->collection ) {
			$scope[] = 'collection "' . $this->collection->name . '"';
		}
		if ( $this->group ) {
			$scope[] = 'group "' . $this->group->name . '"';
		}
		$lines = array(
			'0 HEAD',
			'1 SOUR GENOROOTS',
			'2 NAME GenoRoots',
			'2 VERS ' . GENOROOTS_VERSION,
			'1 DATE ' . strtoupper( gmdate( 'j M Y' ) ),
			'1 GEDC',
			'2 VERS 5.5',
			'2 FORM LINEAGE-LINKED',
			'1 CHAR UTF-8',
		);
		return array_merge( $lines, $this->text( 1, 'NOTE', sprintf(
			'Exported from %s (%s): %s.',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			home_url( '/' ),
			$scope ? implode( ', ', $scope ) : 'all people'
		) ) );
	}

	private function person( $post_id, array $famc, array $fams ) {
		$meta  = static function ( $key ) use ( $post_id ) {
			return trim( (string) get_post_meta( $post_id, $key, true ) );
		};
		$lines = array(
			'0 ' . $this->ref( $post_id ) . ' INDI',
			'1 NAME ' . $this->one_line( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) ),
		);
		if ( $meta( 'name_latin' ) !== '' ) {
			$lines[] = '1 NAME ' . $this->one_line( $meta( 'name_latin' ) );
			$lines[] = '2 TYPE aka';
		}
		$sex     = strtoupper( $meta( 'sex' ) );
		$lines[] = '1 SEX ' . ( in_array( $sex, array( 'M', 'F' ), true ) ? $sex : 'U' );

		$lines = array_merge(
			$lines,
			$this->event( 'BIRT', $meta( 'birth_date_raw' ), $meta( 'birth_place' ) ?: $meta( 'birth_place_en' ) ),
			$this->event( 'DEAT', $meta( 'death_date_raw' ), $meta( 'death_place' ) ?: $meta( 'death_place_en' ) ),
			$this->event( 'BURI', '', $meta( 'burial_place' ) )
		);

		if ( $meta( 'period' ) !== '' ) {
			$lines[] = '1 _PERIOD ' . $this->one_line( $meta( 'period' ) );
		}
		// The importer joins NOTE records with a blank line: one NOTE per paragraph.
		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', $meta( 'notes' ) ) ) as $paragraph ) {
			if ( trim( $paragraph ) !== '' ) {
				$lines = array_merge( $lines, $this->text( 1, 'NOTE', trim( $paragraph ) ) );
			}
		}
		foreach ( preg_split( "/\n/", str_replace( "\r", '', $meta( 'sources' ) ) ) as $source ) {
			if ( trim( $source ) !== '' ) {
				$lines = array_merge( $lines, $this->text( 1, 'SOUR', trim( $source ) ) );
			}
		}
		$groups = get_the_terms( $post_id, GenoRoots_Family_Group_Taxonomy::TAXONOMY );
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$lines[] = '1 _GROUP ' . $this->one_line( $group->name );
		}
		$article = (int) get_post_meta( $post_id, 'related_post', true );
		if ( $article && get_post_status( $article ) === 'publish' ) {
			// Site-relative, so the file links correctly on another copy of the site.
			$path    = trim( (string) wp_parse_url( get_permalink( $article ), PHP_URL_PATH ), '/' );
			$home    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			$lines[] = '1 _ARTICLE ' . ( $home !== '' && strpos( $path, $home . '/' ) === 0 ? substr( $path, strlen( $home ) + 1 ) : $path );
		}
		$portrait = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $portrait ) {
			$lines[] = '1 OBJE';
			$lines[] = '2 FILE ' . $portrait;
		}
		foreach ( $famc as $family_id ) {
			$lines[] = '1 FAMC ' . $this->ref( $family_id );
		}
		foreach ( $fams as $family_id ) {
			$lines[] = '1 FAMS ' . $this->ref( $family_id );
		}
		return $lines;
	}

	private function family( $family_id, array $members ) {
		$lines = array( '0 ' . $this->ref( $family_id ) . ' FAM' );
		// spouse1/spouse2 ↔ HUSB/WIFE, as the importer maps them.
		if ( $members['husb'] ) {
			$lines[] = '1 HUSB ' . $this->ref( $members['husb'] );
		}
		if ( $members['wife'] ) {
			$lines[] = '1 WIFE ' . $this->ref( $members['wife'] );
		}
		foreach ( $members['children'] as $child ) {
			$lines[] = '1 CHIL ' . $this->ref( $child );
		}
		$lines  = array_merge( $lines, $this->event(
			'MARR',
			trim( (string) get_post_meta( $family_id, 'marriage_date_raw', true ) ),
			trim( (string) get_post_meta( $family_id, 'marriage_place', true ) ) ?: trim( (string) get_post_meta( $family_id, 'marriage_place_en', true ) )
		) );
		$status = (string) get_post_meta( $family_id, 'status', true );
		if ( in_array( $status, array( 'confirmed', 'probable', 'claimed' ), true ) ) {
			$lines[] = '1 _STATUS ' . $status;
		}
		return $lines;
	}

	// ---- Lines ----

	private function event( $tag, $date, $place ) {
		if ( $date === '' && $place === '' ) {
			return array();
		}
		$lines = array( '1 ' . $tag );
		if ( $date !== '' ) {
			$lines[] = '2 DATE ' . $this->one_line( $date );
		}
		if ( $place !== '' ) {
			$lines[] = '2 PLAC ' . $this->one_line( $place );
		}
		return $lines;
	}

	private function one_line( $value ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}

	/**
	 * A NOTE/SOUR value over several lines: CONT for each line break, CONC to
	 * split long lines, never next to a space (readers trim line ends).
	 */
	private function text( $level, $tag, $value ) {
		$lines = array();
		foreach ( explode( "\n", $value ) as $i => $line ) {
			$first = true;
			do {
				$length = mb_strlen( $line );
				$cut    = min( self::LINE_MAX, $length );
				while ( $cut < $length && $cut > 1 && ( mb_substr( $line, $cut, 1 ) === ' ' || mb_substr( $line, $cut - 1, 1 ) === ' ' ) ) {
					$cut--;
				}
				$chunk = mb_substr( $line, 0, $cut );
				$line  = mb_substr( $line, $cut );
				if ( $i === 0 && $first ) {
					$lines[] = rtrim( $level . ' ' . $tag . ' ' . $chunk );
				} else {
					$lines[] = rtrim( ( $level + 1 ) . ' ' . ( $first ? 'CONT' : 'CONC' ) . ' ' . $chunk );
				}
				$first = false;
			} while ( $line !== '' );
		}
		return $lines;
	}
}
