/**
 * Shared helpers for the stage B specs (modals, uploads, attachment moves).
 */
const SHOTS = process.env.CPHFB_SHOTS || '';

const PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
);

async function shot( page, name, options = {} ) {
	if ( SHOTS ) {
		await page.waitForTimeout( 250 );
		await page.screenshot( { path: `${ SHOTS }/${ name }.png`, ...options } );
	}
}

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * REST call from the page (cookie + nonce). `path` starting with `/wp/` goes to
 * core routes, anything else to cph-filebirb/v1.
 */
async function rest( page, method, path, body ) {
	return page.evaluate(
		async ( [ m, p, b ] ) => {
			const d = window.cphfbData;
			const base = p.startsWith( '/wp/' ) ? p.slice( 1 ) : d.namespace + p;
			const url = new URL( d.restRoot + base.split( '?' )[ 0 ] );
			new URLSearchParams( base.split( '?' )[ 1 ] || '' ).forEach( ( v, k ) => url.searchParams.set( k, v ) );
			const res = await fetch( url, {
				method: m,
				headers: { 'X-WP-Nonce': d.nonce, 'Content-Type': 'application/json' },
				body: b ? JSON.stringify( b ) : undefined,
				credentials: 'same-origin',
			} );
			return res.json();
		},
		[ method, path, body ]
	);
}

async function resetFolders( page ) {
	const { tree } = await rest( page, 'GET', '/folders?include_counts=0' );
	for ( const node of tree ) {
		await rest( page, 'DELETE', `/folders/${ node.id }?mode=subtree` );
	}
	await rest( page, 'POST', '/user-settings', { collapsed: [], selected_folder: -1, sidebar_width: 0, default_upload_folder: -1 } );
	await page.evaluate( () => window.localStorage.clear() );
}

async function seed( page ) {
	const brand = await rest( page, 'POST', '/folders', { name: 'Brand', parent: 0 } );
	const events = await rest( page, 'POST', '/folders', { name: 'Events', parent: 0 } );
	const gala = await rest( page, 'POST', '/folders', { name: 'Gala', parent: events.id } );
	const products = await rest( page, 'POST', '/folders', { name: 'Products', parent: 0 } );
	return { brand, events, gala, products };
}

async function folderOf( page, id ) {
	return ( await rest( page, 'GET', `/attachments/${ id }/folder` ) ).folder_id;
}

async function mediaIds( page, n ) {
	const items = await rest( page, 'GET', `/wp/v2/media?per_page=${ n }&orderby=id&order=asc&_fields=id` );
	return items.map( ( i ) => i.id );
}

const pageOf = ( scope ) => ( typeof scope.page === 'function' ? scope.page() : scope );

/** Tree row by folder name, inside `scope` (page or a locator). */
const row = ( scope, name ) =>
	scope.locator( '.cphfb-tree [role="treeitem"]', { has: pageOf( scope ).locator( `.cphfb-row__name:text-is("${ name }")` ) } );

const pinned = ( scope, label ) => scope.locator( '.cphfb-row--pinned', { hasText: label } );

/** Collect page errors and console errors into an array. */
function trackErrors( page, ignore = [] ) {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! ignore.some( ( re ) => re.test( m.text() ) ) ) {
			errors.push( m.text() );
		}
	} );
	return errors;
}

/**
 * Block editor for a new post, with the welcome guide out of the way.
 */
async function openBlockEditor( page ) {
	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' ) && window.wp?.media && window.cphfb );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
	} );
	const guide = page.locator( '.edit-post-welcome-guide, .components-guide' );
	if ( await guide.isVisible().catch( () => false ) ) {
		await page.keyboard.press( 'Escape' );
	}
}

/** The visible media modal. */
const modal = ( page ) => page.locator( '.media-modal:visible' );

/** Core remembers the last tab (Upload files / Media Library); go to the library. */
async function showLibrary( page ) {
	const tab = modal( page ).locator( '#menu-item-browse' );
	await tab.waitFor();
	if ( ( await tab.getAttribute( 'aria-selected' ) ) !== 'true' ) {
		await tab.click();
	}
	await modal( page ).locator( '.cphfb-frame-tree .cphfb-sidebar' ).waitFor();
	return modal( page );
}

/** Wait for the next query-attachments request, resolve its decoded body. */
const nextQuery = ( page ) =>
	page
		.waitForRequest( ( r ) => ( r.postData() || '' ).includes( 'action=query-attachments' ) )
		.then( ( r ) => decodeURIComponent( r.postData() ) );

/** Upload a tiny PNG through a file input; resolves to the new attachment id. */
async function uploadVia( page, input, name ) {
	const response = page.waitForResponse( ( r ) => r.url().includes( 'async-upload.php' ) && r.request().method() === 'POST', { timeout: 20000 } );
	await input.setInputFiles( { name, mimeType: 'image/png', buffer: PNG } );
	const res = await response;
	// media-new.php posts `short=1` and gets the bare id back.
	const text = await res.text();
	const id = /^\s*\d+\s*$/.test( text ) ? Number( text ) : JSON.parse( text ).data.id;
	return { id, request: res.request() };
}

module.exports = {
	SHOTS,
	PNG,
	shot,
	login,
	rest,
	resetFolders,
	seed,
	folderOf,
	mediaIds,
	row,
	pinned,
	trackErrors,
	openBlockEditor,
	modal,
	showLibrary,
	nextQuery,
	uploadVia,
};
