import 'family-chart/styles/family-chart.css';
import './styles/main.scss';
import { createTreeChart } from './js/tree-chart.js';
import { createArchiveApp } from './js/archive-app.js';
import { initSearch } from './js/search.js';
import { setCollection } from './js/data.js';

function currentUrlParam( name ) {
	return new URL( window.location.href ).searchParams.get( name ) || '';
}

function archiveUrlFor( personId ) {
	const base = ( window.GenoRootsAPI && window.GenoRootsAPI.archiveUrl ) || '/';
	const separator = base.includes( '?' ) ? '&' : '?';
	return `${ base }${ separator }person=${ personId }`;
}

function setUrlPerson( personId, { replace = false } = {} ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'person', personId );
	url.searchParams.delete( 'group' ); // the group only chose where to start
	window.history[ replace ? 'replaceState' : 'pushState' ]( { personId }, '', url );
}

/**
 * Wires one .gnr-focus-chart mount. Only the mount with data-drives-url="1"
 * (the canonical archive page instance) owns the browser URL and the full
 * pan/zoom gestures; shortcode instances sit inside normal pages, so they
 * leave one-finger and wheel scrolling to the page.
 */
function initChartMount( mountEl ) {
	const drivesUrl = mountEl.dataset.drivesUrl === '1';

	const tree = createTreeChart( mountEl, {
		personId: parseInt( mountEl.dataset.personId, 10 ) || null,
		groupSlug: mountEl.dataset.groupSlug || null,
		fetchUp: parseInt( mountEl.dataset.up, 10 ) || 2,
		fetchDown: parseInt( mountEl.dataset.down, 10 ) || 2,
		politeZoom: ! drivesUrl,
		fullTreeUrlFor: drivesUrl ? null : archiveUrlFor,
		onFocusChange: ( personId, { initial = false } = {} ) => {
			if ( drivesUrl ) {
				setUrlPerson( personId, { replace: initial } );
			}
		},
	} );

	// Exposed so the search box (which lives outside the mount) can drive it.
	mountEl.genorootsFocus = tree.focus;

	if ( drivesUrl ) {
		window.addEventListener( 'popstate', () => {
			const personId = currentUrlParam( 'person' );
			if ( personId ) {
				tree.focus( personId, { silent: true } );
			}
		} );
	}
}

function initLandingSearch( app ) {
	const inputEl = document.getElementById( 'genoroots-search-input' );
	const resultsEl = document.getElementById( 'genoroots-search-results' );
	if ( ! inputEl || ! resultsEl ) {
		return;
	}

	const drivingChart = document.querySelector( '.gnr-focus-chart[data-drives-url="1"]' );

	initSearch( {
		inputEl,
		resultsEl,
		onSelect: ( personId ) => {
			if ( app ) {
				app.openPerson( personId );
			} else if ( drivingChart && drivingChart.genorootsFocus ) {
				drivingChart.genorootsFocus( personId );
			} else {
				window.location.href = archiveUrlFor( personId );
			}
		},
	} );
}

/** Window width without the scrollbar, for full-width (edge-to-edge) trees. */
function trackPageWidth() {
	const set = () => document.documentElement.style.setProperty( '--gnr-page-width', `${ document.documentElement.clientWidth }px` );
	set();
	window.addEventListener( 'resize', set );
}

function boot() {
	trackPageWidth();
	// The archive page runs as one app (groups explorer + tree); other pages
	// only have standalone tree mounts (person page, shortcode).
	const appEl = document.querySelector( '.gnr-app' );
	// A collection page scopes the explorer and the name search to it.
	setCollection( appEl ? appEl.dataset.collection : '' );
	const app = appEl ? createArchiveApp( appEl ) : null;
	document.querySelectorAll( '.gnr-focus-chart:not([data-app-managed])' ).forEach( initChartMount );
	initLandingSearch( app );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
