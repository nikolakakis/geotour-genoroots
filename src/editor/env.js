// Settings and strings from PHP (GenoRoots_Field_Editor::enqueue()).
export const config = window.GenoRootsEditor || {};

const strings = config.i18n || {};

/** A plain string. */
export const t = ( key ) => ( typeof strings[ key ] === 'string' ? strings[ key ] : key );

/** A string with %s / %1$s placeholders. */
export const tf = ( key, ...values ) => {
	let next = 0;
	return t( key ).replace( /%(?:(\d)\$)?[sd]/g, ( match, position ) => {
		const value = values[ position ? Number( position ) - 1 : next++ ];
		return value === undefined ? '' : String( value );
	} );
};

/** A counted string: PHP sends [ singular, plural ] with %d. */
export const tn = ( key, n ) => {
	const forms = Array.isArray( strings[ key ] ) ? strings[ key ] : [ key, key ];
	return forms[ n === 1 ? 0 : 1 ].replace( '%d', n );
};

export const esc = ( value ) => String( value ?? '' ).replace( /[&<>"']/g, ( c ) => (
	{ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]
) );

/**
 * An ID for one change, sent with it so a retry is recognised. Not
 * crypto.randomUUID(): that needs HTTPS, and local sites are plain HTTP.
 */
export const requestId = () => {
	const bytes = new Uint8Array( 16 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
};
