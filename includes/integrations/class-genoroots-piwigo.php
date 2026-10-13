<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional Piwigo connector: links people to their photos in a Piwigo
 * gallery. Read-only on the Piwigo side (its public web API), and only
 * when asked (WP-CLI or the settings page) — visitors never trigger a
 * request to Piwigo.
 *
 * Convention: a person's photos carry a Piwigo tag whose name ends with
 * their GenoRoots ID in brackets, e.g. "Μαρία Παπαδάκη [384]" for the
 * person with GEDCOM id I384. Tags are matched by that number, never by
 * Piwigo's own tag ID, which changes whenever the gallery is rebuilt.
 */
class GenoRoots_Piwigo {

	const OPTION = 'genoroots_piwigo_url';

	/** Person fields this connector owns. */
	const FIELDS = array( 'piwigo_tag_id', 'photo_archive_url', 'piwigo_photo_count' );

	public static function base_url() {
		return untrailingslashit( (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * All tags from Piwigo's public API.
	 *
	 * @return array[] Each: id, name, counter, url.
	 */
	public static function fetch_tags( $base_url ) {
		if ( ! $base_url ) {
			throw new RuntimeException( 'No Piwigo address set (GenoRoots → Settings, or --piwigo=).' );
		}
		$response = wp_remote_get( $base_url . '/ws.php?format=json&method=pwg.tags.getList', array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Piwigo request failed: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 || ( $body['stat'] ?? '' ) !== 'ok' || ! isset( $body['result']['tags'] ) ) {
			throw new RuntimeException( sprintf( 'Unexpected answer from Piwigo (HTTP %d). Is %s a Piwigo site?', $code, $base_url ) );
		}
		return array_map( function ( $tag ) {
			return array(
				'id'      => (int) $tag['id'],
				'name'    => (string) $tag['name'],
				'counter' => (int) $tag['counter'],
				'url'     => (string) ( $tag['url'] ?? '' ),
			);
		}, $body['result']['tags'] );
	}

	/**
	 * Works out, without writing anything, which people change.
	 *
	 * @return array {
	 *   changes: [ person_id => [ 'title', 'from' => fields, 'to' => fields ] ],
	 *   linked: int, conflicts: string[], unknown: string[], without_id: int, untagged: int
	 * }
	 */
	public static function plan( array $tags ) {
		// Tags by GenoRoots number; the one with most photos wins a tie.
		$by_number = array();
		$without_id = 0;
		foreach ( $tags as $tag ) {
			if ( preg_match( '/\[(\d+)\]/', $tag['name'], $m ) ) {
				$by_number[ (int) $m[1] ][] = $tag;
			} else {
				$without_id++;
			}
		}

		$conflicts = array();
		foreach ( $by_number as $number => &$candidates ) {
			usort( $candidates, function ( $a, $b ) {
				return $b['counter'] <=> $a['counter'];
			} );
			if ( count( $candidates ) > 1 ) {
				$conflicts[] = sprintf(
					'[%d] has %d tags: %s. Using "%s"; merge them in Piwigo/Lightroom.',
					$number,
					count( $candidates ),
					implode( ', ', array_map( function ( $t ) {
						return sprintf( '#%d "%s" (%d)', $t['id'], $t['name'], $t['counter'] );
					}, $candidates ) ),
					$candidates[0]['name']
				);
			}
		}
		unset( $candidates );

		$people = get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$changes = array();
		$linked  = 0;
		$seen    = array();
		foreach ( $people as $person_id ) {
			$gedcom = (string) get_post_meta( $person_id, 'gedcom_id', true );
			$number = preg_match( '/^I(\d+)$/', $gedcom, $m ) ? (int) $m[1] : 0;
			$tag    = $number && isset( $by_number[ $number ] ) ? $by_number[ $number ][0] : null;
			if ( $number ) {
				$seen[ $number ] = true;
			}

			$to = $tag
				? array(
					'piwigo_tag_id'      => (string) $tag['id'],
					'photo_archive_url'  => $tag['url'],
					'piwigo_photo_count' => (string) $tag['counter'],
				)
				: array_fill_keys( self::FIELDS, '' );
			$from = array();
			foreach ( self::FIELDS as $field ) {
				$from[ $field ] = (string) get_field( $field, $person_id, false );
			}

			if ( $tag ) {
				$linked++;
			}
			if ( $from !== $to ) {
				$changes[ $person_id ] = array(
					'title' => get_the_title( $person_id ),
					'from'  => $from,
					'to'    => $to,
				);
			}
		}

		$unknown = array();
		foreach ( $by_number as $number => $candidates ) {
			if ( empty( $seen[ $number ] ) ) {
				$unknown[] = sprintf( '[%d] "%s" (%d photos): no person with GenoRoots ID I%d.', $number, $candidates[0]['name'], $candidates[0]['counter'], $number );
			}
		}

		return array(
			'max_number' => $by_number ? max( array_keys( $by_number ) ) : 0,
			'changes'    => $changes,
			'linked'     => $linked,
			'people'     => count( $people ),
			'conflicts'  => $conflicts,
			'unknown'    => $unknown,
			'without_id' => $without_id,
		);
	}

	/** Writes a plan's changes. */
	public static function apply( array $plan ) {
		foreach ( $plan['changes'] as $person_id => $change ) {
			foreach ( $change['to'] as $field => $value ) {
				update_field( $field, $value, $person_id );
			}
		}
		if ( ! empty( $plan['max_number'] ) ) {
			GenoRoots_Ids::reserve( $plan['max_number'] ); // e.g. "[972]" before that person is in the tree
		}
		update_option( 'genoroots_piwigo_last_sync', array(
			'time'   => time(),
			'linked' => $plan['linked'],
			'people' => $plan['people'],
		), false );
		return count( $plan['changes'] );
	}
}
