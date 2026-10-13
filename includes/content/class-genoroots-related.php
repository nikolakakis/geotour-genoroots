<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Related article": a person can point to one page on the same site about
 * them (a biography, an article). Any public content type is allowed, so
 * GenoRoots stays independent: on GeoTour it is a geotour_people entry, on
 * Kamilari an article, elsewhere anything.
 *
 * The link is stored as a post ID, not a URL: get_permalink() and
 * get_the_title() follow the page's language through whatever multilingual
 * plugin the site uses. For the same reason it is site-specific and bundles
 * skip it (an ID means nothing on another site).
 *
 * The way back, from the article to the tree, is the [genoroots_family_link]
 * shortcode, which the article can include.
 */
class GenoRoots_Related {

	const FIELD     = 'related_post';
	const FIELD_KEY = 'field_genoroots_person_related_post';

	public static function init() {
		add_filter( 'acf/fields/post_object/query/key=' . self::FIELD_KEY, array( __CLASS__, 'limit_choices' ) );
		add_shortcode( 'genoroots_family_link', array( __CLASS__, 'family_link' ) );
	}

	/**
	 * Content a person may point to: every public type except media and
	 * GenoRoots' own. Read when the picker searches, so types registered by
	 * other plugins are all there.
	 */
	public static function linkable_types() {
		$types = array_diff(
			get_post_types( array( 'public' => true ) ),
			array( 'attachment', GenoRoots_Person_CPT::POST_TYPE, GenoRoots_Family_CPT::POST_TYPE )
		);
		return array_values( (array) apply_filters( 'genoroots_related_post_types', $types ) );
	}

	public static function limit_choices( $args ) {
		$args['post_type']   = self::linkable_types();
		$args['post_status'] = 'publish';
		return $args;
	}

	/**
	 * The person's related article, if it is published: url and title in
	 * the current language.
	 *
	 * @return array{url:string,title:string}|null
	 */
	public static function of_person( $person_id ) {
		$post_id = (int) get_field( self::FIELD, $person_id, false );
		if ( ! $post_id || get_post_status( $post_id ) !== 'publish' ) {
			return null;
		}
		return array(
			'url'   => (string) get_permalink( $post_id ),
			'title' => (string) get_the_title( $post_id ),
		);
	}

	/** Published people whose related article is this post. */
	public static function people_linking_to( $post_id ) {
		return get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'meta_key'       => self::FIELD, // phpcs:ignore -- small, indexed by post
			'meta_value'     => (int) $post_id, // phpcs:ignore
		) );
	}

	/**
	 * The interactive tree centred on a person: their collection's page (or
	 * the archive) with ?person=ID.
	 */
	public static function tree_url( $person_id ) {
		$collection = GenoRoots_Collection_Taxonomy::of_person( $person_id );
		$base       = $collection ? get_term_link( $collection ) : '';
		if ( ! $base || is_wp_error( $base ) ) {
			$base = get_post_type_archive_link( GenoRoots_Person_CPT::POST_TYPE );
		}
		return add_query_arg( 'person', (int) $person_id, $base );
	}

	/**
	 * [genoroots_family_link post=""] — on an article, a card leading to the
	 * family tree of the person who points to it: a small drawing of their
	 * immediate family (parents, spouse, children), the tree's name and size.
	 * Prints nothing when no person points here, so it is safe to leave in a
	 * template.
	 */
	public static function family_link( $atts ) {
		$atts    = shortcode_atts( array( 'post' => 0 ), $atts, 'genoroots_family_link' );
		$post_id = (int) $atts['post'] ?: get_the_ID();
		$people  = $post_id ? self::people_linking_to( $post_id ) : array();
		if ( ! $people ) {
			return '';
		}
		$cards = '';
		foreach ( $people as $person_id ) {
			$cards .= self::family_card( (int) $person_id, count( $people ) > 1 );
		}
		return self::card_styles() . '<div class="gnr-family-cards">' . $cards . '</div>';
	}

	private static function family_card( $person_id, $several ) {
		$name       = wp_strip_all_tags( html_entity_decode( get_the_title( $person_id ), ENT_QUOTES, 'UTF-8' ) );
		$collection = GenoRoots_Collection_Taxonomy::of_person( $person_id );
		$title      = ( $collection && ! $several ) ? $collection->name : $name;

		// The tree's size: its people and the family groups among them.
		$ids = get_posts( array(
			'post_type'      => GenoRoots_Person_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => $collection ? array( GenoRoots_Collection_Taxonomy::tax_clause( urldecode( $collection->slug ) ) ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$groups = $ids ? wp_get_object_terms( $ids, GenoRoots_Family_Group_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) ) : array();
		$groups = is_wp_error( $groups ) ? 0 : count( array_unique( $groups ) );
		/* translators: %d: number of people in the family tree */
		$meta = sprintf( _n( '%d person', '%d people', count( $ids ), 'geotour-genoroots' ), count( $ids ) );
		if ( $groups ) {
			/* translators: %d: number of family groups */
			$meta .= ' · ' . sprintf( _n( '%d family group', '%d family groups', $groups, 'geotour-genoroots' ), $groups );
		}

		/* translators: %s: person's name */
		$label = sprintf( __( 'Family tree of %s', 'geotour-genoroots' ), $name );

		return sprintf(
			'<a class="gnr-family-card" href="%1$s" aria-label="%2$s">'
				. '<span class="gnr-family-card__graphic" aria-hidden="true">%3$s</span>'
				. '<span class="gnr-family-card__text">'
					. '<span class="gnr-family-card__eyebrow">%4$s</span>'
					. '<span class="gnr-family-card__title">%5$s</span>'
					. '<span class="gnr-family-card__meta">%6$s</span>'
				. '</span>'
				. '<span class="gnr-family-card__go">%7$s <span class="gnr-family-card__arrow" aria-hidden="true">→</span></span>'
			. '</a>',
			esc_url( self::tree_url( $person_id ) ),
			esc_attr( $label ),
			self::mini_tree( $person_id, $name ),
			esc_html__( 'Family tree', 'geotour-genoroots' ),
			esc_html( $title ),
			esc_html( $meta ),
			esc_html__( 'Explore the tree', 'geotour-genoroots' )
		);
	}

	/**
	 * The person's immediate family as a small SVG: parents above, the person
	 * (with their portrait) and a spouse in the middle, up to four children
	 * below ("+n" for the rest). Drawn from the real relations.
	 */
	private static function mini_tree( $person_id, $name ) {
		$parents  = array_slice( GenoRoots_Relatives::get_parents( $person_id ), 0, 2 );
		$spouses  = GenoRoots_Relatives::get_spouses( $person_id );
		$children = GenoRoots_Relatives::get_children( $person_id );

		$w      = 132;
		$me     = array( 'x' => $spouses ? 52 : 66, 'y' => 54 );
		$mate   = array( 'x' => 92, 'y' => 54 );
		$stem_x = $spouses ? ( $me['x'] + $mate['x'] ) / 2 : $me['x'];
		$lines  = '';
		$nodes  = '';
		$node   = static function ( $x, $y, $r, $class ) {
			return sprintf( '<circle class="%s" cx="%s" cy="%s" r="%s"/>', $class, $x, $y, $r );
		};
		$line   = static function ( $points ) {
			return '<polyline class="gnr-mt__line" points="' . esc_attr( $points ) . '"/>';
		};

		if ( $parents ) {
			$xs = count( $parents ) === 2 ? array( $me['x'] - 13, $me['x'] + 13 ) : array( $me['x'] );
			if ( count( $xs ) === 2 ) {
				$lines .= $line( "{$xs[0]},16 {$xs[1]},16" );
			}
			$lines .= $line( "{$me['x']},16 {$me['x']},{$me['y']}" );
			foreach ( $xs as $x ) {
				$nodes .= $node( $x, 16, 6.5, 'gnr-mt__kin' );
			}
		}
		if ( $spouses ) {
			$lines .= $line( "{$me['x']},{$me['y']} {$mate['x']},{$mate['y']}" );
			$nodes .= $node( $mate['x'], $mate['y'], 8.5, 'gnr-mt__mate' );
		}
		if ( $children ) {
			$shown = count( $children ) > 4 ? 3 : count( $children );
			$slots = $shown + ( count( $children ) > 4 ? 1 : 0 );
			$gap   = 24;
			$start = max( 12, min( $w - 12 - ( $slots - 1 ) * $gap, $stem_x - ( $slots - 1 ) * $gap / 2 ) );
			$xs    = array();
			for ( $i = 0; $i < $slots; $i++ ) {
				$xs[] = $start + $i * $gap;
			}
			$lines .= $line( "{$stem_x},{$me['y']} {$stem_x},78" );
			$lines .= $line( reset( $xs ) . ',78 ' . end( $xs ) . ',78' );
			foreach ( $xs as $i => $x ) {
				$lines .= $line( "{$x},78 {$x},86" );
				if ( $i < $shown ) {
					$nodes .= $node( $x, 92, 6.5, 'gnr-mt__kin' );
				} else {
					$nodes .= sprintf( '<rect class="gnr-mt__more" x="%s" y="84" width="22" height="16" rx="8"/><text class="gnr-mt__more-text" x="%s" y="95.5" text-anchor="middle">+%d</text>', $x - 11, $x, count( $children ) - $shown );
				}
			}
		}

		// The person: portrait in a circle, or their initial.
		$clip     = 'gnr-mt-' . $person_id;
		$portrait = get_the_post_thumbnail_url( $person_id, 'thumbnail' );
		$me_svg   = $node( $me['x'], $me['y'], 15, 'gnr-mt__me' );
		if ( $portrait ) {
			$me_svg .= sprintf(
				'<clipPath id="%1$s"><circle cx="%2$s" cy="%3$s" r="13"/></clipPath><image href="%4$s" x="%5$s" y="%6$s" width="26" height="26" clip-path="url(#%1$s)" preserveAspectRatio="xMidYMid slice"/>',
				esc_attr( $clip ),
				$me['x'],
				$me['y'],
				esc_url( $portrait ),
				$me['x'] - 13,
				$me['y'] - 13
			);
		} else {
			$me_svg .= sprintf( '<text class="gnr-mt__initial" x="%s" y="%s" text-anchor="middle">%s</text>', $me['x'], $me['y'] + 5, esc_html( mb_substr( $name, 0, 1 ) ) );
		}

		return '<svg class="gnr-mt" viewBox="0 0 ' . $w . ' 104" width="' . $w . '" height="104" focusable="false">' . $lines . $nodes . $me_svg . '</svg>';
	}

	/** Printed once per page, with the first card. */
	private static function card_styles() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style id="gnr-family-card-css">'
			. '.gnr-family-cards{display:grid;gap:12px;margin:28px 0}'
			. '.gnr-family-card{--a:var(--gnr-accent,#8a5a3c);--l:var(--gnr-line,#c9b8a3);display:flex;align-items:center;gap:20px;max-width:620px;padding:14px 22px 14px 14px;border:1px solid var(--l)!important;border-radius:16px;background:linear-gradient(135deg,var(--gnr-bg,#faf8f5) 0%,#fff 72%);color:var(--gnr-ink,#2b2420);text-decoration:none!important;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}'
			. '.gnr-family-card:hover,.gnr-family-card:focus-visible{border-color:var(--a)!important;transform:translateY(-2px);box-shadow:0 12px 28px color-mix(in srgb,var(--a) 18%,transparent);outline:none}'
			. '.gnr-family-card__graphic{flex:none;display:flex;padding:6px 8px;border-radius:12px;background:#fff;box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--l) 70%,transparent)}'
			. '.gnr-mt__line{fill:none;stroke:var(--l);stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;transition:stroke .2s ease}'
			. '.gnr-family-card:hover .gnr-mt__line{stroke:color-mix(in srgb,var(--a) 55%,var(--l))}'
			. '.gnr-mt__kin{fill:#fff;stroke:var(--a);stroke-width:1.6}'
			. '.gnr-mt__mate{fill:color-mix(in srgb,var(--a) 14%,#fff);stroke:var(--a);stroke-width:1.6}'
			. '.gnr-mt__me{fill:var(--a);stroke:#fff;stroke-width:2;filter:drop-shadow(0 2px 4px color-mix(in srgb,var(--a) 35%,transparent))}'
			. '.gnr-mt__initial{fill:#fff;font:600 14px/1 system-ui,sans-serif}'
			. '.gnr-mt__more{fill:color-mix(in srgb,var(--a) 12%,#fff);stroke:var(--a);stroke-width:1.2}'
			. '.gnr-mt__more-text{fill:var(--a);font:600 9px/1 system-ui,sans-serif}'
			. '.gnr-family-card__text{display:flex;flex-direction:column;gap:3px;min-width:0}'
			. '.gnr-family-card__eyebrow{font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--a)}'
			. '.gnr-family-card__title{font-size:1.25rem;font-weight:600;line-height:1.25;color:var(--gnr-ink,#2b2420)}'
			. '.gnr-family-card__meta{font-size:.88rem;color:var(--gnr-muted,#7a6f63)}'
			. '.gnr-family-card__go{margin-left:auto;flex:none;font-size:.9rem;font-weight:600;color:var(--a);white-space:nowrap}'
			. '.gnr-family-card__arrow{display:inline-block;transition:transform .2s ease}'
			. '.gnr-family-card:hover .gnr-family-card__arrow{transform:translateX(4px)}'
			. '@media (max-width:560px){.gnr-family-card{flex-wrap:wrap;gap:12px 16px;padding:14px}.gnr-family-card__go{margin-left:0;width:100%}}'
			. '@media (prefers-reduced-motion:reduce){.gnr-family-card,.gnr-family-card__arrow{transition:none}}'
			. '</style>';
	}
}
