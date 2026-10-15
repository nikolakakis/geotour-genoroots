// GenoRoots field editor ([genoroots_field_editor]): find a person, see their
// close family, add or correct relatives, from a phone.
//
// Screens are addressed by the URL hash (#/ search, #/p/123 a person), so
// the phone's Back button walks back through them; sheets add their own
// history entry (ui.js).
import './editor.scss';
import { config, esc, t, tn } from './env.js';
import { ApiError, NetworkError, get } from './api.js';
import { cachedPerson, forgetPerson, getCollection, getRecent, getUnsent, rememberPerson, setCollection } from './store.js';
import { cardHtml, dropSheet, errorHtml, nicknameText, sheetOpen } from './ui.js';
import {
	addRelative, canUpload, choosePortrait, connect, createPerson, editMarriage, editPerson,
	flushUnsent, removeLink, searchInto, showHistory, showUnsent,
} from './flows.js';

const root = document.getElementById( 'gnr-editor' );

let collection = initialCollection();
let current = null; // the person view on screen
let searchQuery = '';

function initialCollection() {
	const allowed = ( config.collections || [] ).map( ( c ) => c.id );
	const stored = getCollection();
	if ( config.collection && allowed.includes( config.collection ) ) {
		return config.collection;
	}
	if ( stored !== null && ( stored === 0 || allowed.includes( stored ) ) ) {
		return stored;
	}
	return 0;
}

// Shell ---------------------------------------------------------------------------

function shell() {
	const collections = config.collections || [];
	root.removeAttribute( 'data-loading' );
	root.innerHTML = `
		<header class="gnr-ed-bar">
			${ collections.length ? `
				<label class="gnr-ed-bar__collection">
					<span class="screen-reader-text">${ esc( t( 'collection' ) ) }</span>
					<select>
						${ collections.length > 1 || ! config.restricted ? `<option value="0">${ esc( t( 'allCollections' ) ) }</option>` : '' }
						${ collections.map( ( c ) => `<option value="${ c.id }"${ c.id === collection ? ' selected' : '' }>${ esc( c.name ) }</option>` ).join( '' ) }
					</select>
				</label>` : `<span class="gnr-ed-bar__title">${ esc( t( 'title' ) ) }</span>` }
			<button type="button" class="gnr-ed-bar__unsent" hidden></button>
			<details class="gnr-ed-menu">
				<summary class="gnr-ed-icon" aria-label="${ esc( t( 'more' ) ) }">⋯</summary>
				<div class="gnr-ed-menu__list">
					<button type="button" data-menu="history">${ esc( t( 'allHistory' ) ) }</button>
					${ config.treeUrl ? `<a href="${ esc( config.treeUrl ) }">${ esc( t( 'viewPage' ) ) }</a>` : '' }
					<a href="${ esc( config.logoutUrl ) }">${ esc( t( 'signOut' ) ) } (${ esc( config.user ? config.user.name : '' ) })</a>
				</div>
			</details>
		</header>
		<main class="gnr-ed-main" tabindex="-1"></main>`;

	const select = root.querySelector( '.gnr-ed-bar__collection select' );
	if ( select ) {
		collection = Number( select.value );
		select.addEventListener( 'change', () => {
			collection = Number( select.value );
			setCollection( collection );
			if ( ! current ) {
				route();
			}
		} );
	}
	root.querySelector( '.gnr-ed-bar__unsent' ).addEventListener( 'click', showUnsent );
	root.querySelector( '[data-menu="history"]' ).addEventListener( 'click', ( event ) => {
		event.target.closest( 'details' ).open = false;
		showHistory( 0 );
	} );
	refreshBadge();
}

function refreshBadge() {
	const button = root.querySelector( '.gnr-ed-bar__unsent' );
	const count = getUnsent().length;
	button.hidden = count === 0;
	button.textContent = count ? tn( 'unsent', count ) : '';
}

const main = () => root.querySelector( '.gnr-ed-main' );

// Routing ---------------------------------------------------------------------------

function go( hash ) {
	if ( sheetOpen() ) {
		// Leaving from inside a sheet: replace its history entry with the new screen.
		dropSheet();
		window.history.replaceState( null, '', hash );
		route();
	} else if ( window.location.hash === hash ) {
		route();
	} else {
		window.location.hash = hash;
	}
}

function route() {
	const match = /^#\/p\/(\d+)/.exec( window.location.hash );
	if ( match ) {
		showPerson( Number( match[ 1 ] ) );
	} else {
		showSearch();
	}
	window.scrollTo( { top: root.getBoundingClientRect().top + window.scrollY - 8 } );
}

window.addEventListener( 'hashchange', route );

// Search ------------------------------------------------------------------------------

function showSearch() {
	current = null;
	const recent = getRecent();
	main().innerHTML = `
		<div class="gnr-ed-search">
			<input type="search" class="gnr-ed-search__input" value="${ esc( searchQuery ) }" placeholder="${ esc( t( 'search' ) ) }" autocomplete="off" enterkeyhint="search" aria-label="${ esc( t( 'search' ) ) }">
		</div>
		<div class="gnr-ed-results" aria-live="polite"></div>
		<section class="gnr-ed-recent"${ recent.length ? '' : ' hidden' }>
			<h2 class="gnr-ed-h">${ esc( t( 'recent' ) ) }</h2>
			<div class="gnr-ed-results">${ recent.map( ( c ) => cardHtml( c, { tag: 'a', attrs: `href="#/p/${ c.id }"` } ) ).join( '' ) }</div>
		</section>
		<div class="gnr-ed-actions gnr-ed-actions--center">
			<button type="button" class="gnr-ed-button gnr-ed-button--soft" data-create>${ esc( t( 'addPerson' ) ) }</button>
		</div>`;
	const input = main().querySelector( '.gnr-ed-search__input' );
	const recentEl = main().querySelector( '.gnr-ed-recent' );
	searchInto( input, main().querySelector( '.gnr-ed-results' ), ( card ) => go( `#/p/${ card.id }` ), 0, ( q ) => {
		searchQuery = q;
		recentEl.hidden = Boolean( q ) || ! recent.length;
	} );
	main().querySelector( '[data-create]' ).addEventListener( 'click', () => createPerson( /^\d+$/.test( searchQuery ) ? '' : searchQuery ) );
	if ( window.matchMedia( '(hover: hover)' ).matches ) {
		input.focus();
	}
}

// Person ------------------------------------------------------------------------------

async function showPerson( id, preloaded ) {
	if ( preloaded ) {
		showView( preloaded );
		return;
	}
	const cached = cachedPerson( id );
	if ( cached ) {
		showView( cached, { stale: true } );
	} else {
		main().innerHTML = '<p class="gnr-ed-empty">…</p>';
	}
	try {
		showView( await get( `editor/person/${ id }` ) );
	} catch ( error ) {
		if ( error instanceof NetworkError && cached ) {
			showView( cached, { offline: true } );
		} else if ( error instanceof ApiError && error.status === 404 ) {
			forgetPerson( id );
			main().innerHTML = errorHtml( error.message ) + backLink();
		} else {
			main().innerHTML = errorHtml( error instanceof NetworkError ? t( 'offline' ) : ( error.message || t( 'error' ) ) ) + backLink();
		}
	}
}

const backLink = () => `<p><a class="gnr-ed-button gnr-ed-button--quiet" href="#/">${ esc( t( 'back' ) ) }</a></p>`;

/** Draws a person and their close family. */
function showView( view, { offline = false, stale = false } = {} ) {
	if ( ! view || ! view.person ) {
		return;
	}
	const hashId = /^#\/p\/(\d+)/.exec( window.location.hash );
	if ( ! hashId || Number( hashId[ 1 ] ) !== view.person.id ) {
		window.history.replaceState( window.history.state, '', `#/p/${ view.person.id }` );
	}
	current = view;
	if ( ! stale && ! offline ) {
		rememberPerson( view );
	}
	const p = view.person;
	const number = /^I(\d+)$/.exec( p.gedcom_id || '' );
	const linkTo = ( card ) => `href="#/p/${ card.id }"`;
	const remove = ( familyId, card, label ) => `<button type="button" class="gnr-ed-icon gnr-ed-icon--small" data-unlink="${ familyId }:${ card.id }" data-label="${ esc( label ) }" aria-label="${ esc( t( 'removeLink' ) ) }: ${ esc( card.name ) }">✕</button>`;
	const related = ( card, familyId, label ) => `<div class="gnr-ed-rel">${ cardHtml( card, { tag: 'a', attrs: linkTo( card ) } ) }${ remove( familyId, card, label ) }</div>`;

	const parents = view.parents;
	const parentCards = parents ? [ parents.father, parents.mother ].filter( Boolean ) : [];

	main().innerHTML = `
		${ offline ? `<p class="gnr-ed-note gnr-ed-note--warn">${ esc( t( 'offline' ) ) }</p>` : '' }
		<nav class="gnr-ed-nav"><a href="#/" class="gnr-ed-button gnr-ed-button--quiet gnr-ed-button--small">← ${ esc( t( 'backToSearch' ) ) }</a></nav>
		<header class="gnr-ed-person">
			<button type="button" class="gnr-ed-person__photo gnr-ed-card--${ esc( p.sex.toLowerCase() ) }" data-photo ${ canUpload() ? '' : 'disabled' } aria-label="${ esc( t( 'takePhoto' ) ) }">
				${ p.portrait_l || p.portrait ? `<img src="${ esc( p.portrait_l || p.portrait ) }" alt="">` : '<span aria-hidden="true">📷</span>' }
			</button>
			<div class="gnr-ed-person__text">
				<h1 class="gnr-ed-person__name">${ esc( p.name ) }</h1>
				${ p.nickname ? `<p class="gnr-ed-person__nick">${ esc( nicknameText( p ) ) }</p>` : '' }
				<p class="gnr-ed-person__meta">${ esc( [ p.father_name, p.years ].filter( Boolean ).join( ' · ' ) ) }</p>
				<p class="gnr-ed-person__meta gnr-ed-person__meta--soft">
					${ number ? `#${ esc( number[ 1 ] ) }` : '' }
					${ p.draft ? `<span class="gnr-ed-badge">${ esc( t( 'draft' ) ) }</span>` : '' }
				</p>
			</div>
		</header>
		<div class="gnr-ed-toolbar">
			<button type="button" class="gnr-ed-button" data-edit>${ esc( t( 'edit' ) ) }</button>
			<button type="button" class="gnr-ed-button gnr-ed-button--soft" data-history>${ esc( t( 'history' ) ) }</button>
			${ p.url ? `<a class="gnr-ed-button gnr-ed-button--quiet" href="${ esc( p.url ) }" target="_blank" rel="noopener">${ esc( t( 'viewPage' ) ) }</a>` : '' }
			${ config.adminUrl ? `<a class="gnr-ed-button gnr-ed-button--quiet" href="${ esc( config.adminUrl + p.id ) }">${ esc( t( 'openAdmin' ) ) }</a>` : '' }
		</div>
		<div class="gnr-ed-add">
			<button type="button" data-add="spouse">${ esc( t( 'addSpouse' ) ) }</button>
			<button type="button" data-add="child">${ esc( t( 'addChild' ) ) }</button>
			<button type="button" data-add="parent">${ esc( t( 'addParent' ) ) }</button>
			<button type="button" data-add="sibling">${ esc( t( 'addSibling' ) ) }</button>
		</div>

		<section class="gnr-ed-section">
			<h2 class="gnr-ed-h">${ esc( t( 'parents' ) ) }</h2>
			${ parentCards.length
				? parentCards.map( ( c ) => related( c, parents.family_id, t( 'parents' ) ) ).join( '' )
				: `<p class="gnr-ed-empty">${ esc( t( 'noParents' ) ) }</p>` }
		</section>

		${ view.siblings.length ? `
			<section class="gnr-ed-section">
				<h2 class="gnr-ed-h">${ esc( t( 'siblings' ) ) }</h2>
				${ view.siblings.map( ( c ) => related( c, parents.family_id, t( 'siblings' ) ) ).join( '' ) }
			</section>` : '' }

		<section class="gnr-ed-section">
			<h2 class="gnr-ed-h">${ esc( t( 'marriages' ) ) }</h2>
			${ view.marriages.length ? view.marriages.map( ( m, index ) => `
				<div class="gnr-ed-marriage">
					${ m.spouse ? related( m.spouse, m.family_id, m.spouse.name ) : `<p class="gnr-ed-empty">${ esc( t( 'unknownSpouse' ) ) }</p>` }
					<button type="button" class="gnr-ed-marriage__details" data-marriage="${ index }">
						${ esc( [ m.marriage_date_raw, m.marriage_place ].filter( Boolean ).join( ' · ' ) || t( 'editMarriage' ) ) } ›
					</button>
					${ m.children.length ? `
						<div class="gnr-ed-marriage__children">
							<h3 class="gnr-ed-h gnr-ed-h--small">${ esc( t( 'children' ) ) }</h3>
							${ m.children.map( ( c ) => related( c, m.family_id, t( 'children' ) ) ).join( '' ) }
						</div>` : '' }
				</div>` ).join( '' ) : `<p class="gnr-ed-empty">${ esc( t( 'noMarriages' ) ) }</p>` }
		</section>`;

	const el = main();
	el.querySelector( '[data-edit]' ).addEventListener( 'click', () => editPerson( current ) );
	el.querySelector( '[data-history]' ).addEventListener( 'click', () => showHistory( p.id ) );
	el.querySelector( '[data-photo]' ).addEventListener( 'click', () => choosePortrait( current ) );
	el.querySelectorAll( '[data-add]' ).forEach( ( b ) => b.addEventListener( 'click', () => addRelative( current, b.dataset.add ) ) );
	el.querySelectorAll( '[data-marriage]' ).forEach( ( b ) => b.addEventListener( 'click', () => editMarriage( current, current.marriages[ Number( b.dataset.marriage ) ] ) ) );
	el.querySelectorAll( '[data-unlink]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
		const [ familyId, otherId ] = b.dataset.unlink.split( ':' ).map( Number );
		const card = findCard( otherId );
		removeLink( current, familyId, card, b.dataset.label );
	} ) );
	if ( offline ) {
		el.querySelectorAll( 'button' ).forEach( ( b ) => {
			b.disabled = true;
		} );
	}
}

function findCard( id ) {
	const v = current;
	const all = [
		v.parents && v.parents.father, v.parents && v.parents.mother, ...v.siblings,
		...v.marriages.flatMap( ( m ) => [ m.spouse, ...m.children ] ),
	].filter( Boolean );
	return all.find( ( c ) => c.id === id ) || { id, name: '' };
}

// Start ----------------------------------------------------------------------------------

if ( root ) {
	connect( {
		showView,
		refreshBadge,
		currentCollection: () => collection,
		// Another person, possibly from inside a sheet (which then closes).
		open: ( id, view ) => {
			if ( view && sheetOpen() ) {
				dropSheet();
				window.history.replaceState( null, '', `#/p/${ id }` );
				showView( view );
			} else if ( view ) {
				window.location.hash = `#/p/${ id }`; // route() reloads; the view is drawn at once below
				showView( view );
			} else {
				go( `#/p/${ id }` );
			}
		},
		reload: () => {
			if ( current ) {
				showPerson( current.person.id );
			}
		},
	} );
	shell();
	route();
	window.addEventListener( 'online', flushUnsent );
	if ( navigator.onLine ) {
		flushUnsent();
	}
}
