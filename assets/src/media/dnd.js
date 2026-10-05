/**
 * Drag attachments from a media library onto a folder row.
 *
 * Native HTML5 drag and drop with delegated listeners on window (capture
 * phase), so Backbone re-renders never matter. During our own drags the
 * events are stopped before core's uploader dropzone sees them; otherwise its
 * full-window "Drop files to upload" overlay would cover the tree.
 */
import { _n, sprintf } from '@wordpress/i18n';

import * as store from '../store';
import { idsOf, moveAttachments } from './move';

const TYPE = 'application/x-cphfb-attachments';
const VIEW = '__cphfbView';
const EXPAND_DELAY = 650;

let drag = null; // { ids, over: Element|null, expand: { id, timer } }

/**
 * The browser view an attachment element belongs to, when dragging from it
 * is allowed: the Media Library grid, or a modal browser that has our tree.
 * Enabled jQuery UI sortables (gallery edit, menu-order libraries) are left alone.
 *
 * @param {Element} item `.attachment` element.
 * @return {Object|null} AttachmentsBrowser view.
 */
function sourceView( item ) {
	const list = item.closest( 'ul.attachments' );
	if ( ! list || ( list.classList.contains( 'ui-sortable' ) && ! list.classList.contains( 'ui-sortable-disabled' ) ) ) {
		return null;
	}
	const browserEl = item.closest( '.attachments-browser' );
	const view = browserEl?.[ VIEW ];
	if ( ! view ) {
		return null;
	}
	const grid = view.controller?.isModeActive?.( 'grid' );
	return grid || browserEl.classList.contains( 'cphfb-has-tree' ) ? view : null;
}

/**
 * Remember which view owns a browser element (called for every browser).
 *
 * @param {Object} view AttachmentsBrowser view.
 */
export function registerBrowser( view ) {
	if ( view?.el ) {
		view.el[ VIEW ] = view;
	}
}

/**
 * Attachment id of an item. Items rendered while uploading carry no
 * `data-id`, so fall back to the Attachments view's own model lookup.
 *
 * @param {Element} item `.attachment` element.
 * @param {Object}  view AttachmentsBrowser view.
 * @return {number} Id or 0.
 */
function idOf( item, view ) {
	const id = Number( item.dataset.id );
	if ( id ) {
		return id;
	}
	const views = Object.values( view.attachments?._viewsByCid || {} );
	return Number( views.find( ( v ) => v.el === item )?.model?.get( 'id' ) ) || 0;
}

function dragImage( count ) {
	const ghost = document.createElement( 'div' );
	ghost.className = 'cphfb-drag-ghost';
	ghost.textContent = sprintf(
		/* translators: %s: number of files */
		_n( '%s file', '%s files', count, 'cph-filebirb' ),
		count.toLocaleString()
	);
	document.body.appendChild( ghost );
	setTimeout( () => ghost.remove() );
	return ghost;
}

function rowFor( target ) {
	const row = target?.closest?.( '.cphfb-tree [data-folder-id]' );
	if ( ! row ) {
		return null;
	}
	return Number( row.dataset.folderId ) >= 0 ? row : null;
}

function setOver( row ) {
	if ( drag.over === row ) {
		return;
	}
	drag.over?.removeAttribute( 'data-cphfb-drop' );
	drag.over = row;
	row?.setAttribute( 'data-cphfb-drop', '' );

	// Hovering a collapsed folder opens it.
	const id = row && row.getAttribute( 'aria-expanded' ) === 'false' ? Number( row.dataset.folderId ) : null;
	if ( drag.expand?.id !== id ) {
		clearTimeout( drag.expand?.timer );
		drag.expand = id ? { id, timer: setTimeout( () => store.setExpanded( id, true ), EXPAND_DELAY ) } : null;
	}
}

function end() {
	if ( ! drag ) {
		return;
	}
	clearTimeout( drag.expand?.timer );
	drag.over?.removeAttribute( 'data-cphfb-drop' );
	drag.items.forEach( ( el ) => el.classList.remove( 'cphfb-is-dragged' ) );
	document.body.classList.remove( 'cphfb-is-dragging-files' );
	drag = null;
}

function onPointerDown( event ) {
	const item = event.target?.closest?.( '.attachments .attachment' );
	if ( item && ! item.draggable && sourceView( item ) ) {
		item.draggable = true;
	}
}

function onDragStart( event ) {
	const item = event.target?.closest?.( '.attachments .attachment' );
	const view = item && sourceView( item );
	const id = view ? idOf( item, view ) : 0;
	if ( ! id || ! event.dataTransfer ) {
		return;
	}
	const selected = idsOf( view.options?.selection );
	const ids = selected.includes( id ) ? selected : [ id ];

	event.dataTransfer.effectAllowed = 'move';
	event.dataTransfer.setData( TYPE, JSON.stringify( ids ) );
	if ( event.dataTransfer.setDragImage ) {
		event.dataTransfer.setDragImage( dragImage( ids.length ), 18, 18 );
	}

	const scope = view.el;
	const items = [ ...scope.querySelectorAll( '.attachments .attachment' ) ].filter( ( el ) => ids.includes( idOf( el, view ) ) );
	items.forEach( ( el ) => el.classList.add( 'cphfb-is-dragged' ) );
	document.body.classList.add( 'cphfb-is-dragging-files' );
	drag = { ids, items, over: null, expand: null };
}

function onDragOver( event ) {
	if ( ! drag ) {
		return;
	}
	// Keep core's uploader dropzone (and plupload) out of internal drags.
	event.stopPropagation();
	const row = rowFor( event.target );
	setOver( row );
	if ( row ) {
		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';
	} else if ( event.dataTransfer ) {
		event.dataTransfer.dropEffect = 'none';
	}
}

function onDragLeave( event ) {
	if ( ! drag ) {
		return;
	}
	event.stopPropagation();
	if ( ! event.relatedTarget ) {
		setOver( null );
	}
}

function onDrop( event ) {
	if ( ! drag ) {
		return;
	}
	event.stopPropagation();
	const row = rowFor( event.target );
	const { ids } = drag;
	end();
	if ( ! row ) {
		return;
	}
	event.preventDefault();
	moveAttachments( Number( row.dataset.folderId ), ids );
}

export function installAttachmentDnd() {
	if ( window.__cphfbDnd ) {
		return;
	}
	window.__cphfbDnd = true;
	window.addEventListener( 'pointerdown', onPointerDown, true );
	window.addEventListener( 'dragstart', onDragStart, true );
	window.addEventListener( 'dragenter', onDragOver, true );
	window.addEventListener( 'dragover', onDragOver, true );
	window.addEventListener( 'dragleave', onDragLeave, true );
	window.addEventListener( 'drop', onDrop, true );
	window.addEventListener( 'dragend', end, true );
}
