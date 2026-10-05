/**
 * cph-filebird admin entry: folder sidebar for the Media Library, folder
 * column in every `wp.media` frame, upload routing, attachment drag to folder.
 *
 * Nothing heavy runs on load: prototype hooks only. React mounts when a
 * library browser is built (or on upload.php).
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import './index.scss';

import * as store from './store';
import { storage } from './storage';
import { ALL } from './tree';
import Sidebar from './components/Sidebar';
import { clampWidth } from './components/ResizeHandle';
import { installGrid, requery, getLibrary, gridSelection } from './media/grid';
import { installList } from './media/list';
import { installModals } from './media/modal';
import { installUploader } from './media/uploader';
import { installAttachmentDnd } from './media/dnd';
import { installCompat } from './media/compat';
import { contextFor, pageFolderChanged, setPageFolderGetter, uploadTarget } from './media/context';
import { installQueryHook, moveAttachments } from './media/move';
import { openFolderPicker } from './components/FolderPicker';

const data = window.cphfbData || {};
const mode = data.screen?.mode || '';

/**
 * Folder to open on load: URL, then this browser, then the user's last choice.
 *
 * @return {number} Folder id.
 */
function initialFolder() {
	const fromUrl = new URLSearchParams( window.location.search ).get( 'fbv' );
	if ( fromUrl !== null && fromUrl !== '' && ! Number.isNaN( Number( fromUrl ) ) ) {
		return Number( fromUrl );
	}
	if ( mode === 'list' ) {
		return ALL;
	}
	const local = storage.get( 'selected', null );
	if ( local !== null ) {
		return Number( local );
	}
	return Number( data.userSettings?.selected_folder ?? ALL );
}

let started = false;
function start() {
	if ( started ) {
		return;
	}
	started = true;
	store.selectFolder( initialFolder(), { silent: true } );
}

/* Mounting ---------------------------------------------------------------- */

const mounts = new Set();

/**
 * Mount a sidebar into a container. Can be called for any number of containers.
 *
 * @param {Element} container          Target element.
 * @param {Object}  options            Options.
 * @param {boolean} options.layout     Page layout: resizable, collapsible to a rail.
 * @param {boolean} options.keepAlive  Re-attach the container if something removes it.
 * @return {{unmount: Function, container: Element}} Handle.
 */
function mount( container, options = {} ) {
	start();
	container.classList.add( 'cphfb-root' );
	container.innerHTML = '';
	const root = createRoot( container );
	root.render( <Sidebar { ...options } /> );

	let observer = null;
	if ( options.keepAlive && container.parentNode ) {
		const parent = container.parentNode;
		const next = container.nextSibling;
		observer = new window.MutationObserver( () => {
			if ( ! container.isConnected && parent.isConnected ) {
				parent.insertBefore( container, next && next.parentNode === parent ? next : parent.firstChild );
			}
		} );
		observer.observe( parent, { childList: true } );
	}

	const handle = {
		container,
		unmount() {
			observer?.disconnect();
			root.unmount();
			mounts.delete( handle );
		},
	};
	mounts.add( handle );
	return handle;
}

/* Page layout (upload.php) ------------------------------------------------ */

function mountPage() {
	let el = document.getElementById( 'cphfb-root' );
	if ( ! el ) {
		const wpbody = document.getElementById( 'wpbody' );
		if ( ! wpbody ) {
			return;
		}
		el = document.createElement( 'div' );
		el.id = 'cphfb-root';
		wpbody.insertBefore( el, wpbody.firstChild );
	}
	const body = document.body;
	body.classList.add( 'cphfb-has-sidebar' );

	let saveTimer = null;
	mount( el, {
		layout: true,
		keepAlive: true,
		initialWidth: clampWidth( data.userSettings?.sidebar_width || storage.get( 'width', 0 ) ),
		initialRail: body.classList.contains( 'cphfb-is-rail' ),
		selectionSource: mode === 'grid' ? gridSelection : undefined,
		onWidth( width, commit ) {
			body.style.setProperty( '--cphfb-width', width + 'px' );
			if ( commit ) {
				storage.set( 'width', width );
				clearTimeout( saveTimer );
				saveTimer = setTimeout( () => store.persistUserSettings( { sidebar_width: width } ), 200 );
			}
		},
		onRail( rail ) {
			body.classList.toggle( 'cphfb-is-rail', rail );
			storage.set( 'rail', rail );
		},
	} );
}

/* Public API -------------------------------------------------------------- */

window.cphfb = {
	getSelectedFolder: () => store.getState().selected,
	selectFolder: ( id ) => store.selectFolder( id ),
	refresh: () => store.refresh(),
	refreshCounts: () => store.refreshCounts(),
	mount,
	on: store.on,
	getState: store.getState,
	subscribe: store.subscribe,
	assign: store.assignToFolder,
	requery,
	getLibrary,
	// Stage B.
	moveAttachments: ( folder, ids, options ) => moveAttachments( folder, ids, options ),
	openFolderPicker,
	uploadTarget: ( el ) => uploadTarget( contextFor( el || document.body ) ),
};

installQueryHook();
installModals( start );
installUploader( start );
installAttachmentDnd();
installCompat();

if ( mode === 'grid' ) {
	setPageFolderGetter( () => store.getState().selected );
	store.on( 'select', pageFolderChanged );
	installGrid( () => {
		start();
		return store.getState().selected;
	} );
} else if ( mode === 'list' ) {
	start();
	installList();
}

domReady( () => {
	if ( data.screen?.id === 'upload' ) {
		mountPage();
	}
} );
