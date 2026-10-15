import { fetchSearch } from './data.js';
import { tf } from './i18n.js';

const STRINGS = { nickname: tf( 'nickname' ) };

const DEBOUNCE_MS = 250;

/** Server-side name search (scales past a few dozen people, unlike filtering a full fetch client-side). */
export function initSearch( { inputEl, resultsEl, onSelect } ) {
	let debounceTimer;

	inputEl.addEventListener( 'input', () => {
		clearTimeout( debounceTimer );
		const query = inputEl.value.trim();
		if ( ! query ) {
			resultsEl.hidden = true;
			resultsEl.innerHTML = '';
			return;
		}
		debounceTimer = setTimeout( () => runSearch( query ), DEBOUNCE_MS );
	} );

	function runSearch( query ) {
		fetchSearch( query ).then( ( matches ) => {
			resultsEl.innerHTML = '';
			if ( ! matches.length ) {
				resultsEl.hidden = true;
				return;
			}
			matches.forEach( ( person ) => {
				const li = document.createElement( 'li' );
				li.className = 'gnr-search__result';
				li.textContent = [
					person.name,
					person.nickname ? STRINGS.nickname( person.nickname ) : '',
					person.name_latin ? `(${ person.name_latin })` : '',
				].filter( Boolean ).join( ' ' );
				li.addEventListener( 'click', () => {
					resultsEl.hidden = true;
					inputEl.value = '';
					if ( onSelect ) {
						onSelect( person.id );
					}
				} );
				resultsEl.appendChild( li );
			} );
			resultsEl.hidden = false;
		} );
	}

	document.addEventListener( 'click', ( event ) => {
		if ( event.target !== inputEl && ! resultsEl.contains( event.target ) ) {
			resultsEl.hidden = true;
		}
	} );
}
