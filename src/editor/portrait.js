// Portrait: take or choose a photo, crop it square (drag, pinch or slider to
// zoom, rotate a quarter turn), and upload only that square, 800 × 800 px
// JPEG. Phone photos are 3–8 MB; what is sent is usually 80–150 KB.
import { esc, t } from './env.js';
import { NetworkError, uploadPortrait } from './api.js';
import { busy, errorHtml, openSheet, setSheetBody, setSheetTitle } from './ui.js';

const OUT = 800; // px, square
const QUALITY = 0.85;
const WORK_MAX = 2000; // px: the copy shown while cropping, light enough for phones
const FULL_MAX = 4096; // px: the copy the square is cut from, at save

/**
 * @param {Object}   view    The person view (current portrait, id).
 * @param {Function} onSaved Called with the API answer.
 * @param {Function} onError Called with an error to show.
 */
export function editPortrait( view, onSaved, onError ) {
	const person = view.person;
	openSheet( { title: t( 'takePhoto' ) } );
	pickStep();

	function pickStep( message = '' ) {
		setSheetTitle( t( 'takePhoto' ) );
		const current = person.portrait_l || person.portrait;
		const body = setSheetBody( `
			<div class="gnr-ed-portrait">
				${ current ? `<img class="gnr-ed-portrait__current" src="${ esc( current ) }" alt="">` : '' }
				${ errorHtml( message ) }
				<label class="gnr-ed-button gnr-ed-portrait__choose">
					<input type="file" accept="image/*" hidden>
					${ esc( current ? t( 'replacePhoto' ) : t( 'choosePhoto' ) ) }
				</label>
				<p class="gnr-ed-field__hint">${ esc( t( 'photoHint' ) ) }</p>
			</div>` );
		body.querySelector( 'input[type="file"]' ).addEventListener( 'change', async ( event ) => {
			const file = event.target.files && event.target.files[ 0 ];
			if ( ! file ) {
				return;
			}
			try {
				cropStep( await workingCopy( file, 0 ), file );
			} catch ( e ) {
				pickStep( t( 'photoUnreadable' ) );
			}
		} );
	}

	/** Lets the person place the square. */
	function cropStep( source, file ) {
		const body = setSheetBody( `
			<div class="gnr-ed-crop">
				<div class="gnr-ed-crop__frame" tabindex="0" aria-label="${ esc( t( 'cropHint' ) ) }">
					<span class="gnr-ed-crop__circle" aria-hidden="true"></span>
				</div>
				<p class="gnr-ed-field__hint">${ esc( t( 'cropHint' ) ) }</p>
				<div class="gnr-ed-crop__tools">
					<label class="gnr-ed-crop__zoom">
						<span aria-hidden="true">−</span>
						<input type="range" min="1" max="4" step="0.01" value="1" aria-label="${ esc( t( 'zoom' ) ) }">
						<span aria-hidden="true">+</span>
					</label>
					<button type="button" class="gnr-ed-icon" data-rotate aria-label="${ esc( t( 'rotate' ) ) }" title="${ esc( t( 'rotate' ) ) }">↻</button>
				</div>
				<div class="gnr-ed-form-error"></div>
				<div class="gnr-ed-actions">
					<button type="button" class="gnr-ed-button gnr-ed-button--quiet" data-again>${ esc( t( 'choosePhoto' ) ) }</button>
					<button type="button" class="gnr-ed-button" data-save>${ esc( t( 'save' ) ) }</button>
				</div>
			</div>` );

		const frame = body.querySelector( '.gnr-ed-crop__frame' );
		const slider = body.querySelector( 'input[type="range"]' );
		let rotation = 0;
		let canvas = source;
		let size = 0; // frame width in CSS px
		let base = 1; // scale at which the image just covers the frame
		let zoom = 1;
		let x = 0;
		let y = 0;

		frame.appendChild( canvas );
		reset();

		function reset() {
			size = frame.clientWidth;
			if ( ! size ) {
				requestAnimationFrame( reset ); // not laid out yet
				return;
			}
			base = size / Math.min( canvas.width, canvas.height );
			zoom = 1;
			slider.value = '1';
			x = ( size - canvas.width * base ) / 2;
			y = ( size - canvas.height * base ) / 2;
			draw();
		}

		function scale() {
			return base * zoom;
		}

		function clamp() {
			const s = scale();
			x = Math.min( 0, Math.max( size - canvas.width * s, x ) );
			y = Math.min( 0, Math.max( size - canvas.height * s, y ) );
		}

		function draw() {
			clamp();
			const s = scale();
			canvas.style.width = `${ canvas.width * s }px`;
			canvas.style.height = `${ canvas.height * s }px`;
			canvas.style.transform = `translate(${ x }px, ${ y }px)`;
		}

		/** Zooms keeping the point (px, py) of the frame where it is. */
		function zoomTo( next, px = size / 2, py = size / 2 ) {
			const s = scale();
			const ix = ( px - x ) / s;
			const iy = ( py - y ) / s;
			zoom = Math.min( 4, Math.max( 1, next ) );
			x = px - ix * scale();
			y = py - iy * scale();
			slider.value = String( zoom );
			draw();
		}

		slider.addEventListener( 'input', () => zoomTo( Number( slider.value ) ) );

		// Drag with one finger or the mouse, pinch with two.
		const pointers = new Map();
		let pinch = null;
		frame.addEventListener( 'pointerdown', ( event ) => {
			frame.setPointerCapture( event.pointerId );
			pointers.set( event.pointerId, { x: event.clientX, y: event.clientY } );
			if ( pointers.size === 2 ) {
				const [ a, b ] = [ ...pointers.values() ];
				pinch = { distance: Math.hypot( a.x - b.x, a.y - b.y ), zoom };
			}
		} );
		frame.addEventListener( 'pointermove', ( event ) => {
			const last = pointers.get( event.pointerId );
			if ( ! last ) {
				return;
			}
			const now = { x: event.clientX, y: event.clientY };
			pointers.set( event.pointerId, now );
			if ( pointers.size === 1 ) {
				x += now.x - last.x;
				y += now.y - last.y;
				draw();
			} else if ( pinch && pointers.size === 2 ) {
				const [ a, b ] = [ ...pointers.values() ];
				const rect = frame.getBoundingClientRect();
				zoomTo(
					pinch.zoom * Math.hypot( a.x - b.x, a.y - b.y ) / pinch.distance,
					( a.x + b.x ) / 2 - rect.left,
					( a.y + b.y ) / 2 - rect.top
				);
			}
		} );
		const lift = ( event ) => {
			pointers.delete( event.pointerId );
			if ( pointers.size < 2 ) {
				pinch = null;
			}
		};
		frame.addEventListener( 'pointerup', lift );
		frame.addEventListener( 'pointercancel', lift );
		frame.addEventListener( 'wheel', ( event ) => {
			event.preventDefault();
			const rect = frame.getBoundingClientRect();
			zoomTo( zoom * ( event.deltaY < 0 ? 1.1 : 1 / 1.1 ), event.clientX - rect.left, event.clientY - rect.top );
		}, { passive: false } );
		frame.addEventListener( 'keydown', ( event ) => {
			const step = { ArrowLeft: [ 10, 0 ], ArrowRight: [ -10, 0 ], ArrowUp: [ 0, 10 ], ArrowDown: [ 0, -10 ] }[ event.key ];
			if ( step ) {
				event.preventDefault();
				x += step[ 0 ];
				y += step[ 1 ];
				draw();
			}
		} );

		body.querySelector( '[data-rotate]' ).addEventListener( 'click', async () => {
			rotation = ( rotation + 90 ) % 360;
			const next = await workingCopy( file, rotation );
			canvas.replaceWith( next );
			canvas = next;
			reset();
		} );
		body.querySelector( '[data-again]' ).addEventListener( 'click', () => pickStep() );
		body.querySelector( '[data-save]' ).addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			busy( button, true );
			button.textContent = t( 'uploading' );
			try {
				// Cut from the full-resolution photo, not the lighter copy on screen.
				const full = await workingCopy( file, rotation, FULL_MAX );
				const s = scale() / ( full.width / canvas.width );
				const side = size / s; // the square, in full-resolution pixels
				const out = Math.min( OUT, Math.round( side ) );
				const square = document.createElement( 'canvas' );
				square.width = out;
				square.height = out;
				const ctx = square.getContext( '2d' );
				ctx.imageSmoothingQuality = 'high';
				ctx.drawImage( full, -x / s, -y / s, side, side, 0, 0, out, out );
				const blob = await new Promise( ( resolve ) => square.toBlob( resolve, 'image/jpeg', QUALITY ) );
				onSaved( await uploadPortrait( person.id, blob ) );
			} catch ( error ) {
				busy( button, false );
				const message = error instanceof NetworkError ? t( 'offline' ) : ( error.message || t( 'error' ) );
				body.querySelector( '.gnr-ed-form-error' ).innerHTML = errorHtml( message );
				onError( error );
			}
		} );
	}
}

/**
 * The photo, turned upright (EXIF) and by `rotation` degrees, at most
 * `max` px on its long side, as a canvas.
 */
async function workingCopy( file, rotation, max = WORK_MAX ) {
	const bitmap = await createImageBitmap( file, { imageOrientation: 'from-image' } );
	const fit = Math.min( 1, max / Math.max( bitmap.width, bitmap.height ) );
	const w = Math.round( bitmap.width * fit );
	const h = Math.round( bitmap.height * fit );
	const turned = rotation === 90 || rotation === 270;
	const canvas = document.createElement( 'canvas' );
	canvas.className = 'gnr-ed-crop__image';
	canvas.width = turned ? h : w;
	canvas.height = turned ? w : h;
	const ctx = canvas.getContext( '2d' );
	ctx.translate( canvas.width / 2, canvas.height / 2 );
	ctx.rotate( ( rotation * Math.PI ) / 180 );
	ctx.drawImage( bitmap, -w / 2, -h / 2, w, h );
	bitmap.close();
	return canvas;
}
