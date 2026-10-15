// Talking to geotour-genoroots/v1/editor/…
//
// Every change carries a request_id. When the phone has no connection, the
// change is kept (store.js "unsent") and sent again later with the same ID:
// the server recognises a repeat and answers without saving twice.
import { config, requestId } from './env.js';
import { addUnsent, getUnsent, removeUnsent, updateUnsent } from './store.js';

const TIMEOUT_MS = 25000;
let nonce = config.nonce;

/** The server refused (validation, conflict, permissions…). */
export class ApiError extends Error {
	constructor( code, message, data = {}, status = 0 ) {
		super( message );
		this.code = code;
		this.data = data || {};
		this.status = status;
	}
}

/** No answer: offline, timed out, or the server could not be reached. */
export class NetworkError extends Error {}

function url( path, params = {} ) {
	const u = new URL( config.root, window.location.href );
	if ( u.searchParams.has( 'rest_route' ) ) {
		u.searchParams.set( 'rest_route', u.searchParams.get( 'rest_route' ) + path );
	} else {
		u.pathname += path;
	}
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			u.searchParams.set( key, value );
		}
	} );
	return u.toString();
}

async function refreshNonce() {
	if ( ! config.ajaxUrl ) {
		return false;
	}
	try {
		const response = await fetch( `${ config.ajaxUrl }?action=rest-nonce`, { credentials: 'same-origin' } );
		const text = ( await response.text() ).trim();
		if ( response.ok && /^[a-f0-9]{10}$/.test( text ) ) {
			nonce = text;
			return true;
		}
	} catch ( e ) {
		// fall through
	}
	return false;
}

/**
 * One request. Resolves with the JSON answer; rejects with ApiError or
 * NetworkError.
 */
export async function request( method, path, { params, body, form, retried = false } = {} ) {
	if ( typeof navigator !== 'undefined' && navigator.onLine === false ) {
		throw new NetworkError( 'offline' );
	}
	const controller = new AbortController();
	const timer = setTimeout( () => controller.abort(), TIMEOUT_MS );
	const headers = { 'X-WP-Nonce': nonce, Accept: 'application/json' };
	let payload;
	if ( form ) {
		payload = form;
	} else if ( body ) {
		headers[ 'Content-Type' ] = 'application/json';
		payload = JSON.stringify( body );
	}

	let response;
	try {
		response = await fetch( url( path, params ), {
			method,
			headers,
			body: payload,
			credentials: 'same-origin',
			signal: controller.signal,
		} );
	} catch ( e ) {
		throw new NetworkError( e.name === 'AbortError' ? 'timeout' : 'network' );
	} finally {
		clearTimeout( timer );
	}

	let json = null;
	try {
		json = await response.json();
	} catch ( e ) {
		// Not JSON: a proxy error page, a PHP fatal…
	}

	if ( response.ok ) {
		return json;
	}
	if ( response.status >= 502 && response.status <= 504 ) {
		throw new NetworkError( 'gateway' );
	}
	const code = json && json.code ? json.code : `http_${ response.status }`;
	if ( code === 'rest_cookie_invalid_nonce' && ! retried && await refreshNonce() ) {
		return request( method, path, { params, body, form, retried: true } );
	}
	if ( code === 'rest_cookie_invalid_nonce' || ( response.status === 401 ) ) {
		throw new ApiError( 'signed_out', '', {}, response.status );
	}
	throw new ApiError( code, json && json.message ? json.message : '', json && json.data ? json.data : {}, response.status );
}

export const get = ( path, params ) => request( 'GET', path, { params } );

/**
 * A change. With no connection it is kept to send later, and NetworkError
 * is thrown with .queued = true.
 *
 * @param {string} label What the change is, for the "not sent" list.
 */
export async function send( method, path, body, label ) {
	const item = { request_id: requestId(), method, path, body, label, at: Date.now() };
	item.body = { ...body, request_id: item.request_id };
	try {
		return await request( method, path, { body: item.body } );
	} catch ( error ) {
		if ( error instanceof NetworkError || ( error instanceof ApiError && error.code === 'signed_out' ) ) {
			addUnsent( item );
			error.queued = true;
		}
		throw error;
	}
}

/**
 * Sends the kept changes, oldest first. Stops at the first one that still
 * can't reach the server. A change the server refuses stays in the list
 * with its message, for the user to fix or discard.
 *
 * @return {Promise<{sent: number, failed: number}>}
 */
export async function sendUnsent() {
	let sent = 0;
	let failed = 0;
	for ( const item of getUnsent() ) {
		try {
			await request( item.method, item.path, { body: item.body } );
			removeUnsent( item.request_id );
			sent++;
		} catch ( error ) {
			if ( error instanceof NetworkError || error.code === 'signed_out' ) {
				break;
			}
			updateUnsent( item.request_id, { error: error.message, code: error.code } );
			failed++;
		}
	}
	return { sent, failed };
}

export async function uploadPortrait( personId, blob ) {
	const form = new FormData();
	form.append( 'file', blob, 'portrait.jpg' );
	form.append( 'request_id', requestId() );
	return request( 'POST', `editor/person/${ personId }/portrait`, { form } );
}
