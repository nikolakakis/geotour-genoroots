// What the editor keeps on the phone, per signed-in user: the chosen
// collection, recently opened people (and their last loaded family, to show
// when the connection drops), unfinished forms and changes not yet sent.
// localStorage can be missing or full; then the editor just works without it.
import { config } from './env.js';

const PREFIX = `gnr-editor:${ config.user ? config.user.id : 0 }:`;
const MAX_RECENT = 12;
const MAX_CACHED = 25;

function read( key, fallback ) {
	try {
		const raw = window.localStorage.getItem( PREFIX + key );
		return raw === null ? fallback : JSON.parse( raw );
	} catch ( e ) {
		return fallback;
	}
}

function write( key, value ) {
	try {
		window.localStorage.setItem( PREFIX + key, JSON.stringify( value ) );
	} catch ( e ) {
		// Full or blocked: nothing to do.
	}
}

function remove( key ) {
	try {
		window.localStorage.removeItem( PREFIX + key );
	} catch ( e ) {
		// ignore
	}
}

// Collection --------------------------------------------------------------

export const getCollection = () => read( 'collection', null );
export const setCollection = ( id ) => write( 'collection', id );

// Recent people and their last loaded view ---------------------------------

export const getRecent = () => read( 'recent', [] );

export function rememberPerson( view ) {
	const card = view.person;
	const recent = getRecent().filter( ( c ) => c.id !== card.id );
	recent.unshift( {
		id: card.id,
		name: card.name,
		nickname: card.nickname,
		father_name: card.father_name,
		years: card.years,
		sex: card.sex,
		portrait: card.portrait,
		spouses: card.spouses,
		parents: card.parents,
		gedcom_id: card.gedcom_id,
	} );
	write( 'recent', recent.slice( 0, MAX_RECENT ) );

	const cached = read( 'cached', [] ).filter( ( id ) => id !== card.id );
	cached.unshift( card.id );
	cached.slice( MAX_CACHED ).forEach( ( id ) => remove( `person:${ id }` ) );
	write( 'cached', cached.slice( 0, MAX_CACHED ) );
	write( `person:${ card.id }`, view );
}

export const cachedPerson = ( id ) => read( `person:${ id }`, null );

export function forgetPerson( id ) {
	write( 'recent', getRecent().filter( ( c ) => c.id !== id ) );
	remove( `person:${ id }` );
}

// Unfinished forms -----------------------------------------------------------

export const getDraft = ( key ) => read( `draft:${ key }`, null );
export const setDraft = ( key, values ) => write( `draft:${ key }`, values );
export const clearDraft = ( key ) => remove( `draft:${ key }` );

// Changes not sent yet --------------------------------------------------------

export const getUnsent = () => read( 'unsent', [] );

export function addUnsent( item ) {
	write( 'unsent', [ ...getUnsent().filter( ( i ) => i.request_id !== item.request_id ), item ] );
}

export function updateUnsent( requestId, patch ) {
	write( 'unsent', getUnsent().map( ( i ) => ( i.request_id === requestId ? { ...i, ...patch } : i ) ) );
}

export function removeUnsent( requestId ) {
	write( 'unsent', getUnsent().filter( ( i ) => i.request_id !== requestId ) );
}
