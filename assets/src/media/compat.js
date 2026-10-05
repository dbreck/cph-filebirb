/**
 * Attachment details "Folder" select (printed by PHP, saved by core's
 * `save-attachment-compat`). The response carries the new `fbv`, which core
 * sets on the model; we refresh counts and announce.
 */
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

import * as store from '../store';

const FIELD = /\[cphfb_folder\]$/;

export function installCompat() {
	const proto = window.wp?.media?.model?.Attachment?.prototype;
	if ( ! proto?.saveCompat || proto.__cphfb ) {
		return;
	}
	proto.__cphfb = true;
	const saveCompat = proto.saveCompat;
	proto.saveCompat = function ( data, ...rest ) {
		const touches = data && Object.keys( data ).some( ( key ) => FIELD.test( key ) );
		const before = this.get( 'fbv' );
		const request = saveCompat.call( this, data, ...rest );
		if ( touches && request?.done ) {
			request.done( () => {
				const after = Number( this.get( 'fbv' ) );
				if ( Number( before ) !== after ) {
					this.set( { folder_id: after } );
					store.refreshCounts();
					store.emit( 'move', { folder: after, ids: [ this.id ], before: null } );
					speak(
						sprintf(
							/* translators: %s: folder name */
							__( 'Moved to %s.', 'cph-filebirb' ),
							after > 0 ? store.folderLabel( after ) : __( 'Uncategorized', 'cph-filebirb' )
						)
					);
				}
			} );
		}
		return request;
	};
}
