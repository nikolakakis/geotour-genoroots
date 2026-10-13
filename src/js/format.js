// Formatting helpers shared by the tree and the groups explorer.

import { tf } from './i18n.js';

// "b. 1954" / «γ. 1954» from the catalogue; † needs no translation.
export const LIFESPAN_STRINGS = {
	born: tf( 'bornYear' ),
	died: '†',
};

export function escapeHtml( value ) {
	return String( value ?? '' ).replace( /[&<>"']/g, ( ch ) => (
		{ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ]
	) );
}

export function yearOf( raw ) {
	const match = /(\d{4})/.exec( raw || '' );
	return match ? Number( match[ 1 ] ) : null;
}

// Bracketed or quoted words: nicknames ("Gerokostis", (Katingo)) and married
// names ((Giannoudaki)), never the surname itself.
const isExtra = ( word ) => /^[(«"“'‘]/.test( word ) || /[)»"”'’]$/.test( word );

/**
 * { given, surname } for the cards. The site says which order its names are
 * stored in (GenoRootsAPI.nameOrder): "surname-first" (default, e.g.
 * "Νικολακάκης Μανώλης") or "given-first" ("Eleftherios Venizelos"). In
 * given-first names the surname is the last plain word plus any married
 * name in brackets after it; nicknames and initials stay with the given name.
 */
export function splitName( fullName ) {
	const name = ( fullName || '' ).trim();
	const order = ( window.GenoRootsAPI && window.GenoRootsAPI.nameOrder ) || 'surname-first';

	if ( order === 'given-first' ) {
		const words = name.split( /\s+/ );
		let last = words.length - 1;
		while ( last > 0 && isExtra( words[ last ] ) ) {
			last--;
		}
		return last <= 0
			? { surname: '', given: name }
			: { surname: words.slice( last ).join( ' ' ), given: words.slice( 0, last ).join( ' ' ) };
	}

	const space = name.indexOf( ' ' );
	return space < 0
		? { surname: '', given: name }
		: { surname: name.slice( 0, space ), given: name.slice( space + 1 ) };
}

export function lifespan( person ) {
	const born = yearOf( person.birth_date );
	const died = yearOf( person.death_date );
	if ( born && died ) {
		return `${ born } – ${ died }`;
	}
	if ( born ) {
		return LIFESPAN_STRINGS.born( born );
	}
	if ( died ) {
		return `${ LIFESPAN_STRINGS.died } ${ died }`;
	}
	return '';
}

/** Accent- and case-insensitive key for matching Greek text as you type. */
export function searchKey( text ) {
	return String( text || '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /ς/g, 'σ' );
}

export function isMobileViewport() {
	return window.matchMedia( '(max-width: 640px)' ).matches;
}
