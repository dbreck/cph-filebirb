/**
 * Folder sidebar on upload.php (grid and list mode).
 *
 * Needs wp-env running with cph-filebirb active and a few attachments.
 * Set CPHFB_SHOTS=/some/dir to save screenshots for design review.
 */
const { test, expect } = require( '@playwright/test' );

const SHOTS = process.env.CPHFB_SHOTS || '';

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
 * Call the plugin's REST API from the page (cookie + nonce auth).
 */
async function rest( page, method, path, body ) {
	return page.evaluate(
		async ( [ m, p, b ] ) => {
			const d = window.cphfbData;
			const url = new URL( d.restRoot + d.namespace + p.split( '?' )[ 0 ] );
			new URLSearchParams( p.split( '?' )[ 1 ] || '' ).forEach( ( v, k ) => url.searchParams.set( k, v ) );
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
	await rest( page, 'POST', '/user-settings', { collapsed: [], selected_folder: -1, sidebar_width: 0 } );
	await page.evaluate( () => window.localStorage.clear() );
}

async function seed( page ) {
	const brand = await rest( page, 'POST', '/folders', { name: 'Brand', parent: 0 } );
	const events = await rest( page, 'POST', '/folders', { name: 'Events', parent: 0 } );
	const products = await rest( page, 'POST', '/folders', { name: 'Products', parent: 0 } );
	const gala = await rest( page, 'POST', '/folders', { name: 'Gala', parent: events.id } );
	const launch = await rest( page, 'POST', '/folders', { name: 'Launch', parent: events.id } );
	return { brand, events, products, gala, launch };
}

const row = ( page, name ) =>
	page.locator( '.cphfb-tree__folders [role="treeitem"]', { has: page.locator( `.cphfb-row__name:text-is("${ name }")` ) } );

const folderNames = ( page ) => page.locator( '.cphfb-tree__folders .cphfb-row__name' ).allInnerTexts();

/**
 * Pointer drag with intermediate moves (dnd-kit needs real movement).
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} from
 * @param {import('@playwright/test').Locator} to
 * @param {number} rel Vertical drop point inside the target row (0..1).
 */
async function drag( page, from, to, rel ) {
	const a = await from.boundingBox();
	const b = await to.boundingBox();
	await page.mouse.move( a.x + 60, a.y + a.height / 2 );
	await page.mouse.down();
	await page.mouse.move( a.x + 64, a.y + a.height / 2 + 8, { steps: 4 } );
	await page.mouse.move( b.x + 80, b.y + b.height * rel, { steps: 12 } );
	await page.waitForTimeout( 150 );
	await page.mouse.move( b.x + 82, b.y + b.height * rel, { steps: 2 } );
	await page.mouse.up();
}

let errors;

test.beforeEach( async ( { page } ) => {
	errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		// The 409 from the name-collision test is expected; the UI handles it.
		if ( m.type() === 'error' && ! /status of 409/.test( m.text() ) ) {
			errors.push( m.text() );
		}
	} );
	await login( page );
	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await page.waitForSelector( '.cphfb-sidebar' );
	await resetFolders( page );
} );

test.afterEach( async () => {
	expect( errors, 'console errors' ).toEqual( [] );
} );

test( 'grid: renders pinned rows, tree and counts without layout errors', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await expect( page.locator( '[role="tree"]' ) ).toBeVisible();
	await expect( page.locator( '.cphfb-row--pinned' ) ).toHaveCount( 2 );
	await expect( await folderNames( page ) ).toEqual( [ 'Brand', 'Events', 'Gala', 'Launch', 'Products' ] );
	const all = await page.locator( '.cphfb-row--pinned' ).first().locator( '.cphfb-count' ).innerText();
	expect( Number( all.replace( /\D/g, '' ) ) ).toBeGreaterThan( 0 );
	// Library sits to the right of the sidebar.
	const side = await page.locator( '#cphfb-root' ).boundingBox();
	const grid = await page.locator( '.attachments-browser' ).boundingBox();
	expect( grid.x ).toBeGreaterThanOrEqual( side.x + side.width );
	await shot( page, 'grid-tree' );
} );

test( 'grid: selecting a folder re-queries with query[fbv] and survives reload', async ( { page } ) => {
	const { events } = await seed( page );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );

	const request = page.waitForRequest(
		( r ) => r.url().includes( 'admin-ajax.php' ) && ( r.postData() || '' ).includes( 'action=query-attachments' )
	);
	await row( page, 'Events' ).click();
	const body = decodeURIComponent( ( await request ).postData() );
	expect( body ).toContain( `query[fbv]=${ events.id }` );
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-selected', 'true' );

	// Uncategorized sends 0.
	const unc = page.waitForRequest( ( r ) => ( r.postData() || '' ).includes( 'query-attachments' ) );
	await page.locator( '.cphfb-row--pinned', { hasText: 'Uncategorized' } ).click();
	expect( decodeURIComponent( ( await unc ).postData() ) ).toContain( 'query[fbv]=0' );

	await row( page, 'Events' ).click();
	await page.waitForTimeout( 800 ); // user-settings write is debounced.

	// First query after reload already carries the folder: no unfiltered flash.
	const first = page.waitForRequest( ( r ) => ( r.postData() || '' ).includes( 'query-attachments' ) );
	await page.reload();
	expect( decodeURIComponent( ( await first ).postData() ) ).toContain( `query[fbv]=${ events.id }` );
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'create, rename, duplicate-name error, cancel, color, duplicate', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );

	// New folder at root (All files selected) goes straight into rename mode.
	await page.locator( '.cphfb-row--pinned' ).first().click();
	await page.click( '.cphfb-new' );
	const input = page.locator( '.cphfb-rename__input' );
	await expect( input ).toBeFocused();
	await expect( input ).toHaveValue( 'New folder' );
	await shot( page, 'rename', { clip: { x: 160, y: 32, width: 300, height: 420 } } );

	// Name collision shows an inline error and keeps editing.
	await input.fill( 'Brand' );
	await input.press( 'Enter' );
	await expect( page.locator( '.cphfb-rename__error' ) ).toBeVisible();
	await shot( page, 'rename-error', { clip: { x: 160, y: 32, width: 300, height: 420 } } );
	await expect( input ).toBeVisible();
	await input.fill( 'Archive' );
	await input.press( 'Enter' );
	await expect( input ).toBeHidden();
	await expect( row( page, 'Archive' ) ).toBeVisible();

	// Double-click rename, Escape cancels.
	await row( page, 'Archive' ).dblclick();
	await input.fill( 'Nope' );
	await input.press( 'Escape' );
	await expect( row( page, 'Archive' ) ).toBeVisible();

	// Subfolder via context menu.
	await row( page, 'Brand' ).click( { button: 'right' } );
	await expect( page.locator( '.cphfb-menu' ) ).toBeVisible();
	await shot( page, 'context-menu', { clip: { x: 160, y: 32, width: 440, height: 480 } } );
	await page.getByRole( 'menuitem', { name: 'New subfolder' } ).click();
	await input.fill( 'Logos' );
	await input.press( 'Enter' );
	await expect( row( page, 'Logos' ) ).toHaveAttribute( 'aria-level', '2' );

	// Color via the kebab button.
	await row( page, 'Brand' ).hover();
	await row( page, 'Brand' ).locator( '.cphfb-row__more' ).click();
	await page.getByRole( 'menuitemradio', { name: 'Purple' } ).click();
	await expect( row( page, 'Brand' ).locator( '.cphfb-icon--folder path' ) ).toHaveAttribute( 'fill', '#7a4fd6' );

	// Duplicate.
	await row( page, 'Products' ).click( { button: 'right' } );
	await page.getByRole( 'menuitem', { name: 'Duplicate' } ).click();
	await expect( page.locator( '.cphfb-tree__folders .cphfb-row__name', { hasText: /^Products./ } ) ).toHaveCount( 1 );

	// Everything persisted.
	await page.reload();
	await expect( row( page, 'Logos' ) ).toBeVisible();
	await expect( row( page, 'Archive' ) ).toBeVisible();
	await expect( row( page, 'Brand' ).locator( '.cphfb-icon--folder path' ) ).toHaveAttribute( 'fill', '#7a4fd6' );
} );

test( 'delete: keep subfolders vs delete subtree', async ( { page } ) => {
	await seed( page );
	await rest( page, 'POST', '/folders', { name: 'Deep', parent: ( await rest( page, 'GET', '/folders' ) ).tree[ 1 ].children[ 0 ].id } );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );

	await row( page, 'Gala' ).click();
	await page.keyboard.press( 'Delete' );
	const dialog = page.locator( 'dialog.cphfb-dialog' );
	await expect( dialog ).toBeVisible();
	await expect( dialog ).toContainText( 'never deletes media files' );
	await expect( dialog.getByRole( 'button', { name: 'Cancel' } ) ).toBeFocused();
	await shot( page, 'delete-dialog' );
	await dialog.getByRole( 'button', { name: 'Keep subfolders (move them up)' } ).click();
	await expect( dialog ).toBeHidden();
	await expect( row( page, 'Gala' ) ).toHaveCount( 0 );
	await expect( row( page, 'Deep' ) ).toHaveAttribute( 'aria-level', '2' );
	// Selection fell back to All files.
	await expect( page.locator( '.cphfb-row--pinned' ).first() ).toHaveAttribute( 'aria-selected', 'true' );

	await row( page, 'Events' ).click( { button: 'right' } );
	await page.getByRole( 'menuitem', { name: 'Delete…' } ).click();
	await dialog.getByRole( 'button', { name: 'Delete folder and subfolders' } ).click();
	await expect( row( page, 'Events' ) ).toHaveCount( 0 );
	await expect( row( page, 'Deep' ) ).toHaveCount( 0 );
	await page.reload();
	expect( await folderNames( page ) ).toEqual( [ 'Brand', 'Products' ] );
} );

test( 'drag to reorder and reparent persists; cannot drop into own descendant', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );

	// Products before Brand.
	await drag( page, row( page, 'Products' ), row( page, 'Brand' ), 0.1 );
	await expect.poll( () => folderNames( page ) ).toEqual( [ 'Products', 'Brand', 'Events', 'Gala', 'Launch' ] );

	// Brand into Launch.
	await drag( page, row( page, 'Brand' ), row( page, 'Launch' ), 0.5 );
	await expect( row( page, 'Brand' ) ).toHaveAttribute( 'aria-level', '3' );

	// Events into its own child Gala: refused.
	await drag( page, row( page, 'Events' ), row( page, 'Gala' ), 0.5 );
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-level', '1' );

	await page.waitForTimeout( 500 );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );
	expect( await folderNames( page ) ).toEqual( [ 'Products', 'Events', 'Gala', 'Launch', 'Brand' ] );
	await expect( row( page, 'Brand' ) ).toHaveAttribute( 'aria-level', '3' );
} );

test( 'drag shows a drop indicator', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );
	const a = await row( page, 'Products' ).boundingBox();
	const b = await row( page, 'Events' ).boundingBox();
	await page.mouse.move( a.x + 60, a.y + a.height / 2 );
	await page.mouse.down();
	await page.mouse.move( b.x + 80, b.y + b.height / 2, { steps: 12 } );
	await expect( row( page, 'Events' ) ).toHaveClass( /is-drop-inside/ );
	await shot( page, 'drag-inside', { clip: { x: 160, y: 32, width: 300, height: 380 } } );
	await page.mouse.move( b.x + 80, b.y + 3, { steps: 4 } );
	await expect( row( page, 'Events' ) ).toHaveClass( /is-drop-before/ );
	await shot( page, 'drag-before', { clip: { x: 160, y: 32, width: 300, height: 380 } } );
	await page.keyboard.press( 'Escape' );
	await page.mouse.up();
} );

test( 'keyboard: arrows, expand/collapse, Enter selects, F2 renames', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );

	await page.locator( '.cphfb-row--pinned' ).first().focus();
	await page.keyboard.press( 'ArrowDown' ); // Uncategorized
	await page.keyboard.press( 'ArrowDown' ); // Brand
	await page.keyboard.press( 'ArrowDown' ); // Events
	await expect( row( page, 'Events' ) ).toBeFocused();
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-expanded', 'true' );
	await page.keyboard.press( 'ArrowLeft' );
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( row( page, 'Gala' ) ).toHaveCount( 0 );
	await page.keyboard.press( 'ArrowRight' );
	await page.keyboard.press( 'ArrowRight' );
	await expect( row( page, 'Gala' ) ).toBeFocused();
	await page.keyboard.press( 'ArrowLeft' );
	await expect( row( page, 'Events' ) ).toBeFocused();
	await page.keyboard.press( 'End' );
	await expect( row( page, 'Products' ) ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( row( page, 'Products' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await shot( page, 'keyboard-focus', { clip: { x: 160, y: 32, width: 300, height: 380 } } );
	await page.keyboard.press( 'F2' );
	await page.keyboard.type( 'Catalog' );
	await page.keyboard.press( 'Enter' );
	await expect( row( page, 'Catalog' ) ).toBeFocused();
	await page.keyboard.press( 'Home' );
	await expect( page.locator( '.cphfb-row--pinned' ).first() ).toBeFocused();

	// Collapsed state persists.
	await row( page, 'Events' ).locator( '.cphfb-chevron' ).click();
	await page.waitForTimeout( 900 );
	await page.reload();
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-expanded', 'false' );
} );

test( 'search filters the tree and keeps ancestors', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.fill( '.cphfb-search__input', 'laun' );
	expect( await folderNames( page ) ).toEqual( [ 'Events', 'Launch' ] );
	await shot( page, 'search', { clip: { x: 160, y: 32, width: 300, height: 300 } } );
	await page.fill( '.cphfb-search__input', 'zzz' );
	await expect( page.locator( '.cphfb-empty' ) ).toContainText( 'No folders match' );
	await page.locator( '.cphfb-search__input' ).press( 'Escape' );
	expect( ( await folderNames( page ) ).length ).toBe( 5 );
} );

test( 'sort menu orders by name', async ( { page } ) => {
	await seed( page );
	await page.reload();
	await page.getByRole( 'button', { name: 'Sort folders' } ).click();
	await page.getByRole( 'menuitemradio', { name: 'Name Z → A' } ).click();
	expect( await folderNames( page ) ).toEqual( [ 'Products', 'Events', 'Launch', 'Gala', 'Brand' ] );
	await page.reload();
	expect( await folderNames( page ) ).toEqual( [ 'Products', 'Events', 'Launch', 'Gala', 'Brand' ] );
	await page.getByRole( 'button', { name: 'Sort folders' } ).click();
	await page.getByRole( 'menuitemradio', { name: 'Custom order' } ).click();
} );

test( 'counts refresh after assigning attachments', async ( { page } ) => {
	const { brand } = await seed( page );
	await page.reload();
	await page.waitForSelector( '.attachments .attachment' );
	const ids = await page.evaluate( () => window.wp.media.frame.state().get( 'library' ).pluck( 'id' ).slice( 0, 2 ) );
	await page.evaluate( ( [ f, i ] ) => window.cphfb.assign( f, i ), [ brand.id, ids ] );
	await expect( row( page, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '2' );
	await rest( page, 'POST', '/assign', { folder: 0, ids } );
	await page.evaluate( () => window.cphfb.refresh() );
	await expect( row( page, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '0' );
} );

test( 'resize and collapse to rail persist', async ( { page } ) => {
	const handle = page.locator( '.cphfb-resize' );
	const box = await handle.boundingBox();
	await page.mouse.move( box.x + 4, box.y + 200 );
	await page.mouse.down();
	await page.mouse.move( box.x + 104, box.y + 200, { steps: 8 } );
	await page.mouse.up();
	const width = ( await page.locator( '#cphfb-root' ).boundingBox() ).width;
	expect( width ).toBeGreaterThan( 330 );
	await page.waitForTimeout( 1000 );
	await page.reload();
	expect( Math.abs( ( await page.locator( '#cphfb-root' ).boundingBox() ).width - width ) ).toBeLessThan( 3 );

	await page.getByRole( 'button', { name: 'Hide folders' } ).click();
	expect( ( await page.locator( '#cphfb-root' ).boundingBox() ).width ).toBeLessThan( 60 );
	await shot( page, 'rail' );
	await page.reload();
	expect( ( await page.locator( '#cphfb-root' ).boundingBox() ).width ).toBeLessThan( 60 );
	await page.getByRole( 'button', { name: 'Show folders' } ).click();
	expect( ( await page.locator( '#cphfb-root' ).boundingBox() ).width ).toBeGreaterThan( 200 );
} );

test( 'list mode: sidebar navigates with fbv and hides the dropdown', async ( { page } ) => {
	const { events } = await seed( page );
	await page.goto( '/wp-admin/upload.php?mode=list' );
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );
	await expect( page.locator( '#filter-by-fbv' ) ).toBeHidden();
	const side = await page.locator( '#cphfb-root' ).boundingBox();
	const table = await page.locator( '.wp-list-table' ).boundingBox();
	expect( table.x ).toBeGreaterThanOrEqual( side.x + side.width );
	await Promise.all( [ page.waitForURL( new RegExp( `fbv=${ events.id }` ) ), row( page, 'Events' ).click() ] );
	await expect( row( page, 'Events' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await shot( page, 'list-mode' );
	await page.waitForTimeout( 900 );

	// Bare list URL goes back to the last folder.
	await page.goto( '/wp-admin/upload.php?mode=list' );
	await expect( page ).toHaveURL( new RegExp( `fbv=${ events.id }` ) );
	await Promise.all( [ page.waitForURL( /fbv=-1/ ), page.locator( '.cphfb-row--pinned' ).first().click() ] );
	await page.goto( '/wp-admin/upload.php?mode=grid' );
} );

test( 'narrow screens stack the sidebar above the library', async ( { page } ) => {
	await seed( page );
	await page.setViewportSize( { width: 700, height: 900 } );
	await page.reload();
	await page.waitForSelector( '.cphfb-tree__folders .cphfb-row' );
	const side = await page.locator( '#cphfb-root' ).boundingBox();
	const grid = await page.locator( '.attachments-browser' ).boundingBox();
	expect( grid.y ).toBeGreaterThan( side.y + side.height - 1 );
	await shot( page, 'mobile', { fullPage: true } );
} );

test( 'folded admin menu', async ( { page } ) => {
	await seed( page );
	await page.evaluate( () => document.body.classList.add( 'folded' ) );
	const side = await page.locator( '#cphfb-root' ).boundingBox();
	expect( side.x ).toBeLessThan( 40 );
	await shot( page, 'folded' );
} );

test( 'core grid features still work: uploader, upload, bulk select, details modal', async ( { page } ) => {
	await page.waitForSelector( '.attachments .attachment' );
	const before = ( await rest( page, 'GET', '/counts' ) ).all;

	// Uploader dropzone opens, and an upload refreshes the counts.
	await page.locator( '.page-title-action' ).click();
	await expect( page.locator( '.uploader-inline' ) ).toBeVisible();
	await shot( page, 'uploader' );
	const png = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	);
	const countsRequest = page.waitForRequest( ( r ) => /cph-filebirb(\/|%2F)v1(\/|%2F)counts/.test( r.url() ), { timeout: 15000 } );
	await page.locator( '.uploader-inline input[type="file"], .moxie-shim input[type="file"]' ).first().setInputFiles( {
		name: 'cphfb-e2e.png',
		mimeType: 'image/png',
		buffer: png,
	} );
	await countsRequest;
	await expect( page.locator( '.cphfb-row--pinned' ).first().locator( '.cphfb-count' ) ).toHaveText( String( before + 1 ), { timeout: 15000 } );

	// Bulk select toggles.
	await page.locator( '.select-mode-toggle-button' ).click();
	await expect( page.locator( '.delete-selected-button' ) ).toBeVisible();
	await page.locator( '.select-mode-toggle-button' ).click();

	// Attachment details modal opens above the sidebar; delete the upload from it.
	await page.locator( '.attachments .attachment[aria-label="cphfb-e2e"]' ).first().click();
	await expect( page.locator( '.media-modal' ) ).toBeVisible();
	await shot( page, 'details-modal' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.locator( '.media-modal .delete-attachment' ).click();
	await expect( page.locator( '.cphfb-row--pinned' ).first().locator( '.cphfb-count' ) ).toHaveText( String( before ), { timeout: 15000 } );
} );
