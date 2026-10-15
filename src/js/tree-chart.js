import * as f3 from 'family-chart';
import { select, zoomIdentity, zoomTransform } from 'd3';
import { createFamilyStore } from './family-store.js';
import { t, tf } from './i18n.js';
import { escapeHtml, yearOf, splitName, lifespan, isMobileViewport } from './format.js';

// User-visible strings; translations come from the plugin's catalogue (i18n.js).
// Set only for signed-in users who may edit the tree (GenoRoots_Field_Editor::edit_url()).
const EDIT_URL = ( window.GenoRootsAPI && window.GenoRootsAPI.editUrl ) || '';

const STRINGS = {
	nickname: tf( 'nickname' ),
	profile: t( 'profile' ),
	edit: t( 'edit' ),
	fullTree: t( 'fullTree' ),
	readMore: t( 'readMore' ),
	photos: ( count ) => ( count ? tf( 'photosCount' )( count ) : t( 'photos' ) ),
	zoomIn: t( 'zoomIn' ),
	zoomOut: t( 'zoomOut' ),
	center: t( 'center' ),
	fit: t( 'fit' ),
	hint: t( 'treeHint' ),
	moreUp: t( 'moreUp' ),
	moreDown: t( 'moreDown' ),
	moreFamily: t( 'moreFamily' ),
	loadError: t( 'loadError' ),
	backToGroups: t( 'groups' ),
	removeLens: tf( 'removeLens' ),
	focusIn: tf( 'focusIn' ),
	groupsOf: t( 'groups' ),
};

const LAYOUTS = {
	desktop: { xSpacing: 226, ySpacing: 136, scale: 1 },
	mobile: { xSpacing: 168, ySpacing: 118, scale: 0.9 },
};
const TRANSITION_MS = 650;
const SCALE_EXTENT = [ 0.25, 2 ];
const MIN_READABLE_SCALE = 0.7;
const HINT_MS = 6000;

const ICONS = {
	plus: '<path d="M12 5v14M5 12h14"/>',
	minus: '<path d="M5 12h14"/>',
	center: '<circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>',
	fit: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
};

function icon( name ) {
	return `<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ ICONS[ name ] }</svg>`;
}

/**
 * Focus-centred, animated family tree for one mount element. Clicking a
 * card re-centres on that person at once (with what's already loaded),
 * then fetches their neighbourhood and grows the new branches in place.
 *
 * `lens` highlights the members of the given groups (everyone else fades,
 * nobody is removed, so the tree keeps its shape).
 *
 * Returns { focus(personId, { silent }), setLens(slugs), getMainId() }.
 */
export function createTreeChart( mountEl, {
	personId,
	groupSlug,
	fetchUp,
	fetchDown,
	politeZoom,
	fullTreeUrlFor,
	onFocusChange,
	lens: initialLens = [],
	groupName = ( slug ) => slug,
	onGroupsRequest = null, // ( slugs ) => void; set on the archive page, where the explorer lives
	onLensChange = null,
} = {} ) {
	const store = createFamilyStore( { up: fetchUp, down: fetchDown } );
	const layout = isMobileViewport() ? LAYOUTS.mobile : LAYOUTS.desktop;

	mountEl.classList.add( 'gnr-tree' );
	mountEl.innerHTML = `
		<div class="gnr-tree__canvas f3"></div>
		<div class="gnr-tree__zoom" role="toolbar">
			<button type="button" data-action="zoom-in" aria-label="${ STRINGS.zoomIn }" title="${ STRINGS.zoomIn }">${ icon( 'plus' ) }</button>
			<button type="button" data-action="zoom-out" aria-label="${ STRINGS.zoomOut }" title="${ STRINGS.zoomOut }">${ icon( 'minus' ) }</button>
			<button type="button" data-action="center" aria-label="${ STRINGS.center }" title="${ STRINGS.center }">${ icon( 'center' ) }</button>
			<button type="button" data-action="fit" aria-label="${ STRINGS.fit }" title="${ STRINGS.fit }">${ icon( 'fit' ) }</button>
		</div>
		<div class="gnr-tree__top">
			<div class="gnr-tree__lens" hidden></div>
			<aside class="gnr-tree__panel" aria-live="polite"></aside>
		</div>
		<p class="gnr-tree__hint">${ STRINGS.hint }</p>
		<p class="gnr-tree__toast" role="alert" hidden></p>
		<div class="gnr-tree__loading" aria-hidden="true"></div>
	`;
	const canvasEl = mountEl.querySelector( '.gnr-tree__canvas' );
	const panelEl = mountEl.querySelector( '.gnr-tree__panel' );
	const lensEl = mountEl.querySelector( '.gnr-tree__lens' );
	const hintEl = mountEl.querySelector( '.gnr-tree__hint' );
	const toastEl = mountEl.querySelector( '.gnr-tree__toast' );

	let chart = null;
	let shownIds = new Set();
	let mainId = null;
	let toastTimer;
	let lens = [ ...initialLens ];

	function showToast( message ) {
		toastEl.textContent = message;
		toastEl.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => {
			toastEl.hidden = true;
		}, 4000 );
	}

	function setBusy( busy ) {
		mountEl.classList.toggle( 'is-busy', busy );
	}

	// ---- Zoom helpers (the library keeps its d3-zoom on the canvas wrapper) ----

	function zoomHost() {
		const svg = chart.svg;
		return svg.__zoomObj ? svg : svg.parentNode;
	}

	function currentScale() {
		return zoomTransform( zoomHost() ).k;
	}

	/** Puts the main person in the middle of the viewport, keeping (or setting) the zoom. */
	function centerOnMain( { scale, duration = TRANSITION_MS } = {} ) {
		const host = zoomHost();
		const main = chart.store.getTreeMainDatum();
		if ( ! main ) {
			return;
		}
		const k = scale || currentScale();
		const { width, height } = host.getBoundingClientRect();
		const transform = zoomIdentity.translate( width / 2 - main.x * k, height / 2 - main.y * k ).scale( k );
		select( host ).transition().duration( duration ).call( host.__zoomObj.transform, transform );
	}

	/**
	 * First view: the whole loaded family when it fits at a readable size,
	 * otherwise the focus person at reading zoom.
	 */
	function frameInitial() {
		const host = zoomHost();
		const tree = chart.store.getTree();
		const { width, height } = host.getBoundingClientRect();
		const k = Math.min( layout.scale, width / tree.dim.width, height / tree.dim.height );
		if ( k < MIN_READABLE_SCALE ) {
			centerOnMain( { scale: layout.scale, duration: 0 } );
			return;
		}
		const tx = ( width - tree.dim.width * k ) / 2 + tree.dim.x_off * k;
		const ty = ( height - tree.dim.height * k ) / 2 + tree.dim.y_off * k;
		select( host ).call( host.__zoomObj.transform, zoomIdentity.translate( tx, ty ).scale( k ) );
	}

	function zoomBy( factor ) {
		const host = zoomHost();
		select( host ).transition().duration( 250 ).call( host.__zoomObj.scaleBy, factor );
	}

	function fitAll() {
		chart.updateTree( { tree_position: 'fit', transition_time: TRANSITION_MS } );
	}

	// ---- Cards ----

	function inLens( person ) {
		return lens.every( ( slug ) => ( person.groups || [] ).includes( slug ) );
	}

	function cardInnerHtml( d ) {
		const person = d.data.data.person;
		const { surname, given } = splitName( person.name );
		const years = lifespan( person );
		const hidden = store.hiddenRelatives( person.id, shownIds );
		const chip = ( count, direction, label ) => ( count === 0 ? '' : `
			<span class="gnr-card__more gnr-card__more--${ direction }" title="${ label }">${ count > 0 ? `+${ count }` : '+' }</span>
		` );
		const sex = ( d.data.data.gender || 'U' ).toLowerCase();
		const lensClass = ! lens.length ? '' : inLens( person ) ? ' is-lit' : ' is-dimmed';
		// "More family here" marker (the family-chart / WikiTree mini tree), shown
		// on the same rule as the chips: relatives exist that aren't on screen.
		const miniTree = hidden.up || hidden.down ? `
			<span class="gnr-card__mini" title="${ STRINGS.moreFamily }" aria-hidden="true">
				<svg viewBox="0 0 30 15" width="30" height="15">
					<line class="gnr-card__mini-line" x1="12" y1="4.5" x2="18" y2="4.5"/>
					<line class="gnr-card__mini-line" x1="15" y1="4.5" x2="15" y2="15"/>
					<rect class="gnr-card__mini-m" x="0" y="1" width="12" height="7" rx="3"/>
					<rect class="gnr-card__mini-f" x="18" y="1" width="12" height="7" rx="3"/>
				</svg>
			</span>
		` : '';

		return `
			<div class="card-inner gnr-card gnr-card--${ sex }${ lensClass }${ person.portrait ? ' has-photo' : '' }">
				${ miniTree }
				${ chip( hidden.up, 'up', STRINGS.moreUp ) }
				${ person.portrait ? `<img class="gnr-card__photo" src="${ escapeHtml( person.portrait ) }" alt="" loading="lazy" draggable="false">` : '' }
				<span class="gnr-card__text">
					<span class="gnr-card__given">${ escapeHtml( given ) }</span>
					${ surname ? `<span class="gnr-card__surname">${ escapeHtml( surname ) }</span>` : '' }
					${ years ? `<span class="gnr-card__years">${ escapeHtml( years ) }</span>` : '' }
				</span>
				${ chip( hidden.down, 'down', STRINGS.moreDown ) }
			</div>
		`;
	}

	/** Makes each rendered card keyboard-reachable and screen-reader labelled. */
	function onCardUpdate( d ) {
		const cardEl = this.querySelector( '.card' );
		if ( ! cardEl ) {
			return;
		}
		const person = d.data.data.person;
		cardEl.setAttribute( 'tabindex', '0' );
		cardEl.setAttribute( 'role', 'button' );
		cardEl.setAttribute( 'aria-label', [ person.name, lifespan( person ) ].filter( Boolean ).join( ', ' ) );
		cardEl.onkeydown = ( event ) => {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				focus( person.id );
			}
		};
	}

	// ---- Lens bar + focus panel ----

	function renderLensBar() {
		if ( ! onGroupsRequest ) {
			lensEl.hidden = true;
			return;
		}
		mountEl.classList.toggle( 'has-lens', lens.length > 0 );
		lensEl.hidden = false;
		// Chips of the groups the focused person belongs to get the focus ring,
		// so it's clear which part of the highlight the clicked person is in.
		const main = mainId ? store.getPerson( mainId ) : null;
		const mainGroups = ( main && main.groups ) || [];
		lensEl.innerHTML = `
			<button type="button" class="gnr-tree__back" data-back>
				<span aria-hidden="true">←</span> ${ STRINGS.backToGroups }
			</button>
			${ lens.map( ( slug ) => {
				const isMatch = mainGroups.includes( slug );
				const label = STRINGS.removeLens( groupName( slug ) ) + ( isMatch && main ? ` · ${ STRINGS.focusIn( main.name ) }` : '' );
				return `
					<button type="button" class="gnr-chip gnr-chip--lit${ isMatch ? ' is-match' : '' }" data-unlens="${ escapeHtml( slug ) }" aria-label="${ escapeHtml( label ) }" title="${ escapeHtml( label ) }">
						${ escapeHtml( groupName( slug ) ) } <span aria-hidden="true">✕</span>
					</button>
				`;
			} ).join( '' ) }
		`;
	}

	function renderPanel( person ) {
		if ( ! person ) {
			panelEl.innerHTML = '';
			return;
		}
		const years = lifespan( person );
		const places = [ person.birth_place, person.death_place ].filter( Boolean );
		const groups = onGroupsRequest ? ( person.groups || [] ) : [];
		panelEl.innerHTML = `
			<div class="gnr-tree__panel-head">
				${ person.portrait ? `<img class="gnr-tree__panel-photo" src="${ escapeHtml( person.portrait ) }" alt="">` : '' }
				<div class="gnr-tree__panel-name">${ escapeHtml( person.name ) }</div>
			</div>
			${ person.nickname || person.father_name ? `<div class="gnr-tree__panel-meta">${ escapeHtml( [ person.nickname ? STRINGS.nickname( person.nickname ) : '', person.father_name ].filter( Boolean ).join( ' · ' ) ) }</div>` : '' }
			${ years ? `<div class="gnr-tree__panel-meta">${ escapeHtml( years ) }</div>` : '' }
			${ places.length ? `<div class="gnr-tree__panel-meta">${ escapeHtml( [ ...new Set( places ) ].join( ' · ' ) ) }</div>` : '' }
			${ groups.length ? `
				<div class="gnr-tree__panel-groups" aria-label="${ STRINGS.groupsOf }">
					${ groups.map( ( slug ) => `<button type="button" class="gnr-chip gnr-chip--soft" data-group="${ escapeHtml( slug ) }">${ escapeHtml( groupName( slug ) ) }</button>` ).join( '' ) }
				</div>
			` : '' }
			<div class="gnr-tree__panel-links">
				<a class="gnr-tree__panel-link" href="${ escapeHtml( person.url ) }">${ STRINGS.profile }</a>
				${ person.photos_url ? `<a class="gnr-tree__panel-link" href="${ escapeHtml( person.photos_url ) }" target="_blank" rel="noopener">${ STRINGS.photos( person.photo_count ) }</a>` : '' }
				${ person.related_url ? `<a class="gnr-tree__panel-link" href="${ escapeHtml( person.related_url ) }" title="${ escapeHtml( person.related_title ) }">${ STRINGS.readMore }</a>` : '' }
				${ fullTreeUrlFor ? `<a class="gnr-tree__panel-link" href="${ escapeHtml( fullTreeUrlFor( person.id ) ) }">${ STRINGS.fullTree }</a>` : '' }
				${ EDIT_URL ? `<a class="gnr-tree__panel-link gnr-tree__panel-link--edit" href="${ escapeHtml( EDIT_URL + person.id ) }">✎ ${ STRINGS.edit }</a>` : '' }
			</div>
		`;
		renderLensBar(); // chip rings follow the focused person
	}

	mountEl.querySelector( '.gnr-tree__top' ).addEventListener( 'click', ( event ) => {
		const back = event.target.closest( '[data-back]' );
		const unlens = event.target.closest( '[data-unlens]' );
		const group = event.target.closest( '[data-group]' );
		if ( back && onGroupsRequest ) {
			onGroupsRequest( [ ...lens ] );
		} else if ( unlens ) {
			setLens( lens.filter( ( slug ) => slug !== unlens.dataset.unlens ) );
			if ( onLensChange ) {
				onLensChange( [ ...lens ] );
			}
		} else if ( group && onGroupsRequest ) {
			onGroupsRequest( [ group.dataset.group ] );
		}
	} );

	/** Re-highlights the chart for a new set of groups (no reload, no movement). */
	function setLens( slugs ) {
		lens = [ ...( slugs || [] ) ];
		renderLensBar();
		if ( chart ) {
			chart.updateTree( { initial: false, tree_position: 'inherit', transition_time: 0 } );
		}
	}

	// ---- Navigation ----

	function redraw( { recenter, scale } ) {
		chart.updateData( store.toChartData() );
		chart.updateMainId( String( mainId ) );
		chart.updateTree( { initial: false, tree_position: 'inherit', transition_time: TRANSITION_MS } );
		if ( recenter ) {
			centerOnMain( { scale } );
		}
	}

	/**
	 * Zoom for a newly focused person: at least reading size (the cards'
	 * text legible), or the visitor's own zoom if they're further in.
	 */
	function focusScale() {
		return Math.max( currentScale(), layout.scale );
	}

	/**
	 * Re-centres on a person. Moves immediately if they're already on the
	 * chart; their own neighbourhood then loads in the background and the
	 * new relatives grow into place.
	 */
	async function focus( newPersonId, { silent = false } = {} ) {
		const id = Number( newPersonId );
		if ( ! id || id === mainId ) {
			return;
		}
		hintEl.classList.add( 'is-dismissed' );

		// Decided once per click: the follow-up redraw after loading must not
		// read the zoom halfway through the first animation.
		const scale = chart ? focusScale() : layout.scale;

		const alreadyShown = !! store.getPerson( id );
		if ( alreadyShown ) {
			mainId = id;
			redraw( { recenter: true, scale } );
			renderPanel( store.getPerson( id ) );
			if ( ! silent && onFocusChange ) {
				onFocusChange( id );
			}
		}

		if ( store.isLoaded( id ) ) {
			return;
		}

		setBusy( true );
		try {
			await store.load( id );
		} catch ( error ) {
			showToast( STRINGS.loadError );
			return;
		} finally {
			setBusy( false );
		}

		if ( ! alreadyShown ) {
			// Jumped from search to someone not yet on the chart.
			mainId = id;
			redraw( { recenter: true, scale } );
			renderPanel( store.getPerson( id ) );
			if ( ! silent && onFocusChange ) {
				onFocusChange( id );
			}
		} else if ( mainId === id ) {
			// New spouses/children shift the focus card; follow it.
			redraw( { recenter: true, scale } );
		}
	}

	function prefetch( id ) {
		if ( ! store.isLoaded( id ) ) {
			store.load( id ).catch( () => {} );
		}
	}

	// ---- Setup ----

	function buildChart() {
		chart = f3.createChart( canvasEl, store.toChartData() )
			.setTransitionTime( TRANSITION_MS )
			.setCardXSpacing( layout.xSpacing )
			.setCardYSpacing( layout.ySpacing )
			.setShowSiblingsOfMain( true )
			.setSingleParentEmptyCard( false )
			.setSortChildrenFunction( ( a, b ) => {
				const ya = yearOf( a.data.person.birth_date );
				const yb = yearOf( b.data.person.birth_date );
				return ya && yb ? ya - yb : 0; // keep GEDCOM order when a year is missing
			} )
			.setLinkSpouseText( ( sp1, sp2 ) => {
				const year = store.marriageYear( Number( sp1.data.id ), Number( sp2.data.id ) );
				return year ? `⚭ ${ year }` : '';
			} )
			.setBeforeUpdate( () => {
				const tree = chart.store.getTree();
				shownIds = new Set( ( tree ? tree.data : [] ).map( ( d ) => Number( d.data.id ) ) );
			} );

		const card = chart.setCardHtml()
			.setMiniTree( false )
			.setCardInnerHtmlCreator( cardInnerHtml )
			.setOnCardUpdate( onCardUpdate )
			.setOnCardClick( ( event, d ) => focus( d.data.id ) );

		// Hover: preload their relatives (so the click feels instant) and
		// highlight the line that connects them to the focus person.
		card.onCardMouseenter = ( event, d ) => {
			prefetch( Number( d.data.id ) );
			card.onEnterPathToMain( event, d );
		};
		card.onCardMouseleave = ( event, d ) => card.onLeavePathToMain( event, d );

		chart.updateMainId( String( mainId ) );
		chart.updateTree( { initial: true } );

		const host = zoomHost();
		host.__zoomObj.scaleExtent( SCALE_EXTENT );
		if ( politeZoom ) {
			// Embedded in a normal page: let one finger / the wheel scroll the page.
			host.__zoomObj.filter( ( event ) => {
				if ( event.type === 'wheel' ) {
					return event.ctrlKey || event.metaKey;
				}
				if ( event.touches ) {
					return event.touches.length > 1;
				}
				return ! event.button;
			} );
		}
		frameInitial();

		// Rotation / window resize / being un-hidden: keep the focus person in view.
		let lastSize = canvasEl.getBoundingClientRect();
		new ResizeObserver( () => {
			const size = canvasEl.getBoundingClientRect();
			if ( ! size.width || ! size.height ) {
				return; // hidden (e.g. the explorer is showing)
			}
			if ( Math.abs( size.width - lastSize.width ) > 40 || Math.abs( size.height - lastSize.height ) > 40 ) {
				lastSize = size;
				centerOnMain( { duration: 0 } );
			}
		} ).observe( canvasEl );
	}

	mountEl.querySelector( '.gnr-tree__zoom' ).addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button[data-action]' );
		if ( ! button || ! chart ) {
			return;
		}
		const action = button.dataset.action;
		if ( action === 'zoom-in' ) {
			zoomBy( 1.3 );
		} else if ( action === 'zoom-out' ) {
			zoomBy( 1 / 1.3 );
		} else if ( action === 'center' ) {
			centerOnMain( { scale: layout.scale } );
		} else if ( action === 'fit' ) {
			fitAll();
		}
	} );

	setTimeout( () => hintEl.classList.add( 'is-dismissed' ), HINT_MS );
	renderLensBar();

	setBusy( true );
	store.load( personId || null, groupSlug || null )
		.then( ( focusId ) => {
			mainId = focusId;
			buildChart();
			renderPanel( store.getPerson( focusId ) );
			if ( onFocusChange ) {
				onFocusChange( focusId, { initial: true } );
			}
		} )
		.catch( () => showToast( STRINGS.loadError ) )
		.finally( () => setBusy( false ) );

	return {
		focus,
		setLens,
		getMainId: () => mainId,
	};
}
