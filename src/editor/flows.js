// The editing sheets: add a relative, edit a person or a marriage, remove a
// link, change the portrait, see and undo changes, and the changes that
// are waiting to be sent.
import { config, esc, t, tf, tn } from './env.js';
import { ApiError, NetworkError, get, send, sendUnsent } from './api.js';
import { editPortrait } from './portrait.js';
import { getDraft, getUnsent, removeUnsent, updateUnsent } from './store.js';
import {
	busy, cardHtml, closeSheet, errorHtml, forgetDraft, marriageFields, openSheet, personFields,
	readForm, setSheetBody, setSheetTitle, sheetBody, toast, when, wireForm,
} from './ui.js';

// { showView( view ), open( id, view ), reload(), refreshBadge(), currentCollection() }
let app = null;

export function connect( callbacks ) {
	app = callbacks;
}

/** After a change was saved: show the answer, offer Undo. */
function saved( result, { closing = true } = {} ) {
	if ( closing ) {
		closeSheet();
	}
	if ( result && result.person ) {
		app.showView( result );
	}
	if ( result && result.change_id ) {
		toast( t( 'saved' ), { action: t( 'undo' ), onAction: () => undoChange( result.change_id ) } );
	} else {
		toast( t( 'saved' ) );
	}
}

/** Shared handling of a failed save. Returns true when the sheet should close. */
function failed( error, formEl ) {
	if ( error.queued ) {
		closeSheet();
		app.refreshBadge();
		toast( error.code === 'signed_out' ? t( 'signedOut' ) : t( 'offline' ), { kind: 'warn' } );
		return true;
	}
	const box = formEl && formEl.querySelector( '.gnr-ed-form-error' );
	const message = error instanceof ApiError ? ( error.message || t( 'error' ) ) : t( 'error' );
	if ( box ) {
		box.innerHTML = errorHtml( message );
		box.scrollIntoView( { block: 'nearest' } );
	} else {
		toast( message, { kind: 'error' } );
	}
	return false;
}

// Add a relative ----------------------------------------------------------------

const RELATION_TITLES = { spouse: 'spouseOfTitle', child: 'childOfTitle', parent: 'parentOfTitle', sibling: 'siblingOfTitle' };

/**
 * First search the tree (to link someone already there), then, if they
 * aren't, create them. For a child, choose which marriage first.
 */
export function addRelative( view, relation ) {
	const anchor = view.person;
	const state = { familyId: 0, query: '' };
	if ( relation === 'child' && view.marriages.length ) {
		state.familyId = view.marriages[ 0 ].family_id;
	}
	openSheet( { title: tf( RELATION_TITLES[ relation ], anchor.name ) } );
	pickStep();

	function marriageChooser() {
		if ( relation !== 'child' || ! view.marriages.length ) {
			return '';
		}
		const options = view.marriages.map( ( m ) => [ m.family_id, m.spouse ? m.spouse.name : t( 'unknownSpouse' ) ] );
		if ( ! view.marriages.some( ( m ) => ! m.spouse ) ) {
			options.push( [ 0, t( 'unknownSpouse' ) ] );
		}
		return `
			<div class="gnr-ed-field">
				<span class="gnr-ed-field__label">${ esc( t( 'whichMarriage' ) ) }</span>
				<div class="gnr-ed-seg gnr-ed-seg--choice gnr-ed-seg--wrap" role="radiogroup">
					${ options.map( ( [ id, label ] ) => `<label><input type="radio" name="family" value="${ id }"${ id === state.familyId ? ' checked' : '' }><span>${ esc( label ) }</span></label>` ).join( '' ) }
				</div>
			</div>`;
	}

	function pickStep() {
		setSheetTitle( tf( RELATION_TITLES[ relation ], anchor.name ) );
		const body = setSheetBody( `
			${ marriageChooser() }
			<label class="gnr-ed-field">
				<span class="gnr-ed-field__label">${ esc( t( 'findExisting' ) ) }</span>
				<input type="search" class="gnr-ed-search__input" value="${ esc( state.query ) }" placeholder="${ esc( t( 'search' ) ) }" autocomplete="off" enterkeyhint="search">
			</label>
			<div class="gnr-ed-results" aria-live="polite"></div>
			<div class="gnr-ed-or">
				<span>${ esc( t( 'orCreate' ) ) }</span>
				<button type="button" class="gnr-ed-button gnr-ed-button--soft" data-create>${ esc( t( 'createNew' ) ) }</button>
			</div>
			<div class="gnr-ed-form-error"></div>` );
		const input = body.querySelector( 'input[type="search"]' );
		const results = body.querySelector( '.gnr-ed-results' );
		body.querySelectorAll( 'input[name="family"]' ).forEach( ( radio ) => radio.addEventListener( 'change', () => {
			state.familyId = Number( radio.value );
		} ) );
		searchInto( input, results, ( card ) => submit( { existing_id: card.id } ), anchor.id, ( q ) => {
			state.query = q;
		} );
		body.querySelector( '[data-create]' ).addEventListener( 'click', () => newStep() );
	}

	function newStep( values ) {
		const key = `relative:${ anchor.id }:${ relation }`;
		const kept = values ? null : getDraft( key );
		const draft = values || kept || defaults();
		const body = setSheetBody( `
			<form class="gnr-ed-form" novalidate>
				${ kept ? `<p class="gnr-ed-note">${ esc( t( 'draftKept' ) ) }</p>` : '' }
				${ personFields( draft ) }
				${ relation === 'spouse' ? marriageFields( draft ) : '' }
				<div class="gnr-ed-form-error"></div>
				<div class="gnr-ed-actions">
					<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-back>${ esc( t( 'back' ) ) }</button>
					<button type="submit" class="gnr-ed-button">${ esc( t( 'save' ) ) }</button>
				</div>
			</form>` );
		const form = body.querySelector( 'form' );
		wireForm( form, key );
		form.querySelector( '[data-back]' ).addEventListener( 'click', () => pickStep() );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			const fields = readForm( form );
			if ( ! fields.name ) {
				form.querySelector( '.gnr-ed-form-error' ).innerHTML = errorHtml( t( 'name' ) + ' ?' );
				form.querySelector( 'input[name="name"]' ).focus();
				return;
			}
			const marriage = relation === 'spouse' ? { marriage_date_raw: fields.marriage_date_raw, marriage_place: fields.marriage_place } : undefined;
			delete fields.marriage_date_raw;
			delete fields.marriage_place;
			submit( { new_person: fields, marriage }, form, key );
		} );
	}

	function defaults() {
		const sex = relation === 'spouse' ? ( { M: 'F', F: 'M' }[ anchor.sex ] || 'U' ) : 'U';
		const name = state.query && ! /^\d+$/.test( state.query ) ? state.query : '';
		return { name, sex };
	}

	async function submit( extra, form, draftKey, flags = {} ) {
		const button = form ? form.querySelector( '[type="submit"]' ) : null;
		busy( button, true );
		const body = {
			person: anchor.id,
			relation,
			family_id: relation === 'child' ? state.familyId : 0,
			...extra,
			...flags,
		};
		try {
			const result = await send( 'POST', 'editor/relative', body, `${ tf( RELATION_TITLES[ relation ], anchor.name ) }: ${ extra.new_person ? extra.new_person.name : '…' }` );
			forgetDraft( draftKey );
			saved( result );
		} catch ( error ) {
			busy( button, false );
			if ( error instanceof ApiError && error.code === 'genoroots_possible_duplicates' ) {
				duplicatesStep( error, extra, form, draftKey, flags );
			} else if ( error instanceof ApiError && error.data.needs_confirm ) {
				confirmStep( error.message, () => submit( extra, form, draftKey, { ...flags, confirm: true } ) );
			} else if ( failed( error, form || sheetBody() ) ) {
				forgetDraft( draftKey );
			}
		}
	}

	function duplicatesStep( error, extra, form, draftKey, flags ) {
		const values = readForm( form );
		const body = setSheetBody( `
			<p class="gnr-ed-lead">${ esc( t( 'duplicates' ) ) }</p>
			<div class="gnr-ed-results">
				${ error.data.matches.filter( ( c ) => c.id !== anchor.id ).map( ( c ) => cardHtml( c, { attrs: `data-id="${ c.id }"` } ) ).join( '' ) }
			</div>
			<div class="gnr-ed-form-error"></div>
			<div class="gnr-ed-actions gnr-ed-actions--stack">
				<button type="button" class="gnr-ed-button gnr-ed-button--soft" data-new>${ esc( t( 'noneOfThese' ) ) }</button>
				<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-back>${ esc( t( 'back' ) ) }</button>
			</div>` );
		body.querySelectorAll( '[data-id]' ).forEach( ( el ) => el.addEventListener( 'click', () => {
			forgetDraft( draftKey );
			submit( { existing_id: Number( el.dataset.id ) }, null, null, flags );
		} ) );
		body.querySelector( '[data-new]' ).addEventListener( 'click', ( event ) => {
			busy( event.currentTarget, true );
			submit( extra, null, draftKey, { ...flags, confirm_new: true } );
		} );
		body.querySelector( '[data-back]' ).addEventListener( 'click', () => newStep( values ) );
	}
}

function confirmStep( message, onYes ) {
	const body = setSheetBody( `
		<p class="gnr-ed-lead">${ esc( message ) }</p>
		<div class="gnr-ed-form-error"></div>
		<div class="gnr-ed-actions">
			<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-no>${ esc( t( 'cancel' ) ) }</button>
			<button type="button" class="gnr-ed-button" data-yes>${ esc( t( 'move' ) ) }</button>
		</div>` );
	body.querySelector( '[data-no]' ).addEventListener( 'click', () => closeSheet() );
	body.querySelector( '[data-yes]' ).addEventListener( 'click', ( event ) => {
		busy( event.currentTarget, true );
		onYes();
	} );
}

/**
 * Live search into a results box. Tapping a result calls onPick(card).
 */
export function searchInto( input, results, onPick, excludeId = 0, onQuery = () => {} ) {
	let timer;
	let latest = 0;
	const run = async () => {
		const q = input.value.trim();
		onQuery( q );
		if ( q.length < 2 && ! /^\d+$/.test( q ) ) {
			results.innerHTML = '';
			return;
		}
		const ticket = ++latest;
		try {
			const cards = await get( 'editor/search', { q, collection: app.currentCollection() } );
			if ( ticket !== latest ) {
				return;
			}
			const shown = cards.filter( ( c ) => c.id !== excludeId );
			results.innerHTML = shown.length
				? shown.map( ( c ) => cardHtml( c, { attrs: `data-id="${ c.id }"` } ) ).join( '' )
				: `<p class="gnr-ed-empty">${ esc( t( 'noResults' ) ) }</p>`;
			results.querySelectorAll( '[data-id]' ).forEach( ( el ) => el.addEventListener( 'click', () => {
				onPick( shown.find( ( c ) => c.id === Number( el.dataset.id ) ) );
			} ) );
		} catch ( error ) {
			if ( ticket === latest ) {
				results.innerHTML = errorHtml( error instanceof NetworkError ? t( 'offline' ) : ( error.message || t( 'error' ) ) );
			}
		}
	};
	input.addEventListener( 'input', () => {
		clearTimeout( timer );
		timer = setTimeout( run, 250 );
	} );
	if ( input.value ) {
		run();
	}
	return run;
}

// Create a person on their own ----------------------------------------------------

export function createPerson( initialName = '' ) {
	const key = 'create';
	openSheet( { title: t( 'newPerson' ) } );
	render( getDraft( key ) || { name: initialName } );

	function render( values, extraHtml = '' ) {
		const body = setSheetBody( `
			<form class="gnr-ed-form" novalidate>
				${ extraHtml }
				${ personFields( values ) }
				<div class="gnr-ed-form-error"></div>
				<div class="gnr-ed-actions">
					<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-close>${ esc( t( 'cancel' ) ) }</button>
					<button type="submit" class="gnr-ed-button">${ esc( t( 'save' ) ) }</button>
				</div>
			</form>` );
		const form = body.querySelector( 'form' );
		wireForm( form, key );
		form.querySelector( '[data-close]' ).addEventListener( 'click', () => forgetDraft( key ) );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			save( form, {} );
		} );
	}

	async function save( form, flags ) {
		const fields = readForm( form );
		if ( ! fields.name ) {
			form.querySelector( 'input[name="name"]' ).focus();
			return;
		}
		const button = form.querySelector( '[type="submit"]' );
		busy( button, true );
		try {
			const result = await send( 'POST', 'editor/person', { fields, collection: app.currentCollection(), ...flags }, `${ t( 'newPerson' ) }: ${ fields.name }` );
			forgetDraft( key );
			app.open( result.person.id, result );
			toast( t( 'saved' ), { action: t( 'undo' ), onAction: () => undoChange( result.change_id ) } );
		} catch ( error ) {
			busy( button, false );
			if ( error instanceof ApiError && error.code === 'genoroots_possible_duplicates' ) {
				const body = setSheetBody( `
					<p class="gnr-ed-lead">${ esc( t( 'duplicates' ) ) }</p>
					<div class="gnr-ed-results">${ error.data.matches.map( ( c ) => cardHtml( c, { attrs: `data-id="${ c.id }"` } ) ).join( '' ) }</div>
					<div class="gnr-ed-actions gnr-ed-actions--stack">
						<button type="button" class="gnr-ed-button gnr-ed-button--soft" data-new>${ esc( t( 'noneOfThese' ) ) }</button>
						<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-back>${ esc( t( 'back' ) ) }</button>
					</div>` );
				body.querySelectorAll( '[data-id]' ).forEach( ( el ) => el.addEventListener( 'click', () => {
					forgetDraft( key );
					app.open( Number( el.dataset.id ) );
				} ) );
				body.querySelector( '[data-back]' ).addEventListener( 'click', () => render( fields ) );
				body.querySelector( '[data-new]' ).addEventListener( 'click', () => {
					render( fields );
					save( sheetBody().querySelector( 'form' ), { confirm_new: true } );
				} );
			} else if ( failed( error, form ) ) {
				forgetDraft( key );
			}
		}
	}
}

// Edit a person -----------------------------------------------------------------

const FIELD_LABELS = {
	name: 'name', father_name: 'fatherName', nickname: 'nickname', sex: 'sex',
	birth_date_raw: 'birth', birth_place: 'place', death_date_raw: 'death', death_place: 'place', notes: 'notes',
};

export function editPerson( view ) {
	const person = view.person;
	const key = `edit:${ person.id }`;
	let version = person.version;
	openSheet( { title: t( 'editPerson' ) } );
	const draft = getDraft( key );
	render( draft || person.fields, draft ? `<p class="gnr-ed-note">${ esc( t( 'draftKept' ) ) }</p>` : '' );

	function render( values, extraHtml = '' ) {
		const body = setSheetBody( `
			<form class="gnr-ed-form" novalidate>
				${ extraHtml }
				${ personFields( values ) }
				<div class="gnr-ed-form-error"></div>
				<div class="gnr-ed-actions">
					<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-cancel>${ esc( t( 'cancel' ) ) }</button>
					<button type="submit" class="gnr-ed-button">${ esc( t( 'save' ) ) }</button>
				</div>
			</form>` );
		const form = body.querySelector( 'form' );
		wireForm( form, key );
		form.querySelector( '[data-cancel]' ).addEventListener( 'click', () => {
			forgetDraft( key );
			closeSheet();
		} );
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const fields = readForm( form );
			if ( ! fields.name ) {
				form.querySelector( 'input[name="name"]' ).focus();
				return;
			}
			const button = form.querySelector( '[type="submit"]' );
			busy( button, true );
			try {
				const result = await send( 'POST', `editor/person/${ person.id }`, { fields, version }, `${ t( 'editPerson' ) }: ${ fields.name }` );
				forgetDraft( key );
				saved( result );
			} catch ( error ) {
				busy( button, false );
				if ( error instanceof ApiError && error.code === 'genoroots_edit_conflict' && error.data.current ) {
					// Show what is there now; keep what they typed beside it.
					const current = error.data.current.person;
					version = current.version;
					app.showView( error.data.current );
					const mine = Object.keys( fields ).filter( ( k ) => ( fields[ k ] || '' ) !== ( current.fields[ k ] || '' ) );
					render( current.fields, `
						<p class="gnr-ed-note gnr-ed-note--warn">${ esc( t( 'conflict' ) ) }</p>
						${ mine.length ? `<div class="gnr-ed-yours"><strong>${ esc( t( 'yours' ) ) }</strong>${ mine.map( ( k ) => `<div><span>${ esc( t( FIELD_LABELS[ k ] || k ) ) }:</span> ${ esc( fields[ k ] ) }</div>` ).join( '' ) }</div>` : '' }` );
				} else if ( failed( error, form ) ) {
					forgetDraft( key );
				}
			}
		} );
	}
}

// Marriage details ----------------------------------------------------------------

export function editMarriage( view, marriage ) {
	openSheet( { title: t( 'editMarriage' ) } );
	const body = setSheetBody( `
		<form class="gnr-ed-form" novalidate>
			${ marriage.spouse ? cardHtml( marriage.spouse, { tag: 'div', detail: false } ) : '' }
			${ marriageFields( marriage, { certainty: true } ) }
			<div class="gnr-ed-form-error"></div>
			<div class="gnr-ed-actions">
				<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-close>${ esc( t( 'cancel' ) ) }</button>
				<button type="submit" class="gnr-ed-button">${ esc( t( 'save' ) ) }</button>
			</div>
		</form>` );
	const form = body.querySelector( 'form' );
	wireForm( form, null );
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector( '[type="submit"]' );
		busy( button, true );
		try {
			const result = await send( 'POST', `editor/family/${ marriage.family_id }`, {
				person: view.person.id,
				fields: readForm( form ),
				version: marriage.version,
			}, t( 'editMarriage' ) );
			saved( result );
		} catch ( error ) {
			busy( button, false );
			failed( error, form );
		}
	} );
}

// Remove a link -------------------------------------------------------------------

export async function removeLink( view, familyId, other, familyLabel ) {
	// eslint-disable-next-line no-alert
	if ( ! window.confirm( tf( 'removeConfirm', other.name, familyLabel ) ) ) {
		return;
	}
	try {
		const result = await send( 'POST', 'editor/unlink', { person: view.person.id, family_id: familyId, other: other.id }, `${ t( 'removeLink' ) }: ${ other.name }` );
		saved( result, { closing: false } );
	} catch ( error ) {
		failed( error, null );
	}
}

// Portrait ------------------------------------------------------------------------

export function choosePortrait( view ) {
	editPortrait( view, ( result ) => saved( result ), () => {} );
}

// History and undo ------------------------------------------------------------------

export function showHistory( personId = 0 ) {
	openSheet( { title: personId ? t( 'history' ) : t( 'allHistory' ) } );
	load();

	async function load() {
		setSheetBody( `<p class="gnr-ed-empty">…</p>` );
		try {
			const rows = await get( 'editor/changes', { person: personId || undefined, limit: 40 } );
			const body = setSheetBody( rows.length ? `
				<ol class="gnr-ed-history">
					${ rows.map( ( row ) => `
						<li class="gnr-ed-history__row${ row.undone ? ' is-undone' : '' }">
							<div class="gnr-ed-history__text">
								<span class="gnr-ed-history__summary">${ esc( row.summary ) }</span>
								<span class="gnr-ed-history__meta">${ esc( [ row.user, when( row.time ) ].filter( Boolean ).join( ' · ' ) ) }</span>
							</div>
							${ row.undone ? `<span class="gnr-ed-badge">${ esc( t( 'undone' ) ) }</span>` : '' }
							${ row.can_undo ? `<button type="button" class="gnr-ed-button gnr-ed-button--small gnr-ed-button--soft" data-undo="${ row.id }">${ esc( t( row.button === 'redo' ? 'redo' : 'undo' ) ) }</button>` : '' }
						</li>` ).join( '' ) }
				</ol>
				<div class="gnr-ed-form-error"></div>` : `<p class="gnr-ed-empty">${ esc( t( 'noHistory' ) ) }</p>` );
			body.querySelectorAll( '[data-undo]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( t( 'undoConfirm' ) ) ) {
					return;
				}
				busy( button, true );
				try {
					const result = await undoRequest( Number( button.dataset.undo ) );
					toast( t( 'undone' ) );
					if ( personId && result.person && result.person.id !== personId ) {
						// The undo removed this person: go to whoever the change was made on.
						app.open( result.person.id, result );
						return;
					}
					if ( result.person ) {
						app.showView( result );
					}
					load();
				} catch ( error ) {
					busy( button, false );
					failed( error, body );
				}
			} ) );
		} catch ( error ) {
			setSheetBody( errorHtml( error instanceof NetworkError ? t( 'offline' ) : ( error.message || t( 'error' ) ) ) );
		}
	}
}

const undoRequest = ( changeId ) => send( 'POST', `editor/changes/${ changeId }/undo`, {}, t( 'undo' ) );

export async function undoChange( changeId ) {
	try {
		const result = await undoRequest( changeId );
		if ( result.person ) {
			app.showView( result );
		}
		toast( t( 'undone' ) );
	} catch ( error ) {
		failed( error, null );
	}
}

// Changes waiting to be sent -------------------------------------------------------

export function showUnsent() {
	openSheet( { title: tn( 'unsent', getUnsent().length ) } );
	render();

	function render() {
		const items = getUnsent();
		if ( ! items.length ) {
			closeSheet();
			app.refreshBadge();
			return;
		}
		setSheetTitle( tn( 'unsent', items.length ) );
		const body = setSheetBody( `
			<ul class="gnr-ed-unsent">
				${ items.map( ( item ) => `
					<li class="gnr-ed-unsent__item">
						<div class="gnr-ed-unsent__text">
							<span>${ esc( item.label ) }</span>
							<span class="gnr-ed-history__meta">${ esc( when( new Date( item.at ).toISOString() ) ) }</span>
							${ item.error ? errorHtml( item.error ) : '' }
						</div>
						<div class="gnr-ed-unsent__buttons">
							${ item.code === 'genoroots_possible_duplicates' ? `<button type="button" class="gnr-ed-button gnr-ed-button--small" data-force="${ esc( item.request_id ) }" data-flag="confirm_new">${ esc( t( 'noneOfThese' ) ) }</button>` : '' }
							${ item.code === 'genoroots_needs_confirm' ? `<button type="button" class="gnr-ed-button gnr-ed-button--small" data-force="${ esc( item.request_id ) }" data-flag="confirm">${ esc( t( 'move' ) ) }</button>` : '' }
							<button type="button" class="gnr-ed-button gnr-ed-button--small gnr-ed-button--quiet" data-discard="${ esc( item.request_id ) }">${ esc( t( 'discard' ) ) }</button>
						</div>
					</li>` ).join( '' ) }
			</ul>
			<div class="gnr-ed-actions">
				<button type="button" class="gnr-ed-button" data-send>${ esc( t( 'retry' ) ) }</button>
			</div>` );
		body.querySelector( '[data-send]' ).addEventListener( 'click', async ( event ) => {
			busy( event.currentTarget, true );
			await flushUnsent();
			render();
		} );
		body.querySelectorAll( '[data-discard]' ).forEach( ( button ) => button.addEventListener( 'click', () => {
			// eslint-disable-next-line no-alert
			if ( window.confirm( t( 'discardConfirm' ) ) ) {
				removeUnsent( button.dataset.discard );
				app.refreshBadge();
				render();
			}
		} ) );
		body.querySelectorAll( '[data-force]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
			const item = getUnsent().find( ( i ) => i.request_id === button.dataset.force );
			if ( item ) {
				updateUnsent( item.request_id, { body: { ...item.body, [ button.dataset.flag ]: true }, error: '', code: '' } );
				busy( button, true );
				await flushUnsent();
				render();
			}
		} ) );
	}
}

/** Sends what is waiting; refreshes the open person afterwards. */
export async function flushUnsent() {
	if ( ! getUnsent().length ) {
		return;
	}
	const { sent } = await sendUnsent();
	app.refreshBadge();
	if ( sent ) {
		toast( t( 'saved' ) );
		app.reload();
	}
}

export const canUpload = () => Boolean( config.canUpload );
