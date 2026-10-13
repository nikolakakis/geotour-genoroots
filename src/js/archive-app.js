import { fetchGroups } from './data.js';
import { createGroupsExplorer } from './groups-explorer.js';
import { createTreeChart } from './tree-chart.js';

const SWAP_MS = 220;

const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/** URL state: ?groups=a,b (explorer selection / tree highlight) and ?person=ID (tree). */
function readUrl() {
	const url = new URL( window.location.href );
	return {
		person: parseInt( url.searchParams.get( 'person' ), 10 ) || null,
		groups: ( url.searchParams.get( 'groups' ) || url.searchParams.get( 'group' ) || '' )
			.split( ',' )
			.map( ( s ) => s.trim() )
			.filter( Boolean ),
	};
}

function writeUrl( { person, groups }, { replace = false } = {} ) {
	const url = new URL( window.location.href );
	if ( person ) {
		url.searchParams.set( 'person', person );
	} else {
		url.searchParams.delete( 'person' );
	}
	if ( groups.length ) {
		url.searchParams.set( 'groups', groups.join( ',' ) );
	} else {
		url.searchParams.delete( 'groups' );
	}
	url.searchParams.delete( 'group' ); // legacy "start from this group" parameter
	if ( url.href !== window.location.href ) {
		window.history[ replace ? 'replaceState' : 'pushState' ]( null, '', url );
	}
}

/**
 * The family-tree archive page as one app with two views — the groups
 * explorer and the tree — that hand off to each other without reloads.
 * Each view is created the first time it's shown (both need real size).
 *
 * Returns { openPerson(id) } for the header's name search.
 */
export function createArchiveApp( appEl ) {
	const sections = {
		groups: appEl.querySelector( '[data-view="groups"]' ),
		tree: appEl.querySelector( '[data-view="tree"]' ),
	};
	const explorerMount = sections.groups.querySelector( '.gnr-explorer' );
	const treeMount = sections.tree.querySelector( '.gnr-focus-chart' );

	let explorer = null;
	let tree = null;
	let view = sections.tree.hidden ? 'groups' : 'tree';
	let state = readUrl();
	let names = new Map();
	const groupName = ( slug ) => names.get( slug ) || slug;

	async function showView( name ) {
		if ( view === name && ! sections[ name ].hidden ) {
			return;
		}
		const from = sections[ view ];
		view = name;
		const to = sections[ name ];
		if ( from && from !== to && ! from.hidden ) {
			from.classList.add( 'is-leaving' );
			await wait( SWAP_MS );
			from.hidden = true;
			from.classList.remove( 'is-leaving' );
		}
		to.classList.add( 'is-entering' );
		to.hidden = false;
		void to.offsetWidth; // commit the start state so the fade-in runs
		to.classList.remove( 'is-entering' );
	}

	function ensureExplorer() {
		if ( explorer ) {
			return;
		}
		explorer = createGroupsExplorer( explorerMount, {
			selection: state.groups,
			onSelectionChange: ( groups ) => {
				state = { person: null, groups };
				writeUrl( state );
			},
			onOpenPerson: ( personId, groups ) => openPerson( personId, groups ),
		} );
	}

	function ensureTree() {
		if ( tree ) {
			return false;
		}
		tree = createTreeChart( treeMount, {
			personId: state.person,
			fetchUp: 2,
			fetchDown: 2,
			politeZoom: false,
			lens: state.groups,
			groupName,
			onGroupsRequest: ( groups ) => openGroups( groups ),
			onLensChange: ( groups ) => {
				state = { ...state, groups };
				writeUrl( state, { replace: true } );
			},
			onFocusChange: ( personId, { initial = false } = {} ) => {
				state = { ...state, person: personId };
				writeUrl( state, { replace: initial } );
			},
		} );
		return true;
	}

	async function openPerson( personId, groups = state.groups, { push = true } = {} ) {
		state = { person: personId, groups };
		if ( push ) {
			writeUrl( state );
		}
		await showView( 'tree' );
		if ( ! ensureTree() ) {
			tree.setLens( groups );
			tree.focus( personId, { silent: true } );
		}
	}

	async function openGroups( groups, { push = true } = {} ) {
		state = { person: null, groups };
		if ( push ) {
			writeUrl( state );
		}
		await showView( 'groups' );
		if ( explorer ) {
			explorer.setSelection( groups, { silent: true } );
		} else {
			ensureExplorer();
		}
	}

	window.addEventListener( 'popstate', () => {
		const next = readUrl();
		if ( next.person ) {
			openPerson( next.person, next.groups, { push: false } );
		} else {
			openGroups( next.groups, { push: false } );
		}
	} );

	// Names first (one small cached request), so chips never flash raw slugs.
	fetchGroups()
		.then( ( data ) => {
			names = new Map( data.groups.map( ( g ) => [ g.slug, g.name ] ) );
		} )
		.catch( () => {} )
		.finally( () => {
			if ( state.person ) {
				openPerson( state.person, state.groups, { push: false } );
			} else {
				openGroups( state.groups, { push: false } );
			}
		} );

	return {
		openPerson: ( personId ) => openPerson( personId, state.groups ),
	};
}
