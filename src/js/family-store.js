import { fetchFocus } from './data.js';

/**
 * Client-side accumulator for everything fetched so far. Each /focus call
 * returns one bounded neighbourhood; merging them lets the tree grow as
 * the visitor explores instead of being thrown away on every click.
 *
 * Families are the single source of truth for relationships (same as on
 * the server): parent/child/spouse links are derived from them, so the
 * chart's per-person `rels` are symmetric by construction.
 */
export function createFamilyStore( { up = 2, down = 2 } = {} ) {
	const people = new Map(); // id -> person record from /focus
	const families = new Map(); // id -> family record from /focus
	const loads = new Map(); // id -> Promise, one neighbourhood fetch per person

	function merge( focusData ) {
		focusData.people.forEach( ( person ) => {
			if ( ! people.has( person.id ) ) {
				people.set( person.id, person );
			}
		} );
		focusData.families.forEach( ( family ) => families.set( family.id, family ) );
	}

	/** Fetches (once) the neighbourhood around a person. Safe to call on hover. */
	function load( personId, groupSlug = null ) {
		const key = personId || `group:${ groupSlug }`;
		if ( ! loads.has( key ) ) {
			const request = fetchFocus( { personId, groupSlug, up, down } )
				.then( ( focusData ) => {
					merge( focusData );
					loads.set( focusData.focus_id, request );
					return focusData.focus_id;
				} )
				.catch( ( error ) => {
					loads.delete( key ); // let a later click retry
					throw error;
				} );
			loads.set( key, request );
		}
		return loads.get( key );
	}

	/** True once this person's own neighbourhood has been fetched. */
	function isLoaded( personId ) {
		return loads.has( personId );
	}

	function parentIdsOf( person ) {
		const family = person.famc ? families.get( person.famc ) : null;
		return family ? [ family.spouse1, family.spouse2 ].filter( Boolean ) : [];
	}

	/**
	 * Relatives we know exist but that aren't in the chart right now, split
	 * into "above" (parents) and "below" (spouses and children). A count of
	 * -1 means "some, not fetched yet": the family itself is still unknown.
	 */
	function hiddenRelatives( personId, shownIds ) {
		const person = people.get( personId );
		if ( ! person ) {
			return { up: 0, down: 0 };
		}

		let up = 0;
		if ( person.famc ) {
			if ( ! families.has( person.famc ) ) {
				up = -1;
			} else {
				up = parentIdsOf( person ).filter( ( id ) => ! shownIds.has( id ) ).length;
			}
		}

		let down = 0;
		for ( const familyId of person.fams || [] ) {
			const family = families.get( familyId );
			if ( ! family ) {
				down = -1;
				break;
			}
			const spouseId = family.spouse1 === personId ? family.spouse2 : family.spouse1;
			const relatives = [ spouseId, ...( family.children || [] ) ].filter( Boolean );
			down += relatives.filter( ( id ) => ! shownIds.has( id ) ).length;
		}

		return { up, down };
	}

	/** Everything loaded so far, in family-chart's per-person format. */
	function toChartData() {
		const rels = new Map();
		people.forEach( ( person, id ) => {
			rels.set( id, { parents: [], spouses: [], children: [] } );
		} );
		const has = ( id ) => id && people.has( id );

		families.forEach( ( family ) => {
			const spouses = [ family.spouse1, family.spouse2 ].filter( has );
			const children = ( family.children || [] ).filter( has );

			if ( spouses.length === 2 ) {
				rels.get( spouses[ 0 ] ).spouses.push( String( spouses[ 1 ] ) );
				rels.get( spouses[ 1 ] ).spouses.push( String( spouses[ 0 ] ) );
			}
			children.forEach( ( childId ) => {
				const childRels = rels.get( childId );
				if ( childRels.parents.length ) {
					return; // one birth family per person; a second FAMC would break the layout.
				}
				spouses.forEach( ( parentId ) => {
					childRels.parents.push( String( parentId ) );
					rels.get( parentId ).children.push( String( childId ) );
				} );
			} );
		} );

		return [ ...people.values() ].map( ( person ) => ( {
			id: String( person.id ),
			data: {
				gender: person.sex === 'M' || person.sex === 'F' ? person.sex : 'U',
				person,
			},
			rels: rels.get( person.id ),
		} ) );
	}

	/** Marriage year of the union between two spouses, if the date is known. */
	function marriageYear( spouseA, spouseB ) {
		for ( const family of families.values() ) {
			const pair = [ family.spouse1, family.spouse2 ];
			if ( pair.includes( spouseA ) && pair.includes( spouseB ) ) {
				const match = /(\d{4})/.exec( family.marriage_date || '' );
				return match ? Number( match[ 1 ] ) : null;
			}
		}
		return null;
	}

	return {
		load,
		marriageYear,
		isLoaded,
		hiddenRelatives,
		toChartData,
		getPerson: ( id ) => people.get( Number( id ) ),
	};
}
