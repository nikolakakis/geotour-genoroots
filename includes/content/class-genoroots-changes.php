<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change log: every edit made through GenoRoots_Family_Ops, with the
 * earlier state of each post it touched, so it can be undone.
 *
 * GenoRoots_Changes::run() wraps one edit. It takes a lock (one edit at a
 * time, which also keeps the ID sequence safe), opens a transaction, runs
 * the edit, and logs it. Posts are recorded the first time they are
 * touched: title, status, slug, all their meta and their GenoRoots terms.
 *
 * Undo restores those states and trashes the posts the edit created. It
 * refuses when any of the posts has changed since, and it is logged like
 * any edit, so an undo can be undone too.
 *
 * A request_id (sent by the phone) makes a retried request harmless: the
 * second one gets the first one's answer and writes nothing.
 */
class GenoRoots_Changes {

	const DB_VERSION = 1;
	const LOCK       = 'genoroots_write';

	/** Meta that says nothing about the tree. */
	const IGNORED_META = array( '_edit_lock', '_edit_last' );

	/** @var array|null The edit being recorded: before states and created posts. */
	private static $recording = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'genoroots_changes';
	}

	public static function maybe_install() {
		if ( (int) get_option( 'genoroots_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			request_id varchar(64) DEFAULT NULL,
			action varchar(40) NOT NULL,
			person_id bigint(20) unsigned NOT NULL DEFAULT 0,
			posts text NOT NULL,
			summary text NOT NULL,
			before_state longtext NOT NULL,
			after_hash longtext NOT NULL,
			result longtext,
			undo_of bigint(20) unsigned DEFAULT NULL,
			undone_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_id (request_id),
			KEY person_id (person_id),
			KEY created_at (created_at)
		) {$charset};" );
		update_option( 'genoroots_db_version', self::DB_VERSION );
	}

	// ------------------------------------------------------------------
	// Recording
	// ------------------------------------------------------------------

	/**
	 * Runs one edit and logs it.
	 *
	 * @param array    $args action, person_id (int, or a Closure given the result), request_id (optional),
	 *                       summary (string, or a Closure given the result).
	 * @param callable $edit Does the edit, returns the result (an array).
	 * @return array The result, plus change_id (0 when nothing changed).
	 * @throws GenoRoots_Edit_Error When the edit is refused; nothing is written.
	 */
	public static function run( array $args, callable $edit ) {
		global $wpdb;
		$request_id = isset( $args['request_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $args['request_id'] ), 0, 64 ) : '';

		$replay = self::replay( $request_id );
		if ( $replay !== null ) {
			return $replay;
		}
		if ( self::$recording !== null ) {
			return $edit(); // already inside an edit (e.g. the admin box calling Ops)
		}

		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', self::lock_name() ) ) ) {
			throw new GenoRoots_Edit_Error( 'genoroots_busy', __( 'Someone else is saving a change. Please try again.', 'geotour-genoroots' ), array( 'status' => 503 ) );
		}
		try {
			$replay = self::replay( $request_id ); // the first try may have finished while we waited
			if ( $replay !== null ) {
				return $replay;
			}

			$wpdb->query( 'START TRANSACTION' );
			self::$recording = array( 'before' => array(), 'created' => array() );
			try {
				$result = (array) $edit();
			} catch ( Throwable $error ) {
				$wpdb->query( 'ROLLBACK' );
				self::forget_caches( array_merge( array_keys( self::$recording['before'] ), self::$recording['created'] ) );
				throw $error;
			} finally {
				$recorded        = self::$recording;
				self::$recording = null;
			}

			$after = array();
			$posts = array_unique( array_merge( array_keys( $recorded['before'] ), $recorded['created'] ) );
			foreach ( $posts as $post_id ) {
				clean_post_cache( $post_id );
				$after[ $post_id ] = self::hash( self::state( $post_id ) );
			}
			$changed = $recorded['created'] || array_filter( $recorded['before'], function ( $state, $post_id ) use ( $after ) {
				return self::hash( $state ) !== $after[ $post_id ];
			}, ARRAY_FILTER_USE_BOTH );

			$result['change_id'] = 0;
			if ( $changed ) {
				$summary = $args['summary'] ?? '';
				$wpdb->insert( self::table(), array(
					'created_at'   => current_time( 'mysql', true ),
					'user_id'      => get_current_user_id(),
					'request_id'   => $request_id !== '' ? $request_id : null,
					'action'       => (string) $args['action'],
					'person_id'    => ( $args['person_id'] ?? 0 ) instanceof Closure ? (int) $args['person_id']( $result ) : (int) ( $args['person_id'] ?? 0 ),
					'posts'        => ',' . implode( ',', $posts ) . ',',
					'summary'      => $summary instanceof Closure ? (string) $summary( $result ) : (string) $summary,
					'before_state' => wp_json_encode( array( 'posts' => $recorded['before'], 'created' => $recorded['created'] ) ),
					'after_hash'   => wp_json_encode( $after ),
					'undo_of'      => isset( $args['undo_of'] ) ? (int) $args['undo_of'] : null,
				) );
				$result['change_id'] = (int) $wpdb->insert_id;
				if ( ! empty( $args['after_insert'] ) ) {
					call_user_func( $args['after_insert'], $result['change_id'] );
				}
				$wpdb->update( self::table(), array( 'result' => wp_json_encode( $result ) ), array( 'id' => $result['change_id'] ) );
			}
			$wpdb->query( 'COMMIT' );

			if ( $changed ) {
				/** Fires after an edit is saved: cache plugins and themes can refresh here. */
				do_action( 'genoroots_changed', $posts, $result['change_id'] );
			}
			return $result;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	/** Called before a post changes: records its state the first time. */
	public static function touch( $post_id ) {
		$post_id = (int) $post_id;
		if ( self::$recording === null || ! $post_id
			|| isset( self::$recording['before'][ $post_id ] )
			|| in_array( $post_id, self::$recording['created'], true ) ) {
			return;
		}
		self::$recording['before'][ $post_id ] = self::state( $post_id );
	}

	/** Called after a post is created by the edit. */
	public static function created( $post_id ) {
		if ( self::$recording !== null ) {
			self::$recording['created'][] = (int) $post_id;
		}
	}

	private static function replay( $request_id ) {
		global $wpdb;
		if ( $request_id === '' ) {
			return null;
		}
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT result FROM ' . self::table() . ' WHERE request_id = %s', $request_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $stored === null ) {
			return null;
		}
		$result             = (array) json_decode( (string) $stored, true );
		$result['replayed'] = true;
		return $result;
	}

	private static function lock_name() {
		global $wpdb;
		return self::LOCK . '_' . $wpdb->prefix;
	}

	// ------------------------------------------------------------------
	// Post state
	// ------------------------------------------------------------------

	/** Everything about a post that an edit can change. */
	public static function state( $post_id ) {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_status, post_name, post_type FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		$meta = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$post_id
		), ARRAY_N );
		$meta = array_values( array_filter( $meta, function ( $row ) {
			return ! in_array( $row[0], self::IGNORED_META, true );
		} ) );

		$terms = array();
		foreach ( array( GenoRoots_Collection_Taxonomy::TAXONOMY, GenoRoots_Family_Group_Taxonomy::TAXONOMY ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			$terms[ $taxonomy ] = $ids;
		}
		return array( 'post' => $post, 'meta' => $meta, 'terms' => $terms );
	}

	private static function hash( array $state ) {
		return md5( (string) wp_json_encode( $state ) );
	}

	/** Puts a post back exactly as recorded. */
	private static function restore( $post_id, array $state ) {
		global $wpdb;
		$current = get_post( $post_id );
		if ( ! $current || ! $state['post'] ) {
			return;
		}
		if ( $current->post_title !== $state['post']['post_title'] || $current->post_status !== $state['post']['post_status'] || $current->post_name !== $state['post']['post_name'] ) {
			$wpdb->update( $wpdb->posts, array(
				'post_title'        => $state['post']['post_title'],
				'post_status'       => $state['post']['post_status'],
				'post_name'         => $state['post']['post_name'],
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			), array( 'ID' => $post_id ) );
		} else {
			$wpdb->update( $wpdb->posts, array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			), array( 'ID' => $post_id ) );
		}

		$ignored = "'" . implode( "','", array_map( 'esc_sql', self::IGNORED_META ) ) . "'";
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ({$ignored})", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $state['meta'] as list( $key, $value ) ) {
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		foreach ( $state['terms'] as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $taxonomy );
		}
		self::forget_caches( array( $post_id ) );
		clean_post_cache( $post_id );
	}

	private static function forget_caches( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( $post_id, 'post_meta' );
			wp_cache_delete( $post_id, 'posts' );
			clean_object_term_cache( $post_id, get_post_type( $post_id ) ?: GenoRoots_Person_CPT::POST_TYPE );
		}
	}

	// ------------------------------------------------------------------
	// Undo
	// ------------------------------------------------------------------

	public static function get( $change_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $change_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Only the latest step of an undo chain can be undone (or redone).
	 * Administrators and editors undo anything; a genealogy editor only
	 * their own changes.
	 */
	public static function can_undo( array $change, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! empty( $change['undone_by'] ) ) {
			return false;
		}
		if ( user_can( $user_id, GenoRoots_Capabilities::MANAGE_GROUPS ) ) {
			return true;
		}
		return user_can( $user_id, GenoRoots_Capabilities::EDIT ) && (int) $change['user_id'] === (int) $user_id;
	}

	/**
	 * Undoes a change. Refuses if it was undone already, or if any post it
	 * touched has changed since.
	 */
	public static function undo( $change_id, $request_id = '' ) {
		$change = self::get( $change_id );
		if ( ! $change ) {
			throw new GenoRoots_Edit_Error( 'genoroots_no_change', __( 'That change was not found.', 'geotour-genoroots' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $change['undone_by'] ) ) {
			throw new GenoRoots_Edit_Error( 'genoroots_already_undone', __( 'That change has already been undone.', 'geotour-genoroots' ) );
		}

		// Named after the original change: undoing it again and again
		// alternates "Undid" and "Redid".
		list( $original, $depth ) = self::origin( $change );
		$summary = $depth % 2
			/* translators: %s: the change that was redone */
			? sprintf( __( 'Redid: %s', 'geotour-genoroots' ), $original['summary'] )
			/* translators: %s: the change that was undone */
			: sprintf( __( 'Undid: %s', 'geotour-genoroots' ), $original['summary'] );

		return self::run( array(
			'action'       => 'undo',
			'person_id'    => (int) $change['person_id'],
			'request_id'   => $request_id,
			'undo_of'      => (int) $change['id'],
			'summary'      => $summary,
			'after_insert' => function ( $undo_id ) use ( $change ) {
				global $wpdb;
				// Set once: the next step of the chain is now the one to act on.
				$wpdb->update( self::table(), array( 'undone_by' => $undo_id ), array( 'id' => (int) $change['id'] ) );
			},
		), function () use ( $change ) {
			$before = (array) json_decode( $change['before_state'], true );
			$after  = (array) json_decode( $change['after_hash'], true );

			$changed_since = array();
			foreach ( $after as $post_id => $hash ) {
				if ( self::hash( self::state( (int) $post_id ) ) !== $hash ) {
					$changed_since[] = (int) $post_id;
				}
			}
			if ( $changed_since ) {
				throw new GenoRoots_Edit_Error(
					'genoroots_changed_since',
					sprintf(
						/* translators: %s: names of people or families */
						__( 'This can no longer be undone: %s changed after it. Undo the later changes first, or correct it by hand.', 'geotour-genoroots' ),
						implode( ', ', array_map( function ( $id ) {
							return html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						}, $changed_since ) )
					),
					array( 'posts' => $changed_since )
				);
			}

			foreach ( (array) ( $before['posts'] ?? array() ) as $post_id => $state ) {
				self::touch( (int) $post_id );
				self::restore( (int) $post_id, $state );
			}
			foreach ( (array) ( $before['created'] ?? array() ) as $post_id ) {
				self::touch( (int) $post_id );
				wp_trash_post( (int) $post_id );
			}
			return array( 'undone' => (int) $change['id'] );
		} );
	}

	/**
	 * Whether a change's effect is currently undone: the undo chain after it
	 * has an odd number of steps.
	 */
	public static function is_undone( array $change ) {
		$steps = 0;
		while ( ! empty( $change['undone_by'] ) && $steps < 50 ) {
			$next = self::get( (int) $change['undone_by'] );
			if ( ! $next ) {
				break;
			}
			$change = $next;
			$steps++;
		}
		return $steps % 2 === 1;
	}

	/**
	 * The change an undo chain started from, and how many undos deep this
	 * one is (0: not an undo, 1: an undo, 2: a redo…).
	 *
	 * @return array{0: array, 1: int}
	 */
	private static function origin( array $change ) {
		$depth = 0;
		while ( $change['action'] === 'undo' && ! empty( $change['undo_of'] ) && $depth < 50 ) {
			$previous = self::get( (int) $change['undo_of'] );
			if ( ! $previous ) {
				break;
			}
			$change = $previous;
			$depth++;
		}
		return array( $change, $depth );
	}

	// ------------------------------------------------------------------
	// History
	// ------------------------------------------------------------------

	/**
	 * Recent changes, newest first: all, or those that touched one person.
	 *
	 * @return array[]
	 */
	public static function history( $person_id = 0, $limit = 30, $offset = 0 ) {
		global $wpdb;
		$where = $person_id ? $wpdb->prepare( 'WHERE posts LIKE %s', '%,' . (int) $person_id . ',%' ) : '';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, created_at, user_id, action, person_id, summary, undo_of, undone_by FROM ' . self::table() . " {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
			max( 1, min( 100, (int) $limit ) ),
			max( 0, (int) $offset )
		), ARRAY_A );

		return array_map( function ( $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			// An undo's button brings the change back ("Redo"); a redo's undoes it again.
			$redo = self::origin( $row )[1] % 2 === 1;
			return array(
				'id'        => (int) $row['id'],
				'time'      => gmdate( 'c', strtotime( $row['created_at'] . ' UTC' ) ),
				'user_id'   => (int) $row['user_id'],
				'user'      => $user ? $user->display_name : '',
				'action'    => $row['action'],
				'person_id' => (int) $row['person_id'],
				'summary'   => $row['summary'],
				'undo_of'   => $row['undo_of'] ? (int) $row['undo_of'] : null,
				'undone_by' => $row['undone_by'] ? (int) $row['undone_by'] : null,
				// Shown struck through: an original change whose effect is now undone.
				'undone'    => $row['action'] !== 'undo' && self::is_undone( $row ),
				'can_undo'  => self::can_undo( $row ),
				'button'    => $redo ? 'redo' : 'undo',
			);
		}, $rows );
	}
}
