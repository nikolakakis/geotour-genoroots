// The page's collection (set once at boot from the app's data-collection);
// '' means every person on the site.
let collection = '';

export function setCollection( slug ) {
	collection = slug || '';
	groupsRequest = null;
}

function withCollection( params ) {
	if ( collection ) {
		params.set( 'collection', collection );
	}
	return params;
}

function apiRoot() {
	const api = window.GenoRootsAPI || {};
	return api.root || '/wp-json/';
}

/**
 * Fetches one bounded neighborhood around a person (or a group's default
 * person) — never the whole population. Each call is independent (no
 * memoization) since a different focus/group/depth is a different query.
 */
export function fetchFocus( { personId, groupSlug, up = 2, down = 2 } = {} ) {
	const params = new URLSearchParams();
	if ( personId ) {
		params.set( 'person', personId );
	}
	if ( groupSlug ) {
		params.set( 'group', groupSlug );
	}
	params.set( 'up', up );
	params.set( 'down', down );

	return fetch( `${ apiRoot() }geotour-genoroots/v1/focus?${ params.toString() }` ).then( ( res ) => {
		if ( ! res.ok ) {
			throw new Error( `GenoRoots focus fetch failed: ${ res.status }` );
		}
		return res.json();
	} );
}

function getJson( path, params, label ) {
	return fetch( `${ apiRoot() }geotour-genoroots/v1/${ path }?${ params.toString() }` ).then( ( res ) => {
		if ( ! res.ok ) {
			throw new Error( `GenoRoots ${ label } fetch failed: ${ res.status }` );
		}
		return res.json();
	} );
}

let groupsRequest = null;

/** Every non-empty group with its member count, plus shared-member counts per pair (fetched once per page). */
export function fetchGroups() {
	if ( ! groupsRequest ) {
		groupsRequest = getJson( 'groups', withCollection( new URLSearchParams() ), 'groups' ).catch( ( error ) => {
			groupsRequest = null;
			throw error;
		} );
	}
	return groupsRequest;
}

/** People in ALL of the given groups (one slug = that group, two = their overlap). */
export function fetchMembers( { groups, q = '', page = 1 } ) {
	const params = new URLSearchParams( { groups: groups.join( ',' ), page } );
	if ( q ) {
		params.set( 'q', q );
	}
	return getJson( 'members', withCollection( params ), 'members' );
}

/** Server-side name search — scales past a few dozen people, unlike filtering a full fetch client-side. */
export function fetchSearch( query ) {
	const params = withCollection( new URLSearchParams( { q: query } ) );
	return fetch( `${ apiRoot() }geotour-genoroots/v1/search?${ params.toString() }` ).then( ( res ) => {
		if ( ! res.ok ) {
			throw new Error( `GenoRoots search fetch failed: ${ res.status }` );
		}
		return res.json();
	} );
}
