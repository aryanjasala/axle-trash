// End-to-end tests for Axle Trash. Check every outcome in the database through WP-CLI as well as on screen.
const { test, expect } = require( '@playwright/test' );

test.describe( 'Axle Trash', () => {
	test( 'the plugin is active', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="axle-trash"].active' ) ).toBeVisible();
	} );
} );
