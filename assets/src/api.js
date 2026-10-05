/**
 * Thin wrapper over the `cph-filebird/v1` REST routes.
 */
import apiFetch from '@wordpress/api-fetch';

const data = window.cphfbData || {};
const NS = '/' + ( data.namespace || 'cph-filebird/v1' );

if ( data.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );
}
if ( data.restRoot ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( data.restRoot ) );
}

const request = ( path, options = {} ) =>
	apiFetch( { path: NS + path, ...options } );

export const getFolders = ( includeCounts = true ) =>
	request( '/folders?include_counts=' + ( includeCounts ? 1 : 0 ) );

export const createFolder = ( name, parent = 0 ) =>
	request( '/folders', { method: 'POST', data: { name, parent } } );

export const updateFolder = ( id, fields ) =>
	request( '/folders/' + id, { method: 'PATCH', data: fields } );

export const deleteFolder = ( id, mode = 'subtree' ) =>
	request( '/folders/' + id + '?mode=' + encodeURIComponent( mode ), {
		method: 'DELETE',
	} );

export const duplicateFolder = ( id ) =>
	request( '/folders/' + id + '/duplicate', { method: 'POST' } );

export const saveOrder = ( items ) =>
	request( '/folders/order', { method: 'POST', data: { items } } );

export const assign = ( folder, ids ) =>
	request( '/assign', { method: 'POST', data: { folder, ids } } );

export const getAttachmentFolder = ( id ) =>
	request( '/attachments/' + id + '/folder' );

export const getCounts = () => request( '/counts' );

export const getUserSettings = () => request( '/user-settings' );

export const saveUserSettings = ( settings, keepalive = false ) =>
	request( '/user-settings', { method: 'POST', data: settings, ...( keepalive ? { keepalive: true } : {} ) } );

/**
 * Human-readable message for a REST error.
 *
 * @param {*} error Error thrown by apiFetch.
 * @return {string} Message.
 */
export const errorMessage = ( error ) =>
	( error && error.message ) || String( error || '' );
