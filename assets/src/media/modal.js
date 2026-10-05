/**
 * Folder column in every `wp.media` frame that browses a library.
 *
 * Extension point: `wp.media.view.AttachmentsBrowser.prototype.initialize`
 * and `.dispose`. Every frame type (Select, Post, ImageDetails' replace,
 * Gutenberg's media-utils frames, WPBakery/Salient/ACF pickers) builds its
 * library browse content with this view, one instance per browse render, tied
 * to that state's library collection. So one wrapper covers them all, and the
 * mount lives and dies with the view. A light observer on <body> catches
 * browsers created before our hook was installed.
 */
import { createRoot } from '@wordpress/element';

import { ALL } from '../tree';
import * as store from '../store';
import FrameSidebar from '../components/FrameSidebar';
import { setFrameFolder } from './context';
import { registerBrowser } from './dnd';
import { filterLibrary } from './move';

const MARK = '__cphfbTree';
const browserCallbacks = new Set();

/**
 * Run a callback for every attachments browser built from now on (the grid uses this).
 *
 * @param {Function} callback Receives the AttachmentsBrowser view.
 */
export function onBrowser( callback ) {
	browserCallbacks.add( callback );
}

/**
 * A browser gets a tree when it browses a query-backed library outside the
 * Media Library grid. Gallery/playlist edit browse their selection (no query).
 *
 * @param {Object} browser AttachmentsBrowser view.
 * @return {boolean} Eligible.
 */
function eligible( browser ) {
	const collection = browser.collection || browser.options?.collection;
	if ( ! collection?.props?.get?.( 'query' ) ) {
		return false;
	}
	return ! browser.controller?.isModeActive?.( 'grid' );
}

/**
 * Put the frame's folder into the library before the first query runs.
 *
 * @param {Object} browser AttachmentsBrowser view (pre-initialize).
 */
function preset( browser ) {
	const props = browser.collection.props;
	const current = props.get( 'fbv' );
	if ( current === undefined || current === null ) {
		const id = store.getModalFolder();
		if ( id !== ALL ) {
			props.set( { fbv: id } );
		}
	} else if ( ! store.folderExists( Number( current ) ) ) {
		props.set( { fbv: ALL } );
	}
	filterLibrary( browser.collection );
}

/**
 * Build the column for a browser. Safe to call twice.
 *
 * @param {Object} browser AttachmentsBrowser view.
 */
function attach( browser ) {
	if ( browser[ MARK ] ) {
		return;
	}
	const el = document.createElement( 'div' );
	el.className = 'cphfb-root cphfb-frame-tree';
	browser.el.insertBefore( el, browser.el.firstChild );
	browser.el.classList.add( 'cphfb-has-tree' );

	const controller = browser.controller;
	if ( controller?.el ) {
		const id = Number( browser.collection.props.get( 'fbv' ) ?? ALL );
		setFrameFolder( controller.el, Number.isNaN( id ) ? ALL : id );
	}

	let root = null;
	const mount = () => {
		if ( root || ! handle.alive ) {
			return;
		}
		if ( el.parentNode !== browser.el ) {
			browser.el.insertBefore( el, browser.el.firstChild );
		}
		root = createRoot( el );
		root.render( <FrameSidebar browser={ browser } /> );
	};
	const unmount = () => {
		root?.unmount();
		root = null;
	};
	const handle = {
		alive: true,
		mount,
		unmount,
		destroy() {
			handle.alive = false;
			unmount();
			controller?.off?.( 'open', mount );
			controller?.off?.( 'close', unmount );
			el.remove();
			browser.el.classList.remove( 'cphfb-has-tree', 'cphfb-tree-rail', 'cphfb-tree-overlay' );
		},
	};
	browser[ MARK ] = handle;

	// Rendering a wp.Backbone view replaces its children (`$el.html( subviews )`).
	const render = browser.render;
	browser.render = function ( ...args ) {
		const result = render.apply( this, args );
		if ( handle.alive && el.parentNode !== browser.el ) {
			browser.el.insertBefore( el, browser.el.firstChild );
		}
		return result;
	};

	// Closed modals give their React root back; reopening remounts.
	controller?.on?.( 'open', mount );
	controller?.on?.( 'close', unmount );
	mount();
}

/**
 * Fallback for browsers our prototype hook did not see: resolve the view
 * through the current frame and attach.
 */
function scan() {
	const frame = window.wp?.media?.frame;
	const view = frame?.content?.get?.();
	if ( view && view.el?.classList.contains( 'attachments-browser' ) && ! view[ MARK ] && eligible( view ) ) {
		preset( view );
		attach( view );
	}
}

export function installModals( start ) {
	const Browser = window.wp?.media?.view?.AttachmentsBrowser;
	if ( ! Browser || Browser.prototype.__cphfb ) {
		return;
	}
	Browser.prototype.__cphfb = true;

	const initialize = Browser.prototype.initialize;
	Browser.prototype.initialize = function ( ...args ) {
		const use = eligible( this );
		if ( use ) {
			start();
			preset( this );
		}
		const result = initialize.apply( this, args );
		registerBrowser( this );
		browserCallbacks.forEach( ( callback ) => callback( this ) );
		if ( use ) {
			try {
				attach( this );
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( error );
			}
		}
		return result;
	};

	const dispose = Browser.prototype.dispose;
	Browser.prototype.dispose = function ( ...args ) {
		this[ MARK ]?.destroy();
		return dispose.apply( this, args );
	};

	let pending = 0;
	const observer = new window.MutationObserver( () => {
		if ( ! pending ) {
			pending = window.requestAnimationFrame( () => {
				pending = 0;
				if ( document.querySelector( '.media-modal .attachments-browser:not(.cphfb-has-tree)' ) ) {
					start();
					scan();
				}
			} );
		}
	} );
	const observe = () => observer.observe( document.body, { childList: true } );
	if ( document.body ) {
		observe();
	} else {
		document.addEventListener( 'DOMContentLoaded', observe );
	}
}
