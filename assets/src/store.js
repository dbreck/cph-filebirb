/**
 * Shared folder store. One instance per page, so every mounted sidebar
 * (Media Library page now, `wp.media` modals later) sees the same tree.
 */
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

import * as api from './api';
import {
	ALL,
	UNCATEGORIZED,
	normalizeNode,
	findNode,
	indexTree,
	updateNode,
	removeNode,
	removeNodeKeepChildren,
	insertNode,
	moveNode,
	applyCounts,
	childrenOf,
	uniqueName,
} from './tree';
import { storage } from './storage';

const data = window.cphfbData || {};

const listeners = new Set();
const handlers = {};

let state = {
	tree: ( data.tree || [] ).map( normalizeNode ),
	counts: data.counts || null,
	selected: ALL,
	collapsed: new Set( ( data.userSettings?.collapsed || [] ).map( Number ) ),
	sort: storage.get( 'sort', 'custom' ),
	editing: null,
	notice: null,
	loading: false,
};
if ( state.counts ) {
	state.tree = applyCounts( state.tree, state.counts );
}

export const getState = () => state;

export function subscribe( listener ) {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

function setState( patch ) {
	state = { ...state, ...( typeof patch === 'function' ? patch( state ) : patch ) };
	listeners.forEach( ( listener ) => listener() );
}

/* Events ------------------------------------------------------------------ */

export function on( event, callback ) {
	( handlers[ event ] ||= new Set() ).add( callback );
	return () => handlers[ event ].delete( callback );
}

export function emit( event, ...args ) {
	( handlers[ event ] || [] ).forEach( ( callback ) => {
		try {
			callback( ...args );
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( error );
		}
	} );
}

/* Persistence ------------------------------------------------------------- */

let pendingSettings = {};
let settingsTimer = null;

/**
 * Queue a user-settings write; several calls in a row become one request.
 *
 * @param {Object} patch Partial user settings.
 */
export function persistUserSettings( patch ) {
	pendingSettings = { ...pendingSettings, ...patch };
	clearTimeout( settingsTimer );
	settingsTimer = setTimeout( () => {
		const body = pendingSettings;
		pendingSettings = {};
		api.saveUserSettings( body ).catch( () => {} );
	}, 600 );
}

/* Notices ----------------------------------------------------------------- */

let noticeTimer = null;

export function notify( message, type = 'error' ) {
	clearTimeout( noticeTimer );
	setState( { notice: message ? { message, type } : null } );
	if ( message ) {
		speak( message, type === 'error' ? 'assertive' : 'polite' );
		noticeTimer = setTimeout( () => setState( { notice: null } ), 6000 );
	}
}

/* Selection --------------------------------------------------------------- */

export function folderExists( id ) {
	return id === ALL || id === UNCATEGORIZED || !! findNode( state.tree, id );
}

export function folderLabel( id ) {
	if ( id === ALL ) {
		return __( 'All files', 'cph-filebird' );
	}
	if ( id === UNCATEGORIZED ) {
		return __( 'Uncategorized', 'cph-filebird' );
	}
	const node = findNode( state.tree, id );
	return node ? node.name : '';
}

/**
 * Select a folder: updates state, persists, and tells the media wiring.
 *
 * @param {number}  id              Folder id (-1 all, 0 uncategorized).
 * @param {Object}  options         Options.
 * @param {boolean} options.silent  Skip the `select` event (initial restore).
 * @param {boolean} options.persist Write to localStorage and user settings.
 */
export function selectFolder( id, { silent = false, persist = true } = {} ) {
	id = Number( id );
	if ( Number.isNaN( id ) || ! folderExists( id ) ) {
		id = ALL;
	}
	const changed = id !== state.selected;
	const index = indexTree( state.tree );
	// Reveal the selection.
	const ancestors = [];
	let entry = index.get( id );
	while ( entry && entry.parent ) {
		ancestors.push( entry.parent );
		entry = index.get( entry.parent );
	}
	if ( ancestors.some( ( a ) => state.collapsed.has( a ) ) ) {
		const collapsed = new Set( state.collapsed );
		ancestors.forEach( ( a ) => collapsed.delete( a ) );
		setState( { collapsed } );
	}
	setState( { selected: id } );
	if ( persist ) {
		storage.set( 'selected', id );
		persistUserSettings( { selected_folder: id } );
	}
	if ( changed && ! silent ) {
		emit( 'select', id );
	}
}

/* Expand / collapse ------------------------------------------------------- */

export function setExpanded( id, expanded ) {
	const collapsed = new Set( state.collapsed );
	if ( expanded ) {
		collapsed.delete( id );
	} else {
		collapsed.add( id );
	}
	setState( { collapsed } );
	persistUserSettings( { collapsed: [ ...collapsed ] } );
}

export function setSort( sort ) {
	storage.set( 'sort', sort );
	setState( { sort } );
}

export function setEditing( editing ) {
	setState( { editing } );
}

/* Data -------------------------------------------------------------------- */

function setCounts( counts ) {
	if ( ! counts ) {
		return;
	}
	setState( ( s ) => ( { counts, tree: applyCounts( s.tree, counts ) } ) );
	emit( 'counts', counts );
}

export async function refresh() {
	setState( { loading: true } );
	try {
		const res = await api.getFolders( true );
		const tree = ( res.tree || [] ).map( normalizeNode );
		setState( {
			tree: applyCounts( tree, res.counts ),
			counts: res.counts || state.counts,
		} );
		if ( ! folderExists( state.selected ) ) {
			selectFolder( ALL );
		}
		emit( 'change', state.tree );
	} catch ( error ) {
		notify( api.errorMessage( error ) );
	} finally {
		setState( { loading: false } );
	}
}

let countsTimer = null;

/**
 * Debounced counts reload (after uploads, deletes, assignments).
 */
export function refreshCounts() {
	clearTimeout( countsTimer );
	countsTimer = setTimeout( async () => {
		try {
			setCounts( await api.getCounts() );
		} catch ( error ) {}
	}, 400 );
}

/**
 * Create a folder and put it straight into rename mode.
 *
 * @param {number} parent Parent id (0 = root).
 * @return {Promise<Object|null>} New node.
 */
export async function createFolder( parent = 0 ) {
	parent = parent > 0 ? parent : 0;
	const name = uniqueName( childrenOf( state.tree, parent ), __( 'New folder', 'cph-filebird' ) );
	try {
		const node = normalizeNode( await api.createFolder( name, parent ) );
		setState( ( s ) => ( { tree: insertNode( s.tree, parent, node ) } ) );
		if ( parent ) {
			setExpanded( parent, true );
		}
		setEditing( { id: node.id, isNew: true } );
		speak( sprintf( /* translators: %s: folder name */ __( 'Folder %s created.', 'cph-filebird' ), node.name ) );
		emit( 'change', state.tree );
		return node;
	} catch ( error ) {
		notify( api.errorMessage( error ) );
		return null;
	}
}

/**
 * Rename. Resolves to `true`, or to an error message to show inline.
 *
 * @param {number} id   Folder id.
 * @param {string} name New name.
 * @return {Promise<true|string>} Result.
 */
export async function renameFolder( id, name ) {
	name = name.trim();
	const node = findNode( state.tree, id );
	if ( ! node ) {
		return true;
	}
	if ( ! name ) {
		return __( 'A folder name is required.', 'cph-filebird' );
	}
	if ( name === node.name ) {
		return true;
	}
	const before = state.tree;
	setState( { tree: updateNode( state.tree, id, { name } ) } );
	try {
		const saved = await api.updateFolder( id, { name } );
		setState( ( s ) => ( { tree: updateNode( s.tree, id, { name: saved.name ?? name } ) } ) );
		speak( sprintf( /* translators: %s: folder name */ __( 'Folder renamed to %s.', 'cph-filebird' ), saved.name ?? name ) );
		emit( 'change', state.tree );
		return true;
	} catch ( error ) {
		setState( { tree: before } );
		if ( error?.code === 'folder_name_exists' || error?.data?.status === 409 ) {
			return __( 'A folder with this name already exists here.', 'cph-filebird' );
		}
		return api.errorMessage( error );
	}
}

export async function setColor( id, color ) {
	const before = state.tree;
	setState( { tree: updateNode( state.tree, id, { color } ) } );
	try {
		await api.updateFolder( id, { color } );
		speak( color ? __( 'Folder color set.', 'cph-filebird' ) : __( 'Folder color cleared.', 'cph-filebird' ) );
		emit( 'change', state.tree );
	} catch ( error ) {
		setState( { tree: before } );
		notify( api.errorMessage( error ) );
	}
}

export async function duplicateFolder( id ) {
	const node = findNode( state.tree, id );
	try {
		const copy = normalizeNode( await api.duplicateFolder( id ) );
		const siblings = childrenOf( state.tree, copy.parent );
		const at = siblings.findIndex( ( s ) => s.id === id );
		setState( ( s ) => ( {
			tree: insertNode( s.tree, copy.parent, copy, at < 0 ? Infinity : at + 1 ),
		} ) );
		speak( sprintf( /* translators: %s: folder name */ __( 'Folder %s duplicated.', 'cph-filebird' ), node?.name || '' ) );
		emit( 'change', state.tree );
		refreshCounts();
		return copy;
	} catch ( error ) {
		notify( api.errorMessage( error ) );
		return null;
	}
}

export async function deleteFolder( id, mode = 'subtree' ) {
	const node = findNode( state.tree, id );
	const before = state.tree;
	const index = indexTree( state.tree );
	setState( {
		tree: mode === 'children-up' ? removeNodeKeepChildren( state.tree, id ) : removeNode( state.tree, id ),
	} );
	const selectedGone =
		state.selected === id ||
		( mode === 'subtree' && index.has( state.selected ) && ! findNode( state.tree, state.selected ) );
	try {
		const res = await api.deleteFolder( id, mode );
		if ( res?.counts ) {
			setCounts( res.counts );
		}
		if ( selectedGone ) {
			selectFolder( ALL );
		}
		speak( sprintf( /* translators: %s: folder name */ __( 'Folder %s deleted.', 'cph-filebird' ), node?.name || '' ) );
		emit( 'change', state.tree );
		emit( 'delete', id );
		return true;
	} catch ( error ) {
		setState( { tree: before } );
		notify( api.errorMessage( error ) );
		return false;
	}
}

/**
 * Optimistically move a folder, then persist sibling order. Rolls back on error.
 *
 * @param {number} id     Folder id.
 * @param {number} parent New parent (0 = root).
 * @param {number} index  Position among new siblings.
 */
export async function moveFolder( id, parent, index ) {
	const before = state.tree;
	const { tree, items } = moveNode( state.tree, id, parent, index );
	if ( ! items.length ) {
		return;
	}
	setState( { tree } );
	if ( parent ) {
		setExpanded( parent, true );
	}
	try {
		const res = await api.saveOrder( items );
		if ( res?.tree ) {
			// The server may rename on a collision; take its names.
			const fresh = indexTree( res.tree.map( normalizeNode ) );
			setState( ( s ) => ( {
				tree: applyCounts(
					s.tree.length ? mapNames( s.tree, fresh ) : s.tree,
					s.counts
				),
			} ) );
		}
		const node = findNode( state.tree, id );
		speak( sprintf( /* translators: 1: folder name, 2: parent folder name */ __( 'Moved %1$s into %2$s.', 'cph-filebird' ), node?.name || '', parent ? folderLabel( parent ) : __( 'the top level', 'cph-filebird' ) ) );
		emit( 'change', state.tree );
		refreshCounts();
	} catch ( error ) {
		setState( { tree: before } );
		notify( api.errorMessage( error ) );
	}
}

function mapNames( tree, fresh ) {
	return tree.map( ( node ) => ( {
		...node,
		name: fresh.get( node.id )?.node.name ?? node.name,
		children: mapNames( node.children, fresh ),
	} ) );
}

/**
 * Assign attachments to a folder (stage B drag-and-drop uses this).
 *
 * @param {number}   folder Folder id (0 = unassign).
 * @param {number[]} ids    Attachment ids.
 */
export async function assignToFolder( folder, ids ) {
	try {
		const res = await api.assign( folder, ids );
		if ( res?.counts ) {
			setCounts( res.counts );
		}
		emit( 'assign', folder, res?.assigned || ids );
		return res;
	} catch ( error ) {
		notify( api.errorMessage( error ) );
		return null;
	}
}
