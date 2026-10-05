/**
 * Media Library list mode (`upload.php?mode=list`): folders filter by navigation.
 */
import * as store from '../store';

export function listUrl( id ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'mode', 'list' );
	url.searchParams.set( 'fbv', String( id ) );
	url.searchParams.delete( 'paged' );
	return url.toString();
}

export function installList() {
	store.on( 'select', ( id ) => {
		document.body.classList.add( 'cphfb-is-loading' );
		window.location.assign( listUrl( id ) );
	} );

	// The server's own folder dropdown: keep it in step (it is hidden by CSS
	// while the sidebar is mounted, but submits with the filter form).
	const select = document.getElementById( 'filter-by-fbv' );
	if ( select ) {
		select.value = String( store.getState().selected );
	}
}
