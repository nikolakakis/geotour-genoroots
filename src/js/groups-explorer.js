import { select, zoom, zoomIdentity, zoomTransform, timer, easeCubicInOut, packSiblings } from 'd3';
import { fetchGroups, fetchMembers } from './data.js';
import { t, tf, tn } from './i18n.js';
import { escapeHtml, splitName, lifespan, searchKey } from './format.js';

// User-visible strings; translations come from the plugin's catalogue (i18n.js).
const STRINGS = {
	allGroups: t( 'allGroups' ),
	findGroup: t( 'findGroup' ),
	smallGroups: t( 'smallGroups' ),
	noGroups: t( 'noGroups' ),
	noMatch: t( 'noMatch' ),
	hint: t( 'groupsHint' ),
	members: tn( 'members' ),
	shared: tn( 'shared' ),
	sharedShort: tn( 'sharedShort' ),
	relatedTitle: t( 'relatedTitle' ),
	personTip: t( 'personTip' ),
	searchMembers: t( 'searchMembers' ),
	more: t( 'more' ),
	noMembers: t( 'noMembers' ),
	removeGroup: tf( 'removeGroup' ),
	close: t( 'close' ),
	loadError: t( 'loadError' ),
};

const R_UNIT = 10; // world radius of a 1-member group; area is proportional to members
const MIN_R = 12; // floor so tiny groups stay tappable
const PACK_GAP = 8;
const NEIGHBOUR_GAP = 10;
const MAX_NEIGHBOURS = 8;
const DURATION = 700;
const SMALL_GROUP = 3; // "small" = fewer members than this
const AUTO_HIDE_SMALL_OVER = 30; // hide small groups by default once there are this many groups
const MIN_LABEL_R = 16; // screen px; smaller circles show their name on hover only
const MIN_COUNT_R = 40; // screen px; below this the label drops the member count
const MAX_SCALE = 3.5;
const FIT_PADDING = 28;

const PHOTO_TURN_MS = 1800; // one circle's portrait changes per turn
// First-visit tip over the member list, until a person is opened once.
const TIP_SEEN_KEY = 'genoroots-person-tip-seen';
const tipSeen = () => {
	try {
		return window.localStorage.getItem( TIP_SEEN_KEY ) === '1';
	} catch ( e ) {
		return false;
	}
};
const markTipSeen = () => {
	try {
		window.localStorage.setItem( TIP_SEEN_KEY, '1' );
	} catch ( e ) {}
};
const prefersReducedMotion = () => window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

function radiusFor( count ) {
	return Math.max( MIN_R, Math.sqrt( count ) * R_UNIT );
}

/** Area shared by two circles whose centres are `d` apart. */
function lensArea( r1, r2, d ) {
	if ( d >= r1 + r2 ) {
		return 0;
	}
	if ( d <= Math.abs( r1 - r2 ) ) {
		return Math.PI * Math.min( r1, r2 ) ** 2;
	}
	const a1 = r1 * r1 * Math.acos( ( d * d + r1 * r1 - r2 * r2 ) / ( 2 * d * r1 ) );
	const a2 = r2 * r2 * Math.acos( ( d * d + r2 * r2 - r1 * r1 ) / ( 2 * d * r2 ) );
	const k = 0.5 * Math.sqrt( ( -d + r1 + r2 ) * ( d + r1 - r2 ) * ( d - r1 + r2 ) * ( d + r1 + r2 ) );
	return a1 + a2 - k;
}

/** Centre distance at which two circles share exactly `area` (area shrinks as d grows). */
function distanceForOverlap( r1, r2, area ) {
	if ( area >= Math.PI * Math.min( r1, r2 ) ** 2 ) {
		return Math.abs( r1 - r2 );
	}
	let lo = Math.abs( r1 - r2 );
	let hi = r1 + r2;
	for ( let i = 0; i < 40; i++ ) {
		const mid = ( lo + hi ) / 2;
		if ( lensArea( r1, r2, mid ) > area ) {
			lo = mid;
		} else {
			hi = mid;
		}
	}
	return ( lo + hi ) / 2;
}

const lerp = ( a, b, t ) => a + ( b - a ) * t;

/**
 * Groups explorer: every group as a bubble sized by members; pick one to
 * put it in the middle with the groups it shares members with around it,
 * each overlap drawn to scale. Picking a group or an overlap slides in the
 * matching members; picking a member hands off to the tree.
 *
 * Returns { setSelection(slugs, { silent, animate }), getGroup(slug) }.
 */
export function createGroupsExplorer( mountEl, { selection: initialSelection = [], onSelectionChange, onOpenPerson, onReady } = {} ) {
	const uid = `gnr-groups-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;

	mountEl.classList.add( 'gnr-groups' );
	mountEl.innerHTML = `
		<div class="gnr-groups__bar">
			<button type="button" class="gnr-groups__home" hidden>
				<span aria-hidden="true">←</span> ${ STRINGS.allGroups }
			</button>
			<input type="search" class="gnr-groups__filter" placeholder="${ STRINGS.findGroup }" aria-label="${ STRINGS.findGroup }" autocomplete="off">
			<label class="gnr-groups__small" hidden>
				<input type="checkbox"> ${ STRINGS.smallGroups }
			</label>
		</div>
		<svg class="gnr-groups__svg" role="group">
			<defs><clipPath id="${ uid }-clip"><circle></circle></clipPath></defs>
			<g class="gnr-groups__photos"></g>
			<g class="gnr-groups__circles"></g>
			<g class="gnr-groups__lenses"></g>
		</svg>
		<div class="gnr-groups__labels" aria-hidden="true"></div>
		<p class="gnr-groups__empty" hidden></p>
		<p class="gnr-groups__hint">${ STRINGS.hint }</p>
		<aside class="gnr-groups__sheet" aria-live="polite"></aside>
		<div class="gnr-tree__loading" aria-hidden="true"></div>
	`;

	const svgEl = mountEl.querySelector( '.gnr-groups__svg' );
	const svg = select( svgEl );
	const clipCircle = svg.select( 'clipPath circle' );
	const photosLayer = svg.select( '.gnr-groups__photos' );
	const circlesLayer = svg.select( '.gnr-groups__circles' );
	const lensesLayer = svg.select( '.gnr-groups__lenses' );
	const labelsEl = mountEl.querySelector( '.gnr-groups__labels' );
	const homeEl = mountEl.querySelector( '.gnr-groups__home' );
	const filterEl = mountEl.querySelector( '.gnr-groups__filter' );
	const smallEl = mountEl.querySelector( '.gnr-groups__small' );
	const emptyEl = mountEl.querySelector( '.gnr-groups__empty' );
	const hintEl = mountEl.querySelector( '.gnr-groups__hint' );
	const sheetEl = mountEl.querySelector( '.gnr-groups__sheet' );

	const nodes = new Map(); // slug -> node
	const overlapsBySlug = new Map(); // slug -> [{ slug, shared }]
	let selection = [];
	let filterText = '';
	let showSmall = true;
	let progress = 1;
	let tweenTimer = null;
	let placedNeighbours = []; // [{ slug, shared }] currently drawn around the centre
	let membersRequest = 0;

	// ---- Layouts (world coordinates; area ∝ members) ----

	function isVisibleInAll( node ) {
		if ( ! showSmall && node.group.count < SMALL_GROUP ) {
			return false;
		}
		return ! filterText || node.key.includes( filterText );
	}

	function hiddenTarget( node ) {
		const at = node.cur || { x: 0, y: 0 };
		return { x: at.x, y: at.y, r: 0, o: 0, l: 0, lx: at.x, ly: at.y, kx: at.x, ky: at.y };
	}

	function layoutAll() {
		const circles = [ ...nodes.values() ]
			.filter( isVisibleInAll )
			.map( ( node ) => ( { slug: node.group.slug, r: node.r + PACK_GAP / 2 } ) )
			.sort( ( a, b ) => b.r - a.r );
		packSiblings( circles );

		const targets = new Map();
		circles.forEach( ( c ) => {
			targets.set( c.slug, { x: c.x, y: c.y, r: c.r - PACK_GAP / 2, o: 1, l: 0, lx: c.x, ly: c.y, kx: c.x, ky: c.y } );
		} );
		placedNeighbours = [];
		return targets;
	}

	function layoutFocus( centerSlug, partnerSlug ) {
		const center = nodes.get( centerSlug );
		const R0 = center.r;
		const targets = new Map();
		targets.set( centerSlug, { x: 0, y: 0, r: R0, o: 1, l: 0, lx: 0, ly: 0, kx: 0, ky: 0 } );

		// Biggest overlaps first, but the selected partner always gets a place.
		const candidates = ( overlapsBySlug.get( centerSlug ) || [] )
			.filter( ( n ) => nodes.has( n.slug ) )
			.sort( ( a, b ) => ( b.slug === partnerSlug ) - ( a.slug === partnerSlug ) || b.shared - a.shared );

		// Keep adding neighbours (biggest overlaps first) while the ring still
		// closes without two of them touching — touching would suggest members
		// they don't share. The rest stay listed in the panel.
		let placed = [];
		for ( const candidate of candidates ) {
			if ( placed.length >= MAX_NEIGHBOURS ) {
				break;
			}
			const r = nodes.get( candidate.slug ).r;
			const d = distanceForOverlap( R0, r, Math.PI * R_UNIT * R_UNIT * candidate.shared );
			const ring = arrangeRing( [ ...placed, { ...candidate, r, d } ] );
			if ( ringAngle( ring ) <= 2 * Math.PI ) {
				placed = ring;
			}
		}

		// Spread the spare angle evenly; first neighbour to the right.
		const total = ringAngle( placed );
		const spare = placed.length ? ( 2 * Math.PI - total ) / placed.length : 0;
		let angle = 0;
		placed.forEach( ( p, i ) => {
			if ( i > 0 ) {
				angle += separation( placed[ i - 1 ], p ) + spare;
			}
			const ux = Math.cos( angle );
			const uy = Math.sin( angle );
			const labelDist = p.d + p.r * 0.5;
			const lensDist = ( R0 + p.d - p.r ) / 2;
			targets.set( p.slug, {
				x: ux * p.d,
				y: uy * p.d,
				r: p.r,
				o: 1,
				l: 1,
				lx: ux * labelDist,
				ly: uy * labelDist,
				kx: ux * lensDist,
				ky: uy * lensDist,
			} );
		} );

		// Centre name goes in the middle of the widest empty angle, off the overlaps.
		if ( placed.length ) {
			const angles = placed
				.map( ( p ) => Math.atan2( targets.get( p.slug ).y, targets.get( p.slug ).x ) )
				.sort( ( a, b ) => a - b );
			let bestGap = -1;
			let bestMid = 0;
			angles.forEach( ( a, i ) => {
				const next = i + 1 < angles.length ? angles[ i + 1 ] : angles[ 0 ] + 2 * Math.PI;
				if ( next - a > bestGap ) {
					bestGap = next - a;
					bestMid = ( a + next ) / 2;
				}
			} );
			const centre = targets.get( centerSlug );
			centre.lx = Math.cos( bestMid ) * R0 * 0.55;
			centre.ly = Math.sin( bestMid ) * R0 * 0.55;
		}

		placedNeighbours = placed.map( ( p ) => ( { slug: p.slug, shared: p.shared } ) );
		return targets;
	}

	/** Smallest angle (around the centre) that keeps two neighbours from touching. */
	function separation( a, b ) {
		if ( a.d < 1 || b.d < 1 ) {
			return Math.PI;
		}
		const need = a.r + b.r + NEIGHBOUR_GAP;
		const cos = ( a.d * a.d + b.d * b.d - need * need ) / ( 2 * a.d * b.d );
		return cos <= -1 ? Math.PI : cos >= 1 ? 0 : Math.acos( cos );
	}

	/** Total angle a closed ring of neighbours needs, in the given order. */
	function ringAngle( ring ) {
		if ( ring.length < 2 ) {
			return 0;
		}
		return ring.reduce( ( sum, p, i ) => sum + separation( p, ring[ ( i + 1 ) % ring.length ] ), 0 );
	}

	/** Alternates big and small circles round the ring, which packs tighter. */
	function arrangeRing( list ) {
		const bySize = [ ...list ].sort( ( a, b ) => b.r - a.r );
		const ring = [];
		while ( bySize.length ) {
			ring.push( bySize.shift() );
			if ( bySize.length ) {
				ring.push( bySize.pop() );
			}
		}
		return ring;
	}

	// ---- Rendering (screen coordinates, one pass per animation frame) ----

	function currentState( node ) {
		const from = node.from;
		const to = node.to;
		const p = progress;
		const state = {};
		for ( const key of [ 'x', 'y', 'r', 'o', 'l', 'lx', 'ly', 'kx', 'ky' ] ) {
			state[ key ] = lerp( from[ key ], to[ key ], p );
		}
		return state;
	}

	function render() {
		const t = zoomTransform( svgEl );
		const centerSlug = selection[ 0 ];
		const partnerSlug = selection[ 1 ];

		nodes.forEach( ( node ) => {
			const s = currentState( node );
			node.cur = s;
			const [ sx, sy ] = t.apply( [ s.x, s.y ] );
			const sr = Math.max( 0, s.r * t.k );
			const interactive = s.o > 0.5;

			node.circle
				.attr( 'cx', sx )
				.attr( 'cy', sy )
				.attr( 'r', sr )
				.style( 'opacity', s.o )
				.style( 'pointer-events', interactive ? null : 'none' )
				.attr( 'tabindex', interactive ? 0 : -1 )
				.classed( 'is-center', node.group.slug === centerSlug );

			node.lens
				.attr( 'cx', sx )
				.attr( 'cy', sy )
				.attr( 'r', sr )
				.style( 'opacity', s.l )
				.style( 'pointer-events', s.l > 0.5 ? null : 'none' )
				.attr( 'tabindex', s.l > 0.5 ? 0 : -1 )
				.classed( 'is-selected', node.group.slug === partnerSlug );

			const [ lx, ly ] = t.apply( [ s.lx, s.ly ] );
			node.label.style.transform = `translate(${ lx }px, ${ ly }px) translate(-50%, -50%)`;
			node.label.style.opacity = sr >= MIN_LABEL_R ? s.o : 0;
			node.label.style.maxWidth = `${ Math.round( Math.min( 170, sr * 1.8 ) ) }px`;
			node.label.classList.toggle( 'is-compact', sr < MIN_COUNT_R );

			const [ kx, ky ] = t.apply( [ s.kx, s.ky ] );
			node.lensLabel.style.transform = `translate(${ kx }px, ${ ky }px) translate(-50%, -50%)`;
			node.lensLabel.style.opacity = s.l;
			node.lensLabel.classList.toggle( 'is-selected', node.group.slug === partnerSlug );

			if ( node.photos ) {
				node.photoClip.attr( 'cx', sx ).attr( 'cy', sy ).attr( 'r', sr );
				node.photos.style( 'opacity', s.o );
				node.photoImages.attr( 'x', sx - sr ).attr( 'y', sy - sr ).attr( 'width', sr * 2 ).attr( 'height', sr * 2 );
			}

			if ( node.group.slug === centerSlug ) {
				clipCircle.attr( 'cx', sx ).attr( 'cy', sy ).attr( 'r', sr );
			}
		} );
	}

	function animateTo( targets, { animate = true } = {} ) {
		nodes.forEach( ( node ) => {
			node.from = node.cur || targets.get( node.group.slug ) || hiddenTarget( node );
			node.to = targets.get( node.group.slug ) || hiddenTarget( node );
		} );

		if ( tweenTimer ) {
			tweenTimer.stop();
		}
		const duration = animate && ! prefersReducedMotion() ? DURATION : 0;
		if ( ! duration ) {
			progress = 1;
			render();
		} else {
			progress = 0;
			tweenTimer = timer( ( elapsed ) => {
				progress = easeCubicInOut( Math.min( 1, elapsed / duration ) );
				render();
				if ( elapsed >= duration ) {
					tweenTimer.stop();
					tweenTimer = null;
				}
			} );
		}
		fitView( targets, duration );
	}

	/** The part of the mount the circles may use (minus the bar and the open panel). */
	function drawableArea() {
		const width = mountEl.clientWidth;
		const height = mountEl.clientHeight;
		const barHeight = mountEl.querySelector( '.gnr-groups__bar' ).offsetHeight + 12;
		const open = mountEl.classList.contains( 'has-sheet' );
		const bottomSheet = open && sheetEl.offsetWidth >= width - 2;
		const area = { x: 0, y: barHeight, w: width, h: height - barHeight };
		if ( ! hintEl.classList.contains( 'is-dismissed' ) ) {
			area.h -= 40; // keep the first view clear of the hint pill
		}
		if ( open && bottomSheet ) {
			area.h -= sheetEl.offsetHeight;
		} else if ( open ) {
			area.w -= sheetEl.offsetWidth + 12;
		}
		return area;
	}

	function fitView( targets, duration = 0 ) {
		let x0 = Infinity;
		let y0 = Infinity;
		let x1 = -Infinity;
		let y1 = -Infinity;
		targets.forEach( ( c ) => {
			if ( c.o <= 0 ) {
				return;
			}
			x0 = Math.min( x0, c.x - c.r );
			y0 = Math.min( y0, c.y - c.r );
			x1 = Math.max( x1, c.x + c.r );
			y1 = Math.max( y1, c.y + c.r );
		} );
		if ( ! Number.isFinite( x0 ) ) {
			return;
		}
		const area = drawableArea();
		const k = Math.min(
			MAX_SCALE,
			( area.w - 2 * FIT_PADDING ) / ( x1 - x0 ),
			( area.h - 2 * FIT_PADDING ) / ( y1 - y0 )
		);
		const tx = area.x + area.w / 2 - ( ( x0 + x1 ) / 2 ) * k;
		const ty = area.y + area.h / 2 - ( ( y0 + y1 ) / 2 ) * k;
		const transform = zoomIdentity.translate( tx, ty ).scale( k );
		if ( duration ) {
			svg.transition().duration( duration ).ease( easeCubicInOut ).call( zoomBehavior.transform, transform );
		} else {
			svg.call( zoomBehavior.transform, transform );
		}
	}

	const zoomBehavior = zoom()
		.scaleExtent( [ 0.1, 12 ] )
		.on( 'zoom', render );
	svg.call( zoomBehavior ).on( 'dblclick.zoom', null );

	// ---- Selection ----

	function currentTargets() {
		return selection.length ? layoutFocus( selection[ 0 ], selection[ 1 ] ) : layoutAll();
	}

	function refreshChrome() {
		const focused = selection.length > 0;
		mountEl.classList.toggle( 'is-focused', focused );
		mountEl.classList.toggle( 'has-sheet', focused );
		homeEl.hidden = ! focused;
		filterEl.hidden = focused;
		smallEl.hidden = focused || ! [ ...nodes.values() ].some( ( n ) => n.group.count < SMALL_GROUP );

		const anyVisible = focused || [ ...nodes.values() ].some( isVisibleInAll );
		emptyEl.hidden = anyVisible;
		emptyEl.textContent = nodes.size ? STRINGS.noMatch : STRINGS.noGroups;
	}

	function setSelection( slugs, { silent = false, animate = true } = {} ) {
		const next = [ ...new Set( ( slugs || [] ).filter( ( slug ) => nodes.has( slug ) ) ) ].slice( 0, 2 );
		const changed = next.join( '|' ) !== selection.join( '|' );
		selection = next;
		if ( selection.length ) {
			nodes.forEach( ( node ) => node.updateLensText( selection[ 0 ] ) );
		}
		if ( selection.length ) {
			hintEl.classList.add( 'is-dismissed' );
		}
		refreshChrome();
		animateTo( currentTargets(), { animate } );
		renderSheet();
		if ( changed && ! silent && onSelectionChange ) {
			onSelectionChange( [ ...selection ] );
		}
	}

	function onCircleActivate( node ) {
		const slug = node.group.slug;
		if ( ! selection.length || slug !== selection[ 0 ] ) {
			setSelection( [ slug ] );
		} else if ( selection.length > 1 ) {
			setSelection( [ slug ] ); // centre clicked while an overlap is open: back to the whole group
		}
	}

	function onLensActivate( node ) {
		const partner = node.group.slug;
		setSelection( selection[ 1 ] === partner ? [ selection[ 0 ] ] : [ selection[ 0 ], partner ] );
	}

	// ---- Member panel ----

	function groupName( slug ) {
		const node = nodes.get( slug );
		return node ? node.group.name : slug;
	}

	function renderSheet() {
		if ( ! selection.length ) {
			return;
		}
		const related = ( overlapsBySlug.get( selection[ 0 ] ) || [] ).filter( ( n ) => nodes.has( n.slug ) );
		const title = selection.length > 1
			? `${ escapeHtml( groupName( selection[ 0 ] ) ) } <span class="gnr-sheet__amp">∩</span> ${ escapeHtml( groupName( selection[ 1 ] ) ) }`
			: escapeHtml( groupName( selection[ 0 ] ) );

		sheetEl.innerHTML = `
			<div class="gnr-sheet__head">
				<div class="gnr-sheet__chips">
					${ selection.map( ( slug ) => `
						<button type="button" class="gnr-chip" data-remove="${ escapeHtml( slug ) }" aria-label="${ escapeHtml( STRINGS.removeGroup( groupName( slug ) ) ) }">
							${ escapeHtml( groupName( slug ) ) } <span aria-hidden="true">✕</span>
						</button>
					` ).join( '' ) }
				</div>
				<h2 class="gnr-sheet__title">${ title }</h2>
				<p class="gnr-sheet__count">&nbsp;</p>
				<input type="search" class="gnr-sheet__search" placeholder="${ STRINGS.searchMembers }" aria-label="${ STRINGS.searchMembers }" autocomplete="off">
			</div>
			${ selection.length === 1 && related.length ? `
				<div class="gnr-sheet__related">
					<span class="gnr-sheet__related-title">${ STRINGS.relatedTitle }</span>
					${ related.map( ( n ) => `
						<button type="button" class="gnr-chip gnr-chip--soft" data-with="${ escapeHtml( n.slug ) }">
							${ escapeHtml( groupName( n.slug ) ) } <span class="gnr-chip__count">${ n.shared }</span>
						</button>
					` ).join( '' ) }
				</div>
			` : '' }
			${ tipSeen() ? '' : `
				<div class="gnr-sheet__tip" role="note">
					<span>${ STRINGS.personTip }</span>
					<button type="button" class="gnr-sheet__tip-close" aria-label="${ STRINGS.close }">✕</button>
				</div>
			` }
			<ul class="gnr-sheet__list"></ul>
			<button type="button" class="gnr-sheet__more" hidden>${ STRINGS.more }</button>
		`;
		loadMembers( { page: 1 } );
	}

	let memberQuery = '';
	let memberPage = 1;

	function memberRow( person, index ) {
		const { surname, given } = splitName( person.name );
		const years = lifespan( person );
		const others = ( person.groups || [] ).filter( ( slug ) => ! selection.includes( slug ) && nodes.has( slug ) );
		const sex = ( person.sex || 'U' ).toLowerCase();
		return `
			<li class="gnr-member" style="--i:${ Math.min( index, 12 ) }">
				<button type="button" class="gnr-member__button" data-person="${ person.id }">
					<span class="gnr-member__avatar gnr-member__avatar--${ sex }" aria-hidden="true">${ person.portrait
						? `<img src="${ escapeHtml( person.portrait ) }" alt="" loading="lazy">`
						: escapeHtml( ( given || surname ).charAt( 0 ) ) }</span>
					<span class="gnr-member__text">
						<span class="gnr-member__name">${ escapeHtml( given ) } <span class="gnr-member__surname">${ escapeHtml( surname ) }</span></span>
						${ years || others.length ? `<span class="gnr-member__meta">${ escapeHtml( years ) }${ others.map( ( slug ) => `<span class="gnr-member__tag">${ escapeHtml( groupName( slug ) ) }</span>` ).join( '' ) }</span>` : '' }
					</span>
					<span class="gnr-member__go" aria-hidden="true">→</span>
				</button>
			</li>
		`;
	}

	async function loadMembers( { page } ) {
		const request = ++membersRequest;
		const listEl = sheetEl.querySelector( '.gnr-sheet__list' );
		const countEl = sheetEl.querySelector( '.gnr-sheet__count' );
		const moreEl = sheetEl.querySelector( '.gnr-sheet__more' );
		memberPage = page;
		mountEl.classList.add( 'is-busy' );
		if ( page === 1 ) {
			listEl.classList.add( 'is-loading' );
		}
		try {
			const result = await fetchMembers( { groups: selection, q: memberQuery, page } );
			if ( request !== membersRequest ) {
				return; // a newer selection or search superseded this one
			}
			countEl.textContent = selection.length > 1 ? STRINGS.shared( result.total ) : STRINGS.members( result.total );
			const html = result.items.map( ( person, i ) => memberRow( person, i ) ).join( '' );
			if ( page === 1 ) {
				listEl.innerHTML = html || `<li class="gnr-sheet__none">${ STRINGS.noMembers }</li>`;
			} else {
				listEl.insertAdjacentHTML( 'beforeend', html );
			}
			moreEl.hidden = page >= result.pages;
		} catch ( error ) {
			if ( request === membersRequest ) {
				listEl.innerHTML = `<li class="gnr-sheet__none">${ STRINGS.loadError }</li>`;
			}
		} finally {
			if ( request === membersRequest ) {
				listEl.classList.remove( 'is-loading' );
				mountEl.classList.remove( 'is-busy' );
			}
		}
	}

	let searchTimer;
	sheetEl.addEventListener( 'input', ( event ) => {
		if ( ! event.target.classList.contains( 'gnr-sheet__search' ) ) {
			return;
		}
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => {
			memberQuery = event.target.value.trim();
			loadMembers( { page: 1 } );
		}, 250 );
	} );

	sheetEl.addEventListener( 'click', ( event ) => {
		const remove = event.target.closest( '[data-remove]' );
		const withGroup = event.target.closest( '[data-with]' );
		const person = event.target.closest( '[data-person]' );
		const more = event.target.closest( '.gnr-sheet__more' );
		if ( person || event.target.closest( '.gnr-sheet__tip-close' ) ) {
			markTipSeen();
			sheetEl.querySelector( '.gnr-sheet__tip' )?.remove();
		}
		if ( remove ) {
			memberQuery = '';
			setSelection( selection.filter( ( slug ) => slug !== remove.dataset.remove ) );
		} else if ( withGroup ) {
			memberQuery = '';
			setSelection( [ selection[ 0 ], withGroup.dataset.with ] );
		} else if ( person && onOpenPerson ) {
			onOpenPerson( Number( person.dataset.person ), [ ...selection ] );
		} else if ( more ) {
			loadMembers( { page: memberPage + 1 } );
		}
	} );

	// ---- Bar ----

	homeEl.addEventListener( 'click', () => {
		memberQuery = '';
		setSelection( [] );
	} );

	filterEl.addEventListener( 'input', () => {
		filterText = searchKey( filterEl.value.trim() );
		refreshChrome();
		animateTo( currentTargets() );
	} );

	filterEl.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Enter' ) {
			return;
		}
		const matches = [ ...nodes.values() ].filter( isVisibleInAll );
		if ( matches.length === 1 ) {
			setSelection( [ matches[ 0 ].group.slug ] );
		}
	} );

	smallEl.querySelector( 'input' ).addEventListener( 'change', ( event ) => {
		showSmall = event.target.checked;
		refreshChrome();
		animateTo( currentTargets() );
	} );

	// Background click with an overlap open: back to the whole centre group.
	svg.on( 'click', ( event ) => {
		if ( event.target === svgEl && selection.length > 1 ) {
			setSelection( [ selection[ 0 ] ] );
		}
	} );

	// ---- Data ----

	function buildNodes( data ) {
		const nameCounts = new Map();
		data.groups.forEach( ( g ) => nameCounts.set( g.name, ( nameCounts.get( g.name ) || 0 ) + 1 ) );

		// Largest first in the DOM, so small circles sit on top and stay clickable.
		const groups = [ ...data.groups ].sort( ( a, b ) => b.count - a.count );
		groups.forEach( ( group, index ) => {
			const node = { group, r: radiusFor( group.count ), key: searchKey( group.name ) };

			// Members' portraits, faint and clipped to the circle, under it.
			const portraits = group.portraits || [];
			if ( portraits.length ) {
				const clipId = `${ uid }-photos-${ index }`;
				node.photoClip = svg.select( 'defs' ).append( 'clipPath' ).attr( 'id', clipId ).append( 'circle' );
				node.photos = photosLayer.append( 'g' ).attr( 'clip-path', `url(#${ clipId })` );
				node.photoImages = node.photos.selectAll( 'image' )
					.data( portraits )
					.join( 'image' )
					.attr( 'class', 'gnr-groups__photo' )
					.attr( 'href', ( url ) => url )
					.attr( 'preserveAspectRatio', 'xMidYMid slice' );
				node.photoIndex = Math.floor( Math.random() * portraits.length );
				node.photoImages.classed( 'is-shown', ( d, i ) => i === node.photoIndex );
			}
			const title = `${ group.name } · ${ STRINGS.members( group.count ) }`;
			// Same-name groups are told apart by their description, or failing that their size.
			const subtitle = nameCounts.get( group.name ) > 1 && group.description
				? `${ group.description } · ${ STRINGS.members( group.count ) }`
				: STRINGS.members( group.count );

			node.circle = circlesLayer.append( 'circle' )
				.attr( 'class', 'gnr-groups__circle' )
				.attr( 'role', 'button' )
				.attr( 'aria-label', title )
				.on( 'click', ( event ) => {
					event.stopPropagation();
					onCircleActivate( node );
				} )
				.on( 'keydown', ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						onCircleActivate( node );
					}
				} );
			node.circle.append( 'title' ).text( title );

			node.lens = lensesLayer.append( 'circle' )
				.attr( 'class', 'gnr-groups__lens' )
				.attr( 'clip-path', `url(#${ uid }-clip)` )
				.attr( 'role', 'button' )
				.style( 'opacity', 0 )
				.on( 'click', ( event ) => {
					event.stopPropagation();
					onLensActivate( node );
				} )
				.on( 'keydown', ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						onLensActivate( node );
					}
				} );
			node.lensTitle = node.lens.append( 'title' );

			node.label = document.createElement( 'div' );
			node.label.className = 'gnr-groups__label';
			node.label.innerHTML = `<span class="gnr-groups__label-name">${ escapeHtml( group.name ) }</span><span class="gnr-groups__label-count">${ escapeHtml( subtitle ) }</span>`;
			labelsEl.appendChild( node.label );

			node.lensLabel = document.createElement( 'div' );
			node.lensLabel.className = 'gnr-groups__lens-label';
			labelsEl.appendChild( node.lensLabel );

			nodes.set( group.slug, node );
		} );

		data.overlaps.forEach( ( { a, b, shared } ) => {
			[ [ a, b ], [ b, a ] ].forEach( ( [ from, to ] ) => {
				if ( ! overlapsBySlug.has( from ) ) {
					overlapsBySlug.set( from, [] );
				}
				overlapsBySlug.get( from ).push( { slug: to, shared } );
			} );
		} );
		overlapsBySlug.forEach( ( list ) => list.sort( ( x, y ) => y.shared - x.shared ) );

		// Overlap labels/titles depend on which group is in the centre.
		nodes.forEach( ( node ) => {
			node.updateLensText = ( centerSlug ) => {
				const pair = ( overlapsBySlug.get( centerSlug ) || [] ).find( ( n ) => n.slug === node.group.slug );
				const shared = pair ? pair.shared : 0;
				node.lensLabel.textContent = STRINGS.sharedShort( shared );
				node.lensTitle.text( `${ groupName( centerSlug ) } ∩ ${ node.group.name } · ${ STRINGS.shared( shared ) }` );
				node.lens.attr( 'aria-label', `${ groupName( centerSlug ) } ∩ ${ node.group.name } · ${ STRINGS.shared( shared ) }` );
			};
		} );

		startPhotoCycle();

		showSmall = nodes.size <= AUTO_HIDE_SMALL_OVER;
		smallEl.querySelector( 'input' ).checked = showSmall;
	}

	// One circle changes portrait at a time, round robin; none with reduced motion.
	function startPhotoCycle() {
		const cycling = [ ...nodes.values() ].filter( ( node ) => node.photoImages && node.photoImages.size() > 1 );
		if ( ! cycling.length || prefersReducedMotion() ) {
			return;
		}
		let turn = 0;
		setInterval( () => {
			if ( document.hidden ) {
				return;
			}
			const node = cycling[ turn++ % cycling.length ];
			node.photoIndex = ( node.photoIndex + 1 ) % node.photoImages.size();
			node.photoImages.classed( 'is-shown', ( d, i ) => i === node.photoIndex );
		}, PHOTO_TURN_MS );
	}

	// ---- Resize ----

	let lastSize = { w: 0, h: 0 };
	new ResizeObserver( () => {
		const w = mountEl.clientWidth;
		const h = mountEl.clientHeight;
		if ( ! nodes.size || ( Math.abs( w - lastSize.w ) < 20 && Math.abs( h - lastSize.h ) < 20 ) ) {
			return;
		}
		lastSize = { w, h };
		fitView( currentTargets() );
	} ).observe( mountEl );

	// ---- Boot ----

	mountEl.classList.add( 'is-busy' );
	fetchGroups()
		.then( ( data ) => {
			buildNodes( data );
			lastSize = { w: mountEl.clientWidth, h: mountEl.clientHeight };
			setSelection( initialSelection, { silent: true, animate: false } );
			if ( onReady ) {
				onReady();
			}
		} )
		.catch( () => {
			emptyEl.hidden = false;
			emptyEl.textContent = STRINGS.loadError;
		} )
		.finally( () => mountEl.classList.remove( 'is-busy' ) );

	return {
		setSelection,
		getGroupName: groupName,
	};
}
