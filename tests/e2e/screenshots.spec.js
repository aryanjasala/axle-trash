// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const fs = require( 'fs' );
const { test, expect } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { populate, installFixture } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-trash';
const AFTER = process.env.AXLE_SHOTS_DIR || '';

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		installFixture();
		populate();
		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
	} );

	test( '1: what was deleted, who deleted it, and what still uses it', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( 'tr', { hasText: 'Offer box' } ) ).toBeVisible();
		await shot( page, 1 );
		if ( AFTER ) {
			fs.mkdirSync( AFTER, { recursive: true } );
			await page.screenshot( { path: `${ AFTER }/trash-1280.png`, fullPage: true } );
			await page.setViewportSize( { width: 390, height: 844 } );
			await page.goto( SCREEN );
			await page.screenshot( { path: `${ AFTER }/trash-390.png`, fullPage: true } );
			await page.setViewportSize( { width: 1280, height: 900 } );
		}
	} );

	test( '2: deleting for good asks first', async () => {
		await page.goto( SCREEN );
		await page.locator( 'tr', { hasText: 'Offer box' } ).getByRole( 'link', { name: /Delete Offer box permanently/ } ).click();
		await expect( page.locator( '.axle-confirm' ) ).toBeVisible();
		await shot( page, 2 );
	} );

	test( '3: a deleted account with its content', async () => {
		await page.goto( `${ SCREEN }&view=users` );
		await expect( page.locator( 'tr', { hasText: 'Wanda Writer' } ) ).toContainText( 'Authored 1 item' );
		await shot( page, 3 );
	} );

	test( '4: a newer template took the name', async () => {
		await page.goto( `${ SCREEN }&view=site-editor` );
		await page.getByRole( 'link', { name: /Restore Axle clash/ } ).click();
		await expect( page.locator( '.axle-confirm' ) ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: settings', async () => {
		await page.goto( SCREEN );
		await page.locator( '.axle-card' ).scrollIntoViewIfNeeded();
		await shot( page, 5 );
	} );
} );
