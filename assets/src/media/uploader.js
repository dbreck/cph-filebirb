/**
 * Upload routing: every plupload upload carries the target folder, and every
 * uploader UI shows "Upload to: [folder]".
 *
 * Extension points: `wp.Uploader.prototype.init` (called once per instance
 * after plupload's postinit: grid, every modal frame's window uploader, any
 * plugin's own wp.Uploader), `wp.media.view.UploaderInline.prototype.render`
 * (the inline uploader template holds the `pre-upload-ui` placeholder), and the
 * global `uploader` of media-new.php (plupload/handlers.js).
 */
import { __ } from '@wordpress/i18n';

import * as store from '../store';
import { UNCATEGORIZED, flatten, sortTree } from '../tree';
import { contextFor, onRefreshPickers, uploadTarget } from './context';

const HEADER = 'X-CPHFB-Folder';
const PARAM = 'cphfb_folder';

/**
 * Stamp the target folder on a plupload instance right before each file goes.
 *
 * @param {Object}   up         plupload.Uploader.
 * @param {Function} getContext Returns the folder context for this uploader.
 */
function bindUploader( up, getContext ) {
	if ( ! up || up.__cphfb ) {
		return;
	}
	up.__cphfb = true;
	up.bind( 'BeforeUpload', ( uploader ) => {
		const target = String( uploadTarget( getContext() ) );
		uploader.settings.multipart_params = { ...( uploader.settings.multipart_params || {} ), [ PARAM ]: target };
		uploader.settings.headers = { ...( uploader.settings.headers || {} ), [ HEADER ]: target };
	} );
}

/* Pickers ------------------------------------------------------------------ */

function fillOptions( select ) {
	const { tree, sort } = store.getState();
	const options = [ [ UNCATEGORIZED, __( 'Uncategorized (no folder)', 'cph-filebird' ) ] ];
	flatten( sortTree( tree, sort ), new Set(), true ).forEach( ( row ) => {
		options.push( [ row.node.id, '   '.repeat( row.depth ) + row.node.name ] );
	} );
	const key = options.map( ( o ) => o.join( ':' ) ).join( '|' );
	if ( select.__cphfbKey === key ) {
		return;
	}
	select.__cphfbKey = key;
	select.textContent = '';
	options.forEach( ( [ value, text ] ) => select.appendChild( new window.Option( text, String( value ) ) ) );
}

// The holder remembers its frame: inline uploaders render before they are attached.
const scopeOf = ( select ) => select.parentNode?.__cphfbScope || select;

function syncPicker( select ) {
	fillOptions( select );
	select.value = String( uploadTarget( contextFor( scopeOf( select ) ) ) );
}

let pickers = 0;

/**
 * Fill a `.cphfb-upload-folder` placeholder with the picker (idempotent).
 *
 * @param {Element} holder Placeholder printed on `pre-upload-ui`.
 * @param {Element} scope  Element to resolve the folder context from (the frame).
 */
export function renderPicker( holder, scope = null ) {
	if ( ! holder || holder.querySelector( 'select' ) ) {
		return;
	}
	holder.__cphfbScope = scope;
	const id = 'cphfb-upload-folder-' + ++pickers;
	holder.hidden = false;
	holder.classList.add( 'cphfb-root' );

	const label = document.createElement( 'label' );
	label.htmlFor = id;
	label.className = 'cphfb-upload-folder__label';
	label.textContent = __( 'Upload to:', 'cph-filebird' );

	const select = document.createElement( 'select' );
	select.id = id;
	// Also posts with media-new.php's plain browser-uploader form.
	select.name = PARAM;
	select.className = 'cphfb-upload-folder__select';

	holder.append( label, select );
	syncPicker( select );

	// Options are rebuilt lazily, so pickers hold no store subscription.
	const refresh = () => fillOptions( select );
	select.addEventListener( 'focus', refresh );
	select.addEventListener( 'mousedown', refresh );
	select.addEventListener( 'change', () => {
		contextFor( scopeOf( select ) ).override = Number( select.value );
	} );
}

function refreshPickers( scope ) {
	scope.querySelectorAll?.( '.cphfb-upload-folder select' ).forEach( syncPicker );
}

/* Install ------------------------------------------------------------------ */

export function installUploader( start ) {
	onRefreshPickers( refreshPickers );

	const Uploader = window.wp?.Uploader;
	if ( Uploader && ! Uploader.prototype.__cphfb ) {
		Uploader.prototype.__cphfb = true;
		const init = Uploader.prototype.init;
		Uploader.prototype.init = function ( ...args ) {
			start();
			const container = this.container?.[ 0 ] || this.dropzone?.[ 0 ] || null;
			bindUploader( this.uploader, () => contextFor( container ) );
			return init.apply( this, args );
		};
		// Uploads finished: the queue resets once all files are done.
		Uploader.queue?.on?.( 'reset', () => store.refreshCounts() );
	}

	const Inline = window.wp?.media?.view?.UploaderInline;
	if ( Inline && ! Inline.prototype.__cphfb ) {
		Inline.prototype.__cphfb = true;
		const render = Inline.prototype.render;
		Inline.prototype.render = function ( ...args ) {
			const result = render.apply( this, args );
			start();
			const scope = this.controller?.el || null;
			this.el.querySelectorAll( '.cphfb-upload-folder' ).forEach( ( holder ) => renderPicker( holder, scope ) );
			return result;
		};
	}

	// Pickers already in the page (media-new.php, or an uploader rendered early).
	const fillStatic = () => {
		document.querySelectorAll( '.cphfb-upload-folder' ).forEach( ( holder ) => {
			// Templates (`<script type="text/html">`) are not in the DOM tree; real holders are.
			start();
			renderPicker( holder, null );
		} );
		// media-new.php: plupload/handlers.js keeps its instance in a global.
		if ( window.uploader && typeof window.uploader.bind === 'function' ) {
			start();
			bindUploader( window.uploader, () => contextFor( document.getElementById( 'plupload-upload-ui' ) ) );
			window.uploader.bind( 'UploadComplete', () => store.refreshCounts() );
		}
	};
	if ( document.readyState === 'complete' ) {
		fillStatic();
	} else {
		window.addEventListener( 'load', fillStatic );
	}

	store.on( 'change', () => refreshPickers( document ) );
}
