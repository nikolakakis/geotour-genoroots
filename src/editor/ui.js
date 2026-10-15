// Shared pieces: the bottom sheet, toasts, person cards and form fields.
import { config, esc, t, tf } from './env.js';
import { clearDraft, getDraft, setDraft } from './store.js';

// Sheet ------------------------------------------------------------------------
//
// One sheet at a time, sliding up from the bottom on phones (a dialog on wide
// screens). It adds a history entry, so the phone's Back button closes it.

let sheet = null;

export function openSheet( { title, html = '', onClose } = {} ) {
	closeSheet( { silent: true } );
	const el = document.createElement( 'div' );
	el.className = 'gnr-ed-sheet';
	el.innerHTML = `
		<div class="gnr-ed-sheet__backdrop" data-close></div>
		<section class="gnr-ed-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="gnr-ed-sheet-title">
			<header class="gnr-ed-sheet__head">
				<h2 class="gnr-ed-sheet__title" id="gnr-ed-sheet-title">${ esc( title ) }</h2>
				<button type="button" class="gnr-ed-icon" data-close aria-label="${ esc( t( 'close' ) ) }">✕</button>
			</header>
			<div class="gnr-ed-sheet__body"></div>
		</section>`;
	document.body.appendChild( el );
	document.documentElement.classList.add( 'gnr-ed-locked' );
	el.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '[data-close]' ) ) {
			closeSheet();
		}
	} );
	sheet = { el, body: el.querySelector( '.gnr-ed-sheet__body' ), onClose, pushed: true };
	window.history.pushState( { gnrSheet: true }, '' );
	void el.offsetWidth; // apply the closed state first, so the slide-in animates
	el.classList.add( 'is-open' );
	setSheetBody( html );
	return sheet;
}

export function setSheetBody( html ) {
	if ( sheet ) {
		sheet.body.innerHTML = html;
		sheet.body.scrollTop = 0;
	}
	return sheet ? sheet.body : null;
}

export function setSheetTitle( title ) {
	if ( sheet ) {
		sheet.el.querySelector( '.gnr-ed-sheet__title' ).textContent = title;
	}
}

export const sheetBody = () => ( sheet ? sheet.body : null );
export const sheetOpen = () => sheet !== null;

/**
 * Closes the sheet. By default through history.back(), which takes away
 * the entry it added; silent: just remove it (Back already happened, or a
 * new sheet replaces it).
 */
export function closeSheet( { silent = false } = {} ) {
	if ( ! sheet ) {
		return;
	}
	const { el, onClose, pushed } = sheet;
	sheet = null;
	el.classList.remove( 'is-open' );
	document.documentElement.classList.remove( 'gnr-ed-locked' );
	setTimeout( () => el.remove(), 220 );
	if ( ! silent && pushed && window.history.state && window.history.state.gnrSheet ) {
		window.history.back();
	}
	if ( onClose ) {
		onClose();
	}
}

/** The sheet's history entry was left by navigating; drop it without going back. */
export function dropSheet() {
	if ( sheet ) {
		sheet.pushed = false;
		closeSheet( { silent: true } );
	}
}

window.addEventListener( 'popstate', ( event ) => {
	if ( sheet && ! ( event.state && event.state.gnrSheet ) ) {
		closeSheet( { silent: true } );
	}
} );

// Toast ------------------------------------------------------------------------

let toastTimer;

export function toast( message, { action, onAction, kind = '' } = {} ) {
	let el = document.querySelector( '.gnr-ed-toast' );
	if ( ! el ) {
		el = document.createElement( 'div' );
		el.className = 'gnr-ed-toast';
		el.setAttribute( 'role', 'status' );
		document.body.appendChild( el );
	}
	el.className = `gnr-ed-toast is-shown ${ kind ? `gnr-ed-toast--${ kind }` : '' }`;
	el.innerHTML = `<span>${ esc( message ) }</span>${ action ? `<button type="button">${ esc( action ) }</button>` : '' }`;
	if ( action ) {
		el.querySelector( 'button' ).addEventListener( 'click', () => {
			el.classList.remove( 'is-shown' );
			onAction();
		} );
	}
	clearTimeout( toastTimer );
	toastTimer = setTimeout( () => el.classList.remove( 'is-shown' ), action ? 7000 : 3500 );
}

// Cards ------------------------------------------------------------------------

const initials = ( name ) => String( name || '?' ).split( /\s+/ ).filter( Boolean ).slice( 0, 2 ).map( ( w ) => w[ 0 ] ).join( '' ).toUpperCase();

export const nicknameText = ( card ) => ( card.nickname ? tf( 'nicknameQuoted', card.nickname ) : '' );

/**
 * A person, recognisable at a glance: name and nickname, father's name and
 * years, then spouses or parents.
 *
 * @param {Object} card   From the API (GenoRoots_Editor_Controller::card()).
 * @param {Object} opts   tag (button|a|div), attrs, more (HTML after), detail.
 */
export function cardHtml( card, { tag = 'button', attrs = '', more = '', detail = true } = {} ) {
	const second = [ card.father_name, card.years ].filter( Boolean ).join( ' · ' );
	let third = '';
	if ( detail && card.spouses && card.spouses.length ) {
		third = tf( 'spouseOf', card.spouses.join( ', ' ) );
	} else if ( detail && card.parents && card.parents.length ) {
		third = tf( 'childOf', card.parents.join( ' & ' ) );
	}
	const number = /^I(\d+)$/.exec( card.gedcom_id || '' );
	const type = tag === 'button' ? ' type="button"' : '';
	return `
		<${ tag }${ type } class="gnr-ed-card gnr-ed-card--${ esc( ( card.sex || 'U' ).toLowerCase() ) }" ${ attrs }>
			<span class="gnr-ed-card__avatar">${ card.portrait ? `<img src="${ esc( card.portrait ) }" alt="" loading="lazy">` : esc( initials( card.name ) ) }</span>
			<span class="gnr-ed-card__text">
				<span class="gnr-ed-card__name">${ esc( card.name ) }${ card.nickname ? ` <span class="gnr-ed-card__nick">${ esc( nicknameText( card ) ) }</span>` : '' }</span>
				${ second ? `<span class="gnr-ed-card__line">${ esc( second ) }</span>` : '' }
				${ third ? `<span class="gnr-ed-card__line gnr-ed-card__line--soft">${ esc( third ) }</span>` : '' }
			</span>
			<span class="gnr-ed-card__tail">
				${ card.draft ? `<span class="gnr-ed-badge">${ esc( t( 'draft' ) ) }</span>` : '' }
				${ number ? `<span class="gnr-ed-card__id">${ esc( number[ 1 ] ) }</span>` : '' }
			</span>
		</${ tag }>${ more }`;
}

// Form fields ------------------------------------------------------------------

const DATE_MODES = [ [ '', 'exact' ], [ 'ABT', 'about' ], [ 'BEF', 'before' ], [ 'AFT', 'after' ] ];

/** "ABT 1908" → { mode: 'ABT', text: '1908' } */
function splitDate( raw ) {
	const match = /^(ABT|BEF|AFT)\s+(.*)$/i.exec( String( raw || '' ).trim() );
	return match ? { mode: match[ 1 ].toUpperCase(), text: match[ 2 ] } : { mode: '', text: String( raw || '' ) };
}

export function dateField( name, label, raw ) {
	const { mode, text } = splitDate( raw );
	return `
		<div class="gnr-ed-field gnr-ed-date" data-date="${ esc( name ) }">
			<span class="gnr-ed-field__label">${ esc( label ) }</span>
			<div class="gnr-ed-seg" role="group" aria-label="${ esc( label ) }">
				${ DATE_MODES.map( ( [ value, key ] ) => `<button type="button" data-mode="${ value }" aria-pressed="${ value === mode }">${ esc( t( key ) ) }</button>` ).join( '' ) }
			</div>
			<input type="text" name="${ esc( name ) }" value="${ esc( text ) }" placeholder="${ esc( t( 'datePlaceholder' ) ) }" autocomplete="off">
		</div>`;
}

export function textField( name, label, value, { hint = '', required = false, autofocus = false } = {} ) {
	return `
		<label class="gnr-ed-field">
			<span class="gnr-ed-field__label">${ esc( label ) }</span>
			<input type="text" name="${ esc( name ) }" value="${ esc( value ) }" autocomplete="off"${ required ? ' required' : '' }${ autofocus ? ' data-autofocus' : '' }>
			${ hint ? `<span class="gnr-ed-field__hint">${ esc( hint ) }</span>` : '' }
		</label>`;
}

function sexField( value ) {
	const options = [ [ 'M', 'male' ], [ 'F', 'female' ], [ 'U', 'unknown' ] ];
	return `
		<div class="gnr-ed-field">
			<span class="gnr-ed-field__label">${ esc( t( 'sex' ) ) }</span>
			<div class="gnr-ed-seg gnr-ed-seg--choice" role="radiogroup">
				${ options.map( ( [ v, key ] ) => `<label><input type="radio" name="sex" value="${ v }"${ ( value || 'U' ) === v ? ' checked' : '' }><span>${ esc( t( key ) ) }</span></label>` ).join( '' ) }
			</div>
		</div>`;
}

/** The person form: everything an editor writes about someone. */
export function personFields( values = {}, { open = false } = {} ) {
	const hasMore = open || values.death_date_raw || values.death_place || values.notes;
	return `
		${ textField( 'name', t( 'name' ), values.name, { hint: t( 'nameHint' ), required: true, autofocus: true } ) }
		<div class="gnr-ed-row">
			${ textField( 'father_name', t( 'fatherName' ), values.father_name ) }
			${ textField( 'nickname', t( 'nickname' ), values.nickname ) }
		</div>
		${ sexField( values.sex ) }
		<fieldset class="gnr-ed-group">
			<legend>${ esc( t( 'birth' ) ) }</legend>
			${ dateField( 'birth_date_raw', t( 'date' ), values.birth_date_raw ) }
			${ textField( 'birth_place', t( 'place' ), values.birth_place ) }
		</fieldset>
		<details class="gnr-ed-more"${ hasMore ? ' open' : '' }>
			<summary>${ esc( t( 'more' ) ) }</summary>
			<fieldset class="gnr-ed-group">
				<legend>${ esc( t( 'death' ) ) }</legend>
				${ dateField( 'death_date_raw', t( 'date' ), values.death_date_raw ) }
				${ textField( 'death_place', t( 'place' ), values.death_place ) }
			</fieldset>
			<label class="gnr-ed-field">
				<span class="gnr-ed-field__label">${ esc( t( 'notes' ) ) }</span>
				<textarea name="notes" rows="4" placeholder="${ esc( t( 'notesHint' ) ) }">${ esc( values.notes ) }</textarea>
			</label>
		</details>`;
}

/** Marriage date, place and (optionally) certainty. */
export function marriageFields( values = {}, { certainty = false } = {} ) {
	const statuses = [ 'confirmed', 'probable', 'claimed' ];
	return `
		<fieldset class="gnr-ed-group">
			<legend>${ esc( t( 'marriage' ) ) }</legend>
			${ dateField( 'marriage_date_raw', t( 'date' ), values.marriage_date_raw ) }
			${ textField( 'marriage_place', t( 'place' ), values.marriage_place ) }
			${ certainty ? `
				<label class="gnr-ed-field">
					<span class="gnr-ed-field__label">${ esc( t( 'certainty' ) ) }</span>
					<select name="status">${ statuses.map( ( s ) => `<option value="${ s }"${ ( values.status || 'confirmed' ) === s ? ' selected' : '' }>${ esc( t( s ) ) }</option>` ).join( '' ) }</select>
				</label>` : '' }
		</fieldset>`;
}

/** Makes the date buttons work and remembers the form while it is typed. */
export function wireForm( formEl, draftKey ) {
	formEl.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.gnr-ed-seg button[data-mode]' );
		if ( ! button ) {
			return;
		}
		button.parentElement.querySelectorAll( 'button' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b === button ) ) );
		button.closest( '.gnr-ed-date' ).querySelector( 'input' ).focus();
		saveDraft();
	} );
	let timer;
	function saveDraft() {
		if ( draftKey ) {
			clearTimeout( timer );
			timer = setTimeout( () => setDraft( draftKey, readForm( formEl ) ), 300 );
		}
	}
	formEl.addEventListener( 'input', saveDraft );
	const first = formEl.querySelector( '[data-autofocus]' );
	if ( first && window.matchMedia( '(hover: hover)' ).matches ) {
		first.focus(); // not on phones: the keyboard would cover the form
	}
}

/** The form's values, with dates put back together ("ABT 1908"). */
export function readForm( formEl ) {
	const values = {};
	new FormData( formEl ).forEach( ( value, key ) => {
		values[ key ] = typeof value === 'string' ? value.trim() : value;
	} );
	formEl.querySelectorAll( '.gnr-ed-date' ).forEach( ( el ) => {
		const name = el.dataset.date;
		const pressed = el.querySelector( 'button[aria-pressed="true"]' );
		const mode = pressed ? pressed.dataset.mode : '';
		const text = values[ name ] || '';
		values[ name ] = text && mode ? `${ mode } ${ text }` : text;
	} );
	return values;
}

/** Puts back an unfinished form, if one was kept. Returns true when it did. */
export function restoreDraft( formEl, draftKey, render ) {
	const draft = draftKey ? getDraft( draftKey ) : null;
	if ( ! draft ) {
		return false;
	}
	render( draft );
	return true;
}

export const forgetDraft = ( key ) => key && clearDraft( key );

// Misc -------------------------------------------------------------------------

export function errorHtml( message ) {
	return message ? `<p class="gnr-ed-error" role="alert">${ esc( message ) }</p>` : '';
}

export function busy( button, on ) {
	if ( ! button ) {
		return;
	}
	if ( on ) {
		button.dataset.label = button.textContent;
		button.textContent = t( 'saving' );
		button.disabled = true;
	} else {
		button.textContent = button.dataset.label || button.textContent;
		button.disabled = false;
	}
}

const relativeTime = new Intl.RelativeTimeFormat( document.documentElement.lang || undefined, { numeric: 'auto' } );

export function when( iso ) {
	const seconds = ( new Date( iso ).getTime() - Date.now() ) / 1000;
	const units = [ [ 60, 'second' ], [ 3600, 'minute' ], [ 86400, 'hour' ], [ 604800, 'day' ], [ 2629800, 'week' ], [ 31557600, 'month' ], [ Infinity, 'year' ] ];
	const sizes = { second: 1, minute: 60, hour: 3600, day: 86400, week: 604800, month: 2629800, year: 31557600 };
	for ( const [ limit, unit ] of units ) {
		if ( Math.abs( seconds ) < limit ) {
			return relativeTime.format( Math.round( seconds / sizes[ unit ] ), unit );
		}
	}
	return '';
}

export const canOpenAdmin = () => Boolean( config.adminUrl );
