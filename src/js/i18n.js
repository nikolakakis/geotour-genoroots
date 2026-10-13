// Front-end strings come from PHP (GenoRoots_I18n::script_strings()), already
// translated for the page's language by the plugin's .po/.mo catalogue.
const strings = ( window.GenoRootsAPI && window.GenoRootsAPI.i18n ) || {};

/** A plain string. */
export const t = ( key ) => ( typeof strings[ key ] === 'string' ? strings[ key ] : key );

/** A string with one %s / %d placeholder. */
export const tf = ( key ) => ( value ) => t( key ).replace( /%[sd]/, value );

/** A counted string: PHP sends [ singular, plural ] with %d. */
export const tn = ( key ) => ( n ) => {
	const forms = Array.isArray( strings[ key ] ) ? strings[ key ] : [ key, key ];
	return forms[ n === 1 ? 0 : 1 ].replace( '%d', n );
};
