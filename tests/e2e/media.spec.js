/**
 * Stage B: folder column in `wp.media` frames, upload routing, moving
 * attachments (drag, "Move to folder…", context menu, details pane).
 *
 * Needs wp-env running with cph-filebirb active and a few attachments.
 * Set CPHFB_SHOTS=/some/dir to save screenshots for design review.
 */
const { test, expect } = require( '@playwright/test' );
const h = require( './helpers' );

let errors;

test.beforeEach( async ( { page } ) => {
	errors = h.trackErrors( page );
	await h.login( page );
	await page.goto( '/wp-admin/upload.php?mode=grid' );
	await page.waitForSelector( '.cphfb-sidebar' );
	await h.resetFolders( page );
} );

test.afterEach( async () => {
	expect( errors, 'console errors' ).toEqual( [] );
} );

/** Open a fresh Select frame on the editor page and switch to the library. */
async function openFrame( page, name, options = {} ) {
	await page.evaluate(
		( [ n, o ] ) => {
			window[ n ] = window[ n ] || window.wp.media( { title: n, multiple: 'add', ...o } );
			window[ n ].open();
			window[ n ].content.mode( 'browse' );
		},
		[ name, options ]
	);
	const frame = h.modal( page );
	await expect( frame.locator( '.cphfb-frame-tree .cphfb-sidebar' ) ).toBeVisible();
	return frame;
}

test( 'featured image modal shows the tree; picking a folder sends query[fbv]', async ( { page } ) => {
	const { events } = await h.seed( page );
	await h.openBlockEditor( page );

	const settings = page.getByRole( 'button', { name: 'Settings', exact: true } ).first();
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await settings.click();
	}
	await page.getByRole( 'tab', { name: 'Post' } ).click().catch( () => {} );
	await page.locator( '.editor-post-featured-image__toggle' ).click();

	const frame = await h.showLibrary( page );
	await expect( frame.locator( '.cphfb-frame-tree [role="tree"]' ) ).toBeVisible();
	await expect( h.row( frame, 'Events' ) ).toBeVisible();
	await expect( h.row( frame, 'Gala' ) ).toBeVisible();
	await h.shot( page, 'modal-featured' );

	const query = h.nextQuery( page );
	await h.row( frame, 'Events' ).click();
	expect( await query ).toContain( `query[fbv]=${ events.id }` );
	await expect( h.row( frame, 'Events' ) ).toHaveAttribute( 'aria-selected', 'true' );
	// The page has no Media Library sidebar.
	await expect( page.locator( '#cphfb-root' ) ).toHaveCount( 0 );
} );

test( 'image block "Media Library" modal shows the tree', async ( { page } ) => {
	const { brand } = await h.seed( page );
	await h.openBlockEditor( page );
	await page.evaluate( () => {
		const block = window.wp.blocks.createBlock( 'core/image' );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
	} );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const inCanvas = await page.locator( 'iframe[name="editor-canvas"]' ).count();
	const scope = inCanvas ? canvas : page;
	await scope.getByRole( 'button', { name: 'Media Library' } ).first().click();

	const frame = await h.showLibrary( page );
	const query = h.nextQuery( page );
	await h.row( frame, 'Brand' ).click();
	expect( await query ).toContain( `query[fbv]=${ brand.id }` );
	await h.shot( page, 'modal-image-block' );
} );

test( 'two frames keep independent folders; reopening does not duplicate the tree', async ( { page } ) => {
	const { brand, products } = await h.seed( page );
	await h.openBlockEditor( page );

	let frame = await openFrame( page, 'cphfbA' );
	await h.row( frame, 'Brand' ).click();
	await page.evaluate( () => window.cphfbA.close() );

	frame = await openFrame( page, 'cphfbB' );
	// A new frame starts in the last folder used, then goes its own way.
	await expect( h.row( frame, 'Brand' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await h.row( frame, 'Products' ).click();
	await page.evaluate( () => window.cphfbB.close() );

	frame = await openFrame( page, 'cphfbA' );
	await expect( h.row( frame, 'Brand' ) ).toHaveAttribute( 'aria-selected', 'true' );
	const props = await page.evaluate( () => [
		window.cphfbA.state().get( 'library' ).props.get( 'fbv' ),
		window.cphfbB.state().get( 'library' ).props.get( 'fbv' ),
	] );
	expect( props ).toEqual( [ brand.id, products.id ] );

	// Open/close/open and mode switches: one column per frame, no leaked roots.
	await page.evaluate( () => {
		window.cphfbA.close();
		window.cphfbA.open();
		window.cphfbA.content.mode( 'upload' );
		window.cphfbA.content.mode( 'browse' );
	} );
	await expect( h.modal( page ).locator( '.cphfb-frame-tree' ) ).toHaveCount( 1 );
	const counts = await page.evaluate( () => [ window.cphfbA.el, window.cphfbB.el ].map( ( el ) => el.querySelectorAll( '.cphfb-frame-tree' ).length ) );
	expect( counts ).toEqual( [ 1, 1 ] );
	// Closed frame B gave its React root back.
	expect( await page.evaluate( () => window.cphfbB.el.querySelector( '.cphfb-frame-tree' ).childElementCount ) ).toBe( 0 );
} );

test( 'gallery edit and upload tab get no tree; gallery reorder still works', async ( { page } ) => {
	await h.seed( page );
	await h.openBlockEditor( page );
	await page.evaluate( () => {
		window.cphfbG = window.wp.media( { frame: 'post', state: 'gallery' } );
		window.cphfbG.open();
		window.cphfbG.content.mode( 'browse' );
	} );
	const frame = h.modal( page );
	await expect( frame.locator( '.cphfb-frame-tree' ) ).toBeVisible();
	await frame.locator( '.attachments .attachment' ).nth( 0 ).click();
	await frame.locator( '.attachments .attachment' ).nth( 1 ).click();
	await frame.locator( '.attachments .attachment' ).nth( 2 ).click();
	await frame.getByRole( 'button', { name: 'Create a new gallery' } ).click();
	await expect( frame.locator( '.attachments.ui-sortable' ) ).toBeVisible();
	await expect( frame.locator( '.cphfb-frame-tree:visible' ) ).toHaveCount( 0 );
	await h.shot( page, 'modal-gallery-edit' );

	const before = await page.evaluate( () => window.cphfbG.state().get( 'library' ).pluck( 'id' ) );
	const items = frame.locator( '.attachments.ui-sortable .attachment' );
	const a = await items.nth( 0 ).boundingBox();
	const b = await items.nth( 2 ).boundingBox();
	await page.mouse.move( a.x + a.width / 2, a.y + a.height / 2 );
	await page.mouse.down();
	await page.mouse.move( a.x + a.width / 2 + 10, a.y + a.height / 2, { steps: 3 } );
	await page.mouse.move( b.x + b.width - 5, b.y + b.height / 2, { steps: 12 } );
	await page.mouse.up();
	const after = await page.evaluate( () => window.cphfbG.state().get( 'library' ).pluck( 'id' ) );
	expect( after ).not.toEqual( before );
	expect( [ ...after ].sort() ).toEqual( [ ...before ].sort() );

	await page.evaluate( () => window.cphfbG.content.mode( 'upload' ) );
	await expect( frame.locator( '.cphfb-frame-tree:visible' ) ).toHaveCount( 0 );
	await expect( frame.locator( '.cphfb-upload-folder select' ) ).toBeVisible();
} );

test( 'modal layout at several sizes; narrow modals collapse to a rail', async ( { page } ) => {
	await h.seed( page );
	await h.openBlockEditor( page );
	const frame = await openFrame( page, 'cphfbL' );
	await frame.locator( '.attachments .attachment' ).first().click();
	for ( const [ w, ht ] of [ [ 1440, 900 ], [ 1180, 820 ], [ 960, 760 ], [ 820, 700 ], [ 600, 800 ] ] ) {
		await page.setViewportSize( { width: w, height: ht } );
		await page.waitForTimeout( 300 );
		const tree = await frame.locator( '.cphfb-frame-tree' ).boundingBox();
		const list = await frame.locator( '.attachments-browser .attachments' ).boundingBox();
		const browser = await frame.locator( '.attachments-browser' ).boundingBox();
		// The list starts right of the column; nothing overlaps it.
		expect( list.x ).toBeGreaterThanOrEqual( tree.x + tree.width - 1 );
		if ( browser.width < 900 ) {
			expect( tree.width ).toBeLessThan( 60 );
		} else {
			expect( tree.width ).toBeGreaterThan( 200 );
		}
		await h.shot( page, `modal-${ w }` );
	}
	// Narrow: the toggle opens the tree as an overlay; picking a folder closes it.
	await page.setViewportSize( { width: 820, height: 700 } );
	await frame.getByRole( 'button', { name: 'Show folders' } ).click();
	await expect( frame.locator( '.cphfb-frame-tree' ) ).toHaveCSS( 'width', /2\d\dpx/ );
	await h.shot( page, 'modal-820-overlay' );
	await h.row( frame, 'Brand' ).click();
	await expect( frame.getByRole( 'button', { name: 'Show folders' } ) ).toBeVisible();
	// Wide: the user's collapse choice sticks.
	await page.setViewportSize( { width: 1440, height: 900 } );
	await frame.getByRole( 'button', { name: 'Hide folders' } ).click();
	expect( ( await frame.locator( '.cphfb-frame-tree' ).boundingBox() ).width ).toBeLessThan( 60 );
	await frame.getByRole( 'button', { name: 'Show folders' } ).click();
	expect( ( await frame.locator( '.cphfb-frame-tree' ).boundingBox() ).width ).toBeGreaterThan( 200 );
} );

test( 'grid: upload with a folder selected lands in that folder', async ( { page } ) => {
	const { gala } = await h.seed( page );
	await page.reload();
	await h.row( page, 'Gala' ).click();
	await page.locator( '.page-title-action' ).click();
	const picker = page.locator( '.uploader-inline .cphfb-upload-folder select' );
	await expect( picker ).toBeVisible();
	await expect( picker ).toHaveValue( String( gala.id ) );
	await h.shot( page, 'grid-uploader-picker' );

	const { id, request } = await h.uploadVia( page, page.locator( '.moxie-shim input[type="file"], .uploader-inline input[type="file"]' ).first(), 'cphfb-grid-upload.png' );
	expect( request.headers()[ 'x-cphfb-folder' ] ).toBe( String( gala.id ) );
	expect( await h.folderOf( page, id ) ).toBe( gala.id );
	// Shows in the current folder view, and the count went up.
	await expect( page.locator( '.attachments .attachment[aria-label="cphfb-grid-upload"]' ) ).toBeVisible();
	await expect( h.row( page, 'Gala' ).locator( '.cphfb-count' ) ).toHaveText( '1', { timeout: 10000 } );

	// Changing the picker sends the upload elsewhere and it leaves this view.
	await picker.selectOption( '0' );
	const second = await h.uploadVia( page, page.locator( '.moxie-shim input[type="file"], .uploader-inline input[type="file"]' ).first(), 'cphfb-grid-upload-2.png' );
	expect( await h.folderOf( page, second.id ) ).toBe( 0 );
	await expect( page.locator( '.attachments .attachment[aria-label="cphfb-grid-upload-2"]' ) ).toHaveCount( 0 );

	await h.rest( page, 'DELETE', `/wp/v2/media/${ id }?force=true` );
	await h.rest( page, 'DELETE', `/wp/v2/media/${ second.id }?force=true` );
} );

test( 'modal: upload with a folder selected lands in that folder', async ( { page } ) => {
	const { brand, products } = await h.seed( page );
	await h.openBlockEditor( page );
	const frame = await openFrame( page, 'cphfbU' );
	await h.row( frame, 'Brand' ).click();
	await page.evaluate( () => window.cphfbU.content.mode( 'upload' ) );
	const picker = frame.locator( '.cphfb-upload-folder select' );
	await expect( picker ).toHaveValue( String( brand.id ) );
	await h.shot( page, 'modal-upload-tab' );

	const input = page.locator( '.moxie-shim input[type="file"]' ).last();
	const { id, request } = await h.uploadVia( page, input, 'cphfb-modal-upload.png' );
	expect( request.headers()[ 'x-cphfb-folder' ] ).toBe( String( brand.id ) );
	expect( await h.folderOf( page, id ) ).toBe( brand.id );

	// Another frame in another folder routes to its own folder.
	await page.evaluate( () => window.cphfbU.close() );
	const other = await openFrame( page, 'cphfbV' );
	await h.row( other, 'Products' ).click();
	const target = await page.evaluate( () => window.cphfb.uploadTarget( window.cphfbV.el ) );
	expect( target ).toBe( products.id );
	expect( await page.evaluate( () => window.cphfb.uploadTarget( window.cphfbU.el ) ) ).toBe( brand.id );

	await h.rest( page, 'DELETE', `/wp/v2/media/${ id }?force=true` );
} );

test( 'grid: drag one attachment onto a folder, then onto Uncategorized; Undo', async ( { page } ) => {
	const { brand } = await h.seed( page );
	await page.reload();
	await page.waitForSelector( '.attachments .attachment' );
	const item = page.locator( '.attachments .attachment' ).first();
	const id = Number( await item.getAttribute( 'data-id' ) );

	await item.dragTo( h.row( page, 'Brand' ) );
	await expect( page.locator( '.cphfb-toast' ) ).toContainText( 'Moved 1 file to Brand' );
	await expect( h.row( page, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '1' );
	expect( await h.folderOf( page, id ) ).toBe( brand.id );
	await h.shot( page, 'grid-drag-toast' );

	// In the Brand view, dropping on Uncategorized removes it from the view.
	await h.row( page, 'Brand' ).click();
	await expect( page.locator( `.attachments .attachment[data-id="${ id }"]` ) ).toBeVisible();
	await page.locator( `.attachments .attachment[data-id="${ id }"]` ).dragTo( h.pinned( page, 'Uncategorized' ) );
	await expect( page.locator( `.attachments .attachment[data-id="${ id }"]` ) ).toHaveCount( 0 );
	expect( await h.folderOf( page, id ) ).toBe( 0 );
	await expect( h.row( page, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '0' );

	// Undo puts it back, in the view and on the server.
	await page.locator( '.cphfb-toast' ).getByRole( 'button', { name: 'Undo' } ).click();
	await expect( page.locator( `.attachments .attachment[data-id="${ id }"]` ) ).toBeVisible();
	await expect.poll( () => h.folderOf( page, id ) ).toBe( brand.id );
	await expect( h.row( page, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '1' );
	await h.rest( page, 'POST', '/assign', { folder: 0, ids: [ id ] } );
} );

test( 'grid: bulk select drag moves the whole selection; drop target highlights', async ( { page } ) => {
	const { events } = await h.seed( page );
	await page.reload();
	await page.waitForSelector( '.attachments .attachment' );
	await page.locator( '.select-mode-toggle-button' ).click();
	const items = page.locator( '.attachments .attachment' );
	const ids = [];
	for ( let i = 0; i < 3; i++ ) {
		await items.nth( i ).click();
		ids.push( Number( await items.nth( i ).getAttribute( 'data-id' ) ) );
	}

	// Hover shows the drop highlight.
	const target = h.row( page, 'Events' );
	await items.nth( 1 ).hover();
	await page.mouse.down();
	const box = await target.boundingBox();
	await page.mouse.move( box.x + 40, box.y + box.height / 2, { steps: 8 } );
	await expect( target ).toHaveAttribute( 'data-cphfb-drop', '' );
	await h.shot( page, 'grid-drag-highlight' );
	await page.mouse.up();

	await expect.poll( () => Promise.all( ids.map( ( id ) => h.folderOf( page, id ) ) ) ).toEqual( [ events.id, events.id, events.id ] );
	await expect( target.locator( '.cphfb-count' ) ).toHaveText( '3' );
	await expect( target ).not.toHaveAttribute( 'data-cphfb-drop', '' );
	await h.rest( page, 'POST', '/assign', { folder: 0, ids } );
} );

test( 'grid: "Move to folder…" in Bulk select and "Move selected files here"', async ( { page } ) => {
	const { products, gala } = await h.seed( page );
	await page.reload();
	await page.waitForSelector( '.attachments .attachment' );
	await expect( page.locator( '.cphfb-move-button' ) ).toBeHidden();
	await page.locator( '.select-mode-toggle-button' ).click();
	const move = page.locator( '.cphfb-move-button' );
	await expect( move ).toBeVisible();
	await expect( move ).toBeDisabled();

	const items = page.locator( '.attachments .attachment' );
	const ids = [ Number( await items.nth( 0 ).getAttribute( 'data-id' ) ), Number( await items.nth( 1 ).getAttribute( 'data-id' ) ) ];
	await items.nth( 0 ).click();
	await items.nth( 1 ).click();
	await move.click();
	const picker = page.locator( '.cphfb-picker' );
	await expect( picker ).toBeVisible();
	await expect( picker.locator( 'input' ) ).toBeFocused();
	await h.shot( page, 'grid-move-picker' );
	// Keyboard: filter, then Enter.
	await page.keyboard.type( 'prod' );
	await page.keyboard.press( 'Enter' );
	await expect( picker ).toBeHidden();
	await expect.poll( () => Promise.all( ids.map( ( id ) => h.folderOf( page, id ) ) ) ).toEqual( [ products.id, products.id ] );
	await expect( move ).toBeFocused();

	// Context menu on a folder row.
	await h.row( page, 'Gala' ).click( { button: 'right' } );
	await page.getByRole( 'menuitem', { name: 'Move 2 selected files here' } ).click();
	await expect.poll( () => Promise.all( ids.map( ( id ) => h.folderOf( page, id ) ) ) ).toEqual( [ gala.id, gala.id ] );
	await h.rest( page, 'POST', '/assign', { folder: 0, ids } );
} );

test( 'modal: drag and "Move to folder…" bar; selection bar in the column', async ( { page } ) => {
	const { brand, products } = await h.seed( page );
	await h.openBlockEditor( page );
	const frame = await openFrame( page, 'cphfbM' );
	await h.pinned( frame, 'All files' ).click();
	const items = frame.locator( '.attachments .attachment' );
	const ids = [ Number( await items.nth( 0 ).getAttribute( 'data-id' ) ), Number( await items.nth( 1 ).getAttribute( 'data-id' ) ) ];
	await items.nth( 0 ).click();
	await items.nth( 1 ).click();
	const bar = frame.locator( '.cphfb-selection-bar' );
	await expect( bar ).toContainText( '2 selected' );
	await h.shot( page, 'modal-selection-bar' );

	await items.nth( 0 ).dragTo( h.row( frame, 'Brand' ) );
	await expect.poll( () => Promise.all( ids.map( ( id ) => h.folderOf( page, id ) ) ) ).toEqual( [ brand.id, brand.id ] );
	// The modal's own selection is untouched.
	expect( await page.evaluate( () => window.cphfbM.state().get( 'selection' ).length ) ).toBe( 2 );

	await bar.getByRole( 'button' ).click();
	await page.locator( '.cphfb-picker [role="option"]', { hasText: 'Products' } ).click();
	await expect.poll( () => Promise.all( ids.map( ( id ) => h.folderOf( page, id ) ) ) ).toEqual( [ products.id, products.id ] );
	await expect( h.row( frame, 'Products' ).locator( '.cphfb-count' ) ).toHaveText( '2' );
	await h.rest( page, 'POST', '/assign', { folder: 0, ids } );
} );

test( 'details pane Folder select updates counts, model and view', async ( { page } ) => {
	const { brand } = await h.seed( page );
	await h.openBlockEditor( page );
	const frame = await openFrame( page, 'cphfbD', { multiple: false } );
	await h.pinned( frame, 'Uncategorized' ).click();
	const item = frame.locator( '.attachments .attachment' ).first();
	const id = Number( await item.getAttribute( 'data-id' ) );
	await item.click();
	const select = frame.locator( '.media-sidebar .cphfb-attachment-folder select' );
	await expect( select ).toBeVisible();
	await h.shot( page, 'modal-details-folder' );
	const saved = page.waitForResponse( ( r ) => ( r.request().postData() || '' ).includes( 'save-attachment-compat' ) );
	await select.selectOption( String( brand.id ) );
	await saved;
	await expect( h.row( frame, 'Brand' ).locator( '.cphfb-count' ) ).toHaveText( '1' );
	expect( await page.evaluate( ( i ) => window.wp.media.model.Attachments.all.get( i ).get( 'folder_id' ), id ) ).toBe( brand.id );
	// It no longer belongs in "Uncategorized".
	await expect( frame.locator( `.attachments .attachment[data-id="${ id }"]` ) ).toHaveCount( 0 );
	expect( await h.folderOf( page, id ) ).toBe( brand.id );
	await h.rest( page, 'POST', '/assign', { folder: 0, ids: [ id ] } );
} );

test( 'media-new.php: "Upload to" picker routes the upload', async ( { page } ) => {
	const { events } = await h.seed( page );
	await page.goto( '/wp-admin/media-new.php' );
	const picker = page.locator( '.cphfb-upload-folder select' );
	await expect( picker ).toBeVisible();
	await picker.selectOption( String( events.id ) );
	await h.shot( page, 'media-new' );
	const { id } = await h.uploadVia( page, page.locator( '#plupload-upload-ui input[type="file"], .moxie-shim input[type="file"]' ).first(), 'cphfb-media-new.png' );
	expect( await h.folderOf( page, id ) ).toBe( events.id );
	await h.rest( page, 'DELETE', `/wp/v2/media/${ id }?force=true` );
} );
