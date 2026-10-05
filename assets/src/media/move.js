/**
 * Moving attachments between folders from the media views: assign, keep the
 * Backbone models and open libraries in step, announce, offer Undo.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

import * as store from '../store';
import { ALL, UNCATEGORIZED, indexTree, isDescendant } from '../tree';
import { toast } from '../toast';

const data = window.cphfbData || {};

const allModels = () => window.wp?.media?.model?.Attachments?.all;

/**
 * Current folder of a loaded attachment model, or NaN when unknown.
 *
 * @param {number} id Attachment id.
 * @return {number} Folder id.
 */
export function folderOf( id ) {
	const value = allModels()?.get( id )?.get( 'fbv' );
	return value === undefined || value === null ? NaN : Number( value );
}

/**
 * Write `fbv` / `folder_id` onto loaded models. Libraries carrying our
 * filter re-validate on `change` and drop (or regain) the item.
 *
 * @param {number[]} ids    Attachment ids.
 * @param {number}   folder Folder id.
 */
export function syncModels( ids, folder ) {
	const all = allModels();
	ids.forEach( ( id ) => all?.get( id )?.set( { fbv: folder, folder_id: folder } ) );
}

/**
 * Collection filter: keeps a folder-filtered library honest when items move
 * or uploads land elsewhere. Unknown `fbv` (still uploading) passes.
 *
 * @this {Object} wp.media.model.Attachments
 * @param {Object} attachment Attachment model.
 * @return {boolean} Whether it belongs.
 */
export function folderFilter( attachment ) {
	const want = this.props.get( 'fbv' );
	if ( want === undefined || want === null || Number( want ) === ALL ) {
		return true;
	}
	const has = attachment.get( 'fbv' );
	if ( has === undefined || has === null ) {
		return true;
	}
	const folder = Number( want );
	const actual = Number( has );
	if ( actual === folder ) {
		return true;
	}
	if ( folder > 0 && actual > 0 && data.settings?.includeSubfolders ) {
		return isDescendant( indexTree( store.getState().tree ), actual, folder );
	}
	return false;
}

/**
 * Add our filter to a library collection (idempotent).
 *
 * @param {Object} library wp.media.model.Attachments.
 */
export function filterLibrary( library ) {
	if ( library?.filters && library.filters.cphfb !== folderFilter ) {
		library.filters.cphfb = folderFilter;
	}
}

const label = ( id ) =>
	id === UNCATEGORIZED ? __( 'Uncategorized', 'cph-filebirb' ) : store.folderLabel( id );

/**
 * Move attachments to a folder (0 = unassign).
 *
 * @param {number}   folder         Target folder.
 * @param {number[]} ids            Attachment ids.
 * @param {Object}   options        Options.
 * @param {boolean}  options.undo   Offer Undo.
 * @return {Promise<boolean>} Success.
 */
export async function moveAttachments( folder, ids, { undo = true } = {} ) {
	folder = Number( folder );
	ids = [ ...new Set( ids.map( Number ).filter( ( id ) => id > 0 ) ) ];
	if ( ! ids.length || folder < 0 ) {
		return false;
	}
	const before = new Map( ids.map( ( id ) => [ id, folderOf( id ) ] ) );
	const moving = ids.filter( ( id ) => before.get( id ) !== folder );
	if ( ! moving.length ) {
		speak(
			sprintf(
				/* translators: %s: folder name */
				_n( 'The file is already in %s.', 'The files are already in %s.', ids.length, 'cph-filebirb' ),
				label( folder )
			)
		);
		return true;
	}

	store.shiftCounts( moving.map( ( id ) => ( { from: before.get( id ), to: folder } ) ) );
	syncModels( moving, folder );

	const res = await store.assignToFolder( folder, moving );
	if ( ! res ) {
		// Roll back the optimistic bits; the store already showed the error.
		moving.forEach( ( id ) => ! Number.isNaN( before.get( id ) ) && syncModels( [ id ], before.get( id ) ) );
		store.refreshCounts();
		return false;
	}
	store.emit( 'move', { folder, ids: moving, before } );

	const message = sprintf(
		/* translators: 1: number of files, 2: folder name */
		_n( 'Moved %1$s file to %2$s.', 'Moved %1$s files to %2$s.', moving.length, 'cph-filebirb' ),
		moving.length.toLocaleString(),
		label( folder )
	);
	speak( message );

	const restorable = moving.filter( ( id ) => ! Number.isNaN( before.get( id ) ) );
	if ( undo && restorable.length ) {
		toast( message, {
			action: __( 'Undo', 'cph-filebirb' ),
			onAction: () => undoMove( restorable, before ),
		} );
	}
	return true;
}

async function undoMove( ids, before ) {
	const groups = new Map();
	ids.forEach( ( id ) => {
		const to = before.get( id );
		groups.set( to, [ ...( groups.get( to ) || [] ), id ] );
	} );
	for ( const [ folder, group ] of groups ) {
		store.shiftCounts( group.map( ( id ) => ( { from: folderOf( id ), to: folder } ) ) );
		syncModels( group, folder );
		await store.assignToFolder( folder, group );
	}
	store.emit( 'move', { folder: null, ids, before: null } );
	speak( __( 'Move undone.', 'cph-filebirb' ) );
}

/**
 * The folder all these attachments are in, or null when mixed/unknown.
 *
 * @param {number[]} ids Attachment ids.
 * @return {number|null} Folder id.
 */
export function commonFolder( ids ) {
	const folders = new Set( ids.map( folderOf ) );
	const [ only ] = folders;
	return folders.size === 1 && ! Number.isNaN( only ) ? only : null;
}

/**
 * Selected attachment ids of a selection collection.
 *
 * @param {Object} selection wp.media.model.Selection.
 * @return {number[]} Ids.
 */
export const idsOf = ( selection ) => ( selection ? selection.pluck( 'id' ).filter( Boolean ).map( Number ) : [] );

/**
 * A `selectionSource` for the Sidebar over a (possibly late) selection collection.
 *
 * @param {Function} getSelection Returns the selection collection or null.
 * @return {Object} `{ subscribe, getCount, getIds, moveTo }`.
 */
export function selectionSource( getSelection ) {
	return {
		subscribe( callback ) {
			const selection = getSelection();
			selection?.on( 'add remove reset', callback );
			return () => selection?.off( 'add remove reset', callback );
		},
		getCount: () => idsOf( getSelection() ).length,
		getIds: () => idsOf( getSelection() ),
		getCurrent: () => commonFolder( idsOf( getSelection() ) ),
		moveTo: ( folder ) => moveAttachments( folder, idsOf( getSelection() ) ),
	};
}

/**
 * Core's Query only watches `wp.Uploader.queue` when every arg is one it has
 * a client-side filter for, so a folder-filtered library never shows new
 * uploads. Give `fbv` queries that filter and the same observation.
 * Extension point: `wp.media.model.Query.prototype.initialize`.
 */
export function installQueryHook() {
	const Query = window.wp?.media?.model?.Query;
	if ( ! Query || Query.prototype.__cphfb ) {
		return;
	}
	Query.prototype.__cphfb = true;
	const allowed = [ 's', 'order', 'orderby', 'posts_per_page', 'post_mime_type', 'post_parent', 'author', 'fbv' ];
	const initialize = Query.prototype.initialize;
	Query.prototype.initialize = function ( ...args ) {
		const result = initialize.apply( this, args );
		const queue = window.wp?.Uploader?.queue;
		const keys = Object.keys( this.args || {} );
		if ( queue && keys.includes( 'fbv' ) && keys.every( ( key ) => allowed.includes( key ) ) && ! ( this.observers || [] ).includes( queue ) ) {
			this.filters.cphfb = folderFilter;
			this.observe( queue );
		}
		return result;
	};
}
