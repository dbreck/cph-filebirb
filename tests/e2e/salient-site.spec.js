/**
 * Phase 3: every media picker on a real Salient + WPBakery site.
 *
 * Skipped unless CPHFB_SITE_URL and CPHFB_SITE_WP are set. Read-mostly: opens
 * editors and pickers and never saves a post, page or option (autosave and
 * heartbeat requests are blocked). The only writes are one `zz-cphfb-test-*`
 * folder and one uploaded test image, both removed at the end.
 *
 *   CPHFB_SITE_URL   Site URL, e.g. https://example.local
 *   CPHFB_SITE_WP    WP-CLI command prefix for that site, run through your login
 *                    shell (so shell functions work), e.g. "wp --path=/path/to/site"
 *   CPHFB_SITE_USER  Administrator user ID to mint auth cookies for (default 1)
 *   CPHFB_SITE_PAGE  ID of a page edited with the WPBakery backend editor
 *                    (editor tests skip without it)
 *   CPHFB_SITE_BLOCK_POST  ID of a post that uses the block editor (optional)
 *
 * The site needs at least one folder with files in it.
 */
const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const h = require( './helpers' );

const BASE = process.env.CPHFB_SITE_URL || '';
const WP = process.env.CPHFB_SITE_WP || '';
const ADMIN_ID = Number( process.env.CPHFB_SITE_USER || 1 );
const PAGE_ID = Number( process.env.CPHFB_SITE_PAGE || 0 );
const BLOCK_POST_ID = Number( process.env.CPHFB_SITE_BLOCK_POST || 0 );

test.skip( ! BASE || ! WP, 'Set CPHFB_SITE_URL and CPHFB_SITE_WP to run against a real site.' );
test.use( { baseURL: BASE || undefined, ignoreHTTPSErrors: true } );
// Real editors with a real library are slower than wp-env.
test.setTimeout( 180000 );

/** Auth cookies for an existing administrator, minted with WP-CLI (no password needed). */
function mintCookies() {
	const file = path.join( os.tmpdir(), `cphfb-mint-${ process.pid }.php` );
	fs.writeFileSync(
		file,
		`<?php $e = time() + 7200; $t = WP_Session_Tokens::get_instance( ${ ADMIN_ID } )->create( $e );
echo json_encode( array( SECURE_AUTH_COOKIE => wp_generate_auth_cookie( ${ ADMIN_ID }, $e, 'secure_auth', $t ), LOGGED_IN_COOKIE => wp_generate_auth_cookie( ${ ADMIN_ID }, $e, 'logged_in', $t ) ) );`
	);
	try {
		const shell = process.env.SHELL || 'sh';
		const out = execFileSync( shell, [ '-ic', `${ WP } eval-file '${ file }'` ] ).toString();
		const jar = JSON.parse( out.match( /\{.*\}/ )[ 0 ] );
		const domain = new URL( BASE ).hostname;
		return Object.entries( jar ).map( ( [ name, value ] ) => ( { name, value, domain, path: '/', secure: BASE.startsWith( 'https' ), httpOnly: true } ) );
	} finally {
		fs.unlinkSync( file );
	}
}

/** The site's folder holding the most files (from the page data), as `{ id, name, count }`. */
async function busiestFolder( page ) {
	const folder = await page.evaluate( () => {
		const out = [];
		const walk = ( nodes ) => nodes.forEach( ( n ) => {
			out.push( { id: Number( n.id ), name: n.name, count: Number( n.count || 0 ) } );
			walk( n.children || [] );
		} );
		walk( window.cphfbData?.tree || [] );
		return out.sort( ( a, b ) => b.count - a.count )[ 0 ] || null;
	} );
	expect( folder && folder.count, 'a folder with files' ).toBeGreaterThan( 0 );
	return folder;
}

let cookies;
let errors;

test.beforeAll( () => {
	cookies = mintCookies();
} );

test.beforeEach( async ( { page, context } ) => {
	await context.addCookies( cookies );
	// Third-party noise on this site that is not ours.
	errors = h.trackErrors( page, [ /favicon/, /net::ERR_/, /status of 404/ ] );
	// Leaving an editor with unsaved builder changes asks first; we never save.
	page.on( 'dialog', ( d ) => d.accept() );
	// Never let the editors autosave: drop heartbeat (classic autosave rides on it) and REST autosaves.
	await page.route( /admin-ajax\.php|autosaves/, ( route ) => {
		const request = route.request();
		const body = request.postData() || '';
		if ( /autosaves/.test( request.url() ) || /action=heartbeat|wp_autosave|action=autosave/.test( body ) ) {
			return route.abort();
		}
		return route.continue();
	} );
} );

test.afterEach( async () => {
	expect( errors, 'console errors' ).toEqual( [] );
} );

/** Classic/WPBakery editor for the test page, with autosave switched off. */
async function openClassicEditor( page ) {
	test.skip( ! PAGE_ID, 'Set CPHFB_SITE_PAGE to a WPBakery page ID.' );
	await page.goto( `/wp-admin/post.php?post=${ PAGE_ID }&action=edit` );
	await page.waitForFunction( () => window.wp?.media && window.cphfb && window.vc );
	await page.evaluate( () => {
		window.wp?.autosave?.server?.suspend?.();
		window.jQuery?.( window ).off( 'beforeunload' );
	} );
}

/**
 * The open media modal has our column; picking the busiest folder filters by it
 * and shows only its files. Then close the modal without choosing anything.
 */
async function expectTree( page, name ) {
	const target = await busiestFolder( page );
	const frame = h.modal( page );
	await expect( frame ).toBeVisible();
	const browse = frame.locator( '#menu-item-browse' );
	if ( ( await browse.count() ) && ( await browse.getAttribute( 'aria-selected' ) ) !== 'true' ) {
		await browse.click();
	}
	await expect( frame.locator( '.cphfb-frame-tree [role="tree"]' ) ).toBeVisible();
	const folderRow = h.row( frame, target.name );
	if ( ( await folderRow.getAttribute( 'aria-selected' ) ) === 'true' ) {
		await h.pinned( frame, 'All files' ).click();
	}
	const count = Number( await folderRow.locator( '.cphfb-count' ).innerText() );
	const query = h.nextQuery( page );
	await folderRow.click();
	const body = await query;
	expect( body ).toContain( `query[fbv]=${ target.id }` );
	await expect( folderRow ).toHaveAttribute( 'aria-selected', 'true' );
	await page.waitForTimeout( 1200 );
	const shown = await frame.locator( '.attachments .attachment' ).count();
	expect( shown ).toBeLessThanOrEqual( count );
	await h.shot( page, `site-${ name }` );
	// Back to All files so the next picker starts unfiltered.
	await h.pinned( frame, 'All files' ).click();
	await frame.locator( '.media-modal-close' ).click();
	await expect( frame ).toBeHidden();
}

/** WPBakery: add an element (unsaved) and open its first image param's picker. */
async function openVcImageParam( page, element ) {
	await page.locator( '#vc_add-new-element' ).click();
	await page.locator( `.vc_add-element-container [data-element="${ element }"]` ).click();
	await clickImageParam( page );
}

/** Click the first image param in the open edit panel, switching to its tab first. */
async function clickImageParam( page ) {
	const panel = page.locator( '.vc_ui-panel-window.vc_active' );
	const add = panel.locator( '.gallery_widget_add_images' ).first();
	await expect( add ).toBeAttached();
	if ( ! ( await add.isVisible() ) ) {
		const tab = await add.evaluate( ( el ) => el.closest( '[id^="vc_edit-form-tab-"]' )?.id );
		await panel.locator( `[data-vc-ui-element-target="#${ tab }"]` ).click();
	}
	await add.click();
}

async function closeVcPanel( page ) {
	const close = page.locator( '.vc_ui-panel-window.vc_active' ).getByText( 'Close', { exact: true } ).first();
	await close.click( { timeout: 5000 } );
	await expect( page.locator( '.vc_ui-panel-window.vc_active' ) ).toHaveCount( 0 );
}

test( 'Media Library grid and list', async ( { page } ) => {
	await page.goto( '/wp-admin/upload.php?mode=grid' );
	const target = await busiestFolder( page );
	const folderRow = h.row( page, target.name );
	await expect( folderRow ).toBeVisible();
	if ( ( await folderRow.getAttribute( 'aria-selected' ) ) === 'true' ) {
		await h.pinned( page, 'All files' ).click();
	}
	const query = h.nextQuery( page );
	await folderRow.click();
	expect( await query ).toContain( `query[fbv]=${ target.id }` );
	await h.shot( page, 'site-grid' );
	await h.pinned( page, 'All files' ).click();
	await page.waitForTimeout( 900 );

	await page.goto( `/wp-admin/upload.php?mode=list&fbv=${ target.id }` );
	await expect( h.row( page, target.name ) ).toHaveAttribute( 'aria-selected', 'true' );
	const rows = await page.locator( '.wp-list-table tbody tr:not(.no-items)' ).count();
	expect( rows ).toBeGreaterThan( 0 );
	expect( rows ).toBeLessThanOrEqual( target.count );
	await h.shot( page, 'site-list' );
	await Promise.all( [ page.waitForURL( /fbv=-1/ ), h.pinned( page, 'All files' ).click() ] );
} );

test( 'featured image (classic metabox)', async ( { page } ) => {
	await openClassicEditor( page );
	await page.locator( '#set-post-thumbnail' ).click();
	await expectTree( page, 'featured' );
} );

test( 'classic "Add Media" (Insert Media, gallery create)', async ( { page } ) => {
	await openClassicEditor( page );
	// The WPBakery backend editor hides the classic editor; Classic Mode is a UI toggle (saved only on Update).
	if ( await page.locator( '.wpb_switch-to-composer', { hasText: 'Classic Mode' } ).isVisible() ) {
		await page.locator( '.wpb_switch-to-composer' ).click();
	}
	await page.locator( '#insert-media-button' ).click();
	await expectTree( page, 'add-media' );
	await page.locator( '#insert-media-button' ).click();
	await h.modal( page ).locator( '#menu-item-gallery' ).click();
	await expectTree( page, 'gallery-create' );
} );

test( 'WPBakery backend: image_with_animation, vc_gallery, image with hotspots, row background', async ( { page } ) => {
	await openClassicEditor( page );
	for ( const element of [ 'image_with_animation', 'vc_gallery', 'nectar_image_with_hotspots' ] ) {
		await test.step( element, async () => {
			await openVcImageParam( page, element );
			await expectTree( page, `vc-${ element }` );
			await closeVcPanel( page );
		} );
	}
	// Row settings: background image (on the Background tab).
	await page.locator( 'a[title="Edit this row"]' ).first().click();
	await clickImageParam( page );
	await expectTree( page, 'vc-row-bg' );
	await closeVcPanel( page );
	await page.evaluate( () => window.jQuery?.( window ).off( 'beforeunload' ) );
} );

test( 'Salient page header image and gallery metabox', async ( { page } ) => {
	await openClassicEditor( page );
	const header = page.locator( '#nectar-metabox-page-header .nectar-add-btn' ).first();
	await expect( header ).toBeAttached();
	await header.evaluate( ( el ) => el.click() );
	await expectTree( page, 'page-header' );

	// Salient's own gallery metabox opens wp.media.gallery.edit (edit state: no tree), then "Add to gallery" browses.
	const gallery = page.locator( '#edit-gal' );
	if ( await gallery.count() ) {
		await gallery.evaluate( ( el ) => el.click() );
		const frame = h.modal( page );
		await expect( frame ).toBeVisible();
		await frame.locator( '.media-menu-item', { hasText: /Add to/ } ).first().click();
		await expectTree( page, 'salient-gallery-add' );
	}
} );

test( 'WPBakery frontend editor: image_with_animation', async ( { page } ) => {
	await page.goto( `/wp-admin/post.php?vc_action=vc_inline&post_id=${ PAGE_ID }&post_type=page` );
	await page.waitForFunction( () => window.vc && window.wp?.media && window.cphfb, null, { timeout: 30000 } );
	await page.evaluate( () => window.jQuery?.( window ).off( 'beforeunload' ) );
	await openVcImageParam( page, 'image_with_animation' );
	await expectTree( page, 'vc-frontend' );
	await closeVcPanel( page );
} );

test( 'Salient theme options (Redux) media field: open only', async ( { page } ) => {
	// Find the theme options page from the admin menu, then the section holding a media field.
	await page.goto( '/wp-admin/' );
	const options = await page.evaluate( () =>
		[ ...document.querySelectorAll( '#adminmenu a' ) ].map( ( a ) => a.getAttribute( 'href' ) ).find( ( href ) => /admin\.php\?page=Salient/i.test( href || '' ) )
	);
	test.skip( ! options, 'No Salient theme options page on this site.' );
	await page.goto( '/wp-admin/' + options.replace( /&tab=\d+/, '' ) );
	await page.waitForFunction( () => window.wp?.media && window.cphfb && document.querySelector( '.media_upload_button' ) );
	const tab = await page.evaluate( () => document.querySelector( '.media_upload_button' ).closest( '.redux-group-tab' )?.id.replace( /_section_group$/, '' ) );
	await page.goto( '/wp-admin/' + options.replace( /&tab=\d+/, '' ) + '&tab=' + tab );
	// Redux binds each media field when its section initialises: use one on the open tab.
	await page.waitForLoadState( 'networkidle' );
	const button = page.locator( '.media_upload_button:visible' ).first();
	await expect( button ).toBeVisible();
	await button.click();
	await expectTree( page, 'redux' );
	await page.evaluate( () => window.jQuery?.( window ).off( 'beforeunload' ) );
} );

test( 'block editor: image block "Media Library"', async ( { page } ) => {
	// An existing block-editor post; autosave is blocked and nothing is saved.
	test.skip( ! BLOCK_POST_ID, 'Set CPHFB_SITE_BLOCK_POST to a block-editor post ID.' );
	await page.goto( `/wp-admin/post.php?post=${ BLOCK_POST_ID }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' ) && window.wp?.blocks && window.wp?.media && window.cphfb );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/editor' ).lockPostAutosaving( 'cphfb-test' );
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( window.wp.blocks.createBlock( 'core/image' ) );
	} );
	const inCanvas = await page.locator( 'iframe[name="editor-canvas"]' ).count();
	const scope = inCanvas ? page.frameLocator( 'iframe[name="editor-canvas"]' ) : page;
	await scope.getByRole( 'button', { name: 'Media Library' } ).first().click();
	await expectTree( page, 'block-image' );
	await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).resetEditorBlocks?.( window.wp.data.select( 'core/editor' ).getEditorBlocks().slice( 0, -1 ) ) );
} );

test( 'upload into a test folder, assign, unassign, clean up', async ( { page } ) => {
	await openClassicEditor( page );
	const name = 'zz-cphfb-test-' + Date.now();
	const folder = await h.rest( page, 'POST', '/folders', { name, parent: 0 } );
	let attachment = 0;
	try {
		await page.reload();
		await page.waitForFunction( () => window.wp?.media && window.cphfb );
		await page.evaluate( () => window.wp?.autosave?.server?.suspend?.() );
		await page.locator( '#set-post-thumbnail' ).click();
		const frame = h.modal( page );
		const browse = frame.locator( '#menu-item-browse' );
		if ( ( await browse.getAttribute( 'aria-selected' ) ) !== 'true' ) {
			await browse.click();
		}
		await h.row( frame, name ).click();
		await frame.locator( '#menu-item-upload' ).click();
		await expect( frame.locator( '.cphfb-upload-folder select' ) ).toHaveValue( String( folder.id ) );
		const up = await h.uploadVia( page, page.locator( '.moxie-shim input[type="file"]' ).last(), 'zz-cphfb-test.png' );
		attachment = up.id;
		expect( await h.folderOf( page, attachment ) ).toBe( folder.id );
		await expect( h.row( frame, name ).locator( '.cphfb-count' ) ).toHaveText( '1', { timeout: 10000 } );

		// Unassign through the UI: drop on Uncategorized.
		await frame.locator( '#menu-item-browse' ).click();
		const item = frame.locator( '.attachments .attachment' ).first();
		await item.dragTo( h.pinned( frame, 'Uncategorized' ) );
		await expect.poll( () => h.folderOf( page, attachment ) ).toBe( 0 );
		await frame.locator( '.media-modal-close' ).click();
	} finally {
		if ( attachment ) {
			await h.rest( page, 'DELETE', `/wp/v2/media/${ attachment }?force=true` );
		}
		await h.rest( page, 'DELETE', `/folders/${ folder.id }?mode=subtree` );
	}
	const { tree } = await h.rest( page, 'GET', '/folders?include_counts=0' );
	expect( tree.some( ( n ) => n.name === name ) ).toBe( false );
} );
