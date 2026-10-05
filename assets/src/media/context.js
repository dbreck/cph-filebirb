/**
 * Folder context per `wp.media` frame: which folder the frame is showing and
 * where its uploads go. The page (Media Library grid, media-new.php) has one
 * context of its own; each modal frame gets one on its frame element.
 */
import { ALL } from '../tree';
import * as store from '../store';

const data = window.cphfbData || {};
const KEY = '__cphfbContext';

/**
 * @typedef {Object} FolderContext
 * @property {Function}    getFolder Folder currently shown (-1 all, 0 uncategorized).
 * @property {number|null} override  Folder picked in an uploader's "Upload to" select.
 */

/** @type {FolderContext} */
const pageContext = { getFolder: () => ALL, override: null };

export function setPageFolderGetter( getFolder ) {
	pageContext.getFolder = getFolder;
}

/**
 * The context for an element: its modal frame's, else the page's.
 *
 * @param {Element|null} el Any element (uploader container, picker...).
 * @return {FolderContext} Context.
 */
export function contextFor( el ) {
	const frame = el?.closest?.( '.media-frame:not(.mode-grid)' );
	if ( ! frame ) {
		return pageContext;
	}
	if ( ! frame[ KEY ] ) {
		const folder = store.getModalFolder();
		frame[ KEY ] = { folder, getFolder: () => frame[ KEY ].folder, override: null };
	}
	return frame[ KEY ];
}

/**
 * A modal frame changed folder: uploads follow it, pickers update.
 *
 * @param {Element} frameEl Frame element.
 * @param {number}  id      Folder id.
 */
export function setFrameFolder( frameEl, id ) {
	const context = contextFor( frameEl );
	context.folder = id;
	context.override = null;
	refreshPickers( frameEl );
}

/**
 * The page folder changed (grid sidebar).
 */
export function pageFolderChanged() {
	pageContext.override = null;
	refreshPickers( document );
}

/**
 * Folder new uploads go to: the uploader's own choice, else the folder on
 * screen, else (All files) the user's default upload folder. 0 = no folder.
 *
 * @param {FolderContext} context Context.
 * @return {number} Folder id.
 */
export function uploadTarget( context ) {
	const pick = ( id ) => ( id >= 0 && store.folderExists( id ) ? id : null );
	return (
		pick( context.override ?? -1 ) ??
		pick( context.getFolder() ) ??
		pick( Number( data.userSettings?.default_upload_folder ?? -1 ) ) ??
		0
	);
}

/* Pickers are rendered by ./uploader; this just re-syncs their values. */
let refreshPickers = () => {};
export function onRefreshPickers( callback ) {
	refreshPickers = callback;
}
