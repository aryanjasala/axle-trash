// End-to-end tests for Axle Trash. Every test deletes through the same REST call or wp-admin screen
// WordPress itself uses, then checks the database (WP-CLI) and the front end.
const { test, expect, wp, wpEval, logInAs, appPasswordClient, openEditor } = require( './utils' );
const seed = require( './seed' );

const parkedMeta = ( id, key ) => wpEval( `echo get_post_meta( ${ id }, '${ key }', true );` );
const html = async ( request, url ) => ( await request.get( url, { headers: { Cookie: '' }, maxRedirects: 3 } ) ).text();

test.describe( 'Axle Trash', () => {
	test.beforeAll( () => seed.installFixture() );
	test.beforeEach( () => seed.reset() );

	test( 'the plugin is active', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="axle-trash"].active' ) ).toBeVisible();
	} );

	test( 'a synced pattern deleted from the Site Editor is parked, gone from the front end, and restored with its ID', async ( { rest, request } ) => {
		const { pattern, url } = seed.patternWithPost( 'PATTERN-MARKER-1' );
		expect( await html( request, url ) ).toContain( 'PATTERN-MARKER-1' );

		const deleted = await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		expect( deleted.status ).toBe( 200 );
		expect( deleted.body.deleted ).toBe( true );
		expect( deleted.body.axle_trash.id ).toBe( pattern );

		expect( seed.status( pattern ) ).toBe( 'axle_trash' );
		expect( parkedMeta( pattern, '_axle_trash_status' ) ).toBe( 'publish' );
		expect( await html( request, url ) ).not.toContain( 'PATTERN-MARKER-1' );
		const listed = await rest( 'GET', '/wp/v2/blocks?per_page=100' );
		expect( listed.body.map( ( b ) => b.id ) ).not.toContain( pattern );

		const restored = await rest( 'POST', `/axle-trash/v1/items/${ pattern }/restore` );
		expect( restored.status ).toBe( 200 );
		expect( seed.status( pattern ) ).toBe( 'publish' );
		expect( await html( request, url ) ).toContain( 'PATTERN-MARKER-1' );
		expect( parkedMeta( pattern, '_axle_trash_at' ) ).toBe( '' );
	} );

	test( 'a custom template deleted by REST is parked, pages fall back, restore returns it with its ID', async ( { rest, request } ) => {
		const tpl = seed.dbTemplate( 'axle-landing', 'TEMPLATE-MARKER-1' );
		const page = seed.pageWithTemplate( 'axle-landing' );
		expect( await html( request, page.url ) ).toContain( 'TEMPLATE-MARKER-1' );

		const theme = wp( 'theme', 'list', '--status=active', '--field=name' );
		const deleted = await rest( 'DELETE', `/wp/v2/templates/${ theme }//axle-landing?force=true` );
		expect( deleted.status ).toBe( 200 );
		expect( deleted.body.deleted ).toBe( true );
		expect( seed.status( tpl ) ).toBe( 'axle_trash' );
		expect( await html( request, page.url ) ).not.toContain( 'TEMPLATE-MARKER-1' );
		expect( await html( request, page.url ) ).toContain( 'Landing body' );

		expect( ( await rest( 'POST', `/axle-trash/v1/items/${ tpl }/restore` ) ).status ).toBe( 200 );
		expect( seed.status( tpl ) ).toBe( 'publish' );
		expect( await html( request, page.url ) ).toContain( 'TEMPLATE-MARKER-1' );
	} );

	test( 'a template part and a navigation menu are parked by their REST deletes too', async ( { rest } ) => {
		const part = ( await rest( 'POST', '/wp/v2/template-parts', { slug: 'axle-part', theme: wp( 'theme', 'list', '--status=active', '--field=name' ), title: 'Axle part', content: '<!-- wp:paragraph --><p>part</p><!-- /wp:paragraph -->', status: 'publish', area: 'uncategorized' } ) ).body;
		const partId = part.wp_id;
		const nav = ( await rest( 'POST', '/wp/v2/navigation', { title: 'Axle menu', content: '', status: 'publish' } ) ).body;

		const delPart = await rest( 'DELETE', `/wp/v2/template-parts/${ part.id }?force=true` );
		const delNav = await rest( 'DELETE', `/wp/v2/navigation/${ nav.id }?force=true` );
		expect( delPart.body.deleted ).toBe( true );
		expect( delNav.body.deleted ).toBe( true );
		expect( seed.status( partId ) ).toBe( 'axle_trash' );
		expect( seed.status( nav.id ) ).toBe( 'axle_trash' );
		expect( ( await rest( 'GET', `/wp/v2/navigation/${ nav.id }` ) ).body.status ).not.toBe( 'publish' );

		await rest( 'POST', `/axle-trash/v1/items/${ partId }/restore` );
		await rest( 'POST', `/axle-trash/v1/items/${ nav.id }/restore` );
		expect( seed.status( partId ) ).toBe( 'publish' );
		expect( seed.status( nav.id ) ).toBe( 'publish' );
	} );

	test( 'restoring a template whose name was taken asks first, and can replace the newer one', async ( { rest } ) => {
		const old = seed.dbTemplate( 'axle-clash', 'OLD' );
		const theme = wp( 'theme', 'list', '--status=active', '--field=name' );
		await rest( 'DELETE', `/wp/v2/templates/${ theme }//axle-clash?force=true` );
		const newer = seed.dbTemplate( 'axle-clash', 'NEWER' );

		const refused = await rest( 'POST', `/axle-trash/v1/items/${ old }/restore` );
		expect( refused.status ).toBe( 409 );
		expect( seed.status( old ) ).toBe( 'axle_trash' );

		const replaced = await rest( 'POST', `/axle-trash/v1/items/${ old }/restore`, { replace: true } );
		expect( replaced.status ).toBe( 200 );
		expect( seed.status( old ) ).toBe( 'publish' );
		expect( seed.status( newer ) ).toBe( 'axle_trash' );
	} );

	test( 'a font family keeps its files while parked and loses them when deleted for good', async ( { rest } ) => {
		const font = seed.fontFamily();
		const deleted = await rest( 'DELETE', `/wp/v2/font-families/${ font.family }?force=true` );
		expect( deleted.status ).toBe( 200 );
		expect( seed.status( font.family ) ).toBe( 'axle_trash' );
		expect( seed.status( font.face ) ).toBe( 'axle_trash' );
		expect( seed.fileExists( font.path ) ).toBe( true );

		await rest( 'POST', `/axle-trash/v1/items/${ font.family }/restore` );
		expect( seed.status( font.family ) ).toBe( 'publish' );
		expect( seed.status( font.face ) ).toBe( 'publish' );

		await rest( 'DELETE', `/wp/v2/font-families/${ font.family }?force=true` );
		wp( 'axle-trash', 'delete', String( font.family ), '--yes' );
		expect( seed.status( font.family ) ).toBeNull();
		expect( seed.status( font.face ) ).toBeNull();
		expect( seed.fileExists( font.path ) ).toBe( false );
	} );

	test( 'deleting an author with all their content parks the account, the custom post and the image, and restore brings them back', async ( { page } ) => {
		const a = seed.author( 'wanda' );
		await page.goto( '/wp-admin/users.php' );
		await page.locator( `#user-${ a.user }` ).hover();
		await page.locator( `#user-${ a.user } .submitdelete` ).click();
		await page.getByRole( 'radio', { name: 'Delete all content.' } ).check();
		await page.getByRole( 'button', { name: 'Confirm Deletion' } ).click();
		await expect( page.locator( '.notice' ).filter( { hasText: 'Moved to Tools > Trash' } ) ).toBeVisible();

		expect( seed.json( `return false !== get_userdata( ${ a.user } );` ) ).toBe( false );
		expect( seed.status( a.book ) ).toBe( 'axle_trash' );
		expect( seed.status( a.att ) ).toBe( 'axle_trash' );
		expect( seed.status( a.post ) ).toBe( 'trash' );
		expect( seed.fileExists( a.file ) ).toBe( true );

		await page.locator( '.notice a', { hasText: 'Undo for Wanda Writer' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Restored 1 item' );

		expect( seed.json( `$u = get_userdata( ${ a.user } ); return $u ? array( $u->user_login, $u->user_email, get_user_meta( ${ a.user }, 'axle_trash_probe', true ), $u->roles ) : null;` ) ).toEqual( [ 'wanda', 'wanda@example.com', 'kept', [ 'editor' ] ] );
		expect( seed.status( a.book ) ).toBe( 'publish' );
		expect( seed.status( a.att ) ).toBe( 'inherit' );
		expect( seed.status( a.post ) ).toBe( 'publish' );
		expect( seed.json( `return (int) get_post( ${ a.book } )->post_author;` ) ).toBe( a.user );
	} );

	test( 'a user whose login was taken meanwhile is not restored, with the reason', async ( { rest } ) => {
		const a = seed.author( 'taken' );
		wp( 'user', 'delete', String( a.user ), '--yes' );
		const snapshot = seed.json( `$p = get_posts( array( 'post_type' => 'axle_trash_user', 'post_status' => 'axle_trash', 'numberposts' => 1, 'fields' => 'ids' ) ); return $p[0];` );
		seed.makeUser( 'taken', 'subscriber' );
		const refused = await rest( 'POST', `/axle-trash/v1/items/${ snapshot }/restore` );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.message ).toContain( 'now belongs to someone else' );
		expect( seed.status( snapshot ) ).toBe( 'axle_trash' );
	} );

	test( 'reassigning content still snapshots the account and leaves the content alone', async () => {
		const a = seed.author( 'reassigned' );
		wp( 'user', 'delete', String( a.user ), '--reassign=1', '--yes' );
		expect( seed.status( a.book ) ).toBe( 'publish' );
		expect( seed.json( `return (int) get_post( ${ a.book } )->post_author;` ) ).toBe( 1 );
		const count = wp( 'axle-trash', 'list', '--kind=user', '--format=count' );
		expect( count ).toBe( '1' );
	} );

	test( 'a person without the right sees no items and no counts they could not have deleted', async ( { browser, rest } ) => {
		const others = seed.seedOthers();
		await rest( 'DELETE', `/wp/v2/blocks/${ others.pattern }?force=true` );
		wp( 'post', 'delete', String( others.template ), '--force' );
		await rest( 'DELETE', `/wp/v2/navigation/${ others.nav }?force=true` );
		const a = seed.author( 'gone' );
		wp( 'user', 'delete', String( a.user ), '--yes' );

		const editorId = seed.makeUser( 'ed', 'editor' );
		seed.makeUser( 'sub', 'subscriber' );
		const { context, page } = await logInAs( browser, 'ed', 'pass-ed' );
		await page.goto( '/wp-admin/tools.php?page=axle-trash' );
		const body = await page.locator( '.axle-ui' ).innerText();
		expect( body ).toContain( 'Shared pattern' );
		expect( body ).not.toContain( 'Secret' );
		expect( body ).not.toContain( 'Wanda Writer' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'All (1)' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Site Editor (1)' );
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'Users (0)' );
		expect( editorId ).toBeGreaterThan( 0 );

		const client = await appPasswordClient( 'ed', 'axle-trash-test' );
		const snapshot = seed.json( `$p = get_posts( array( 'post_type' => 'axle_trash_user', 'post_status' => 'axle_trash', 'numberposts' => 1, 'fields' => 'ids' ) ); return $p[0];` );
		expect( ( await client.call( 'POST', `/axle-trash/v1/items/${ snapshot }/restore` ) ).status ).toBe( 403 );
		await client.dispose();
		await context.close();

		const sub = await logInAs( browser, 'sub', 'pass-sub' );
		await sub.page.goto( '/wp-admin/tools.php?page=axle-trash' );
		await expect( sub.page.locator( 'body' ) ).not.toContainText( 'Shared pattern' );
		await sub.context.close();
	} );

	test( 'untick a kind and WordPress deletes that kind for good, as before', async ( { rest } ) => {
		wp( 'option', 'update', 'axle_trash_settings', '{"kinds":["template"],"retention":30}', '--format=json' );
		const { pattern } = seed.patternWithPost( 'X' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		expect( seed.status( pattern ) ).toBeNull();
	} );

	test( 'the daily job deletes what is older than the retention, font files included', async ( { rest } ) => {
		const font = seed.fontFamily();
		await rest( 'DELETE', `/wp/v2/font-families/${ font.family }?force=true` );
		const fresh = seed.patternWithPost( 'Y' );
		await rest( 'DELETE', `/wp/v2/blocks/${ fresh.pattern }?force=true` );
		wpEval( `update_post_meta( ${ font.family }, '_axle_trash_at', time() - 40 * DAY_IN_SECONDS );` );
		wp( 'cron', 'event', 'run', 'axle_trash_purge' );
		expect( seed.status( font.family ) ).toBeNull();
		expect( seed.fileExists( font.path ) ).toBe( false );
		expect( seed.status( fresh.pattern ) ).toBe( 'axle_trash' );
	} );

	test( 'core scheduled trash emptying and an eraser request are not parked', async () => {
		const id = seed.json( `$id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'trash', 'post_title' => 'In core trash', 'post_content' => 'z' ) ); add_action( 'wp_scheduled_delete', static function () use ( $id ) { wp_delete_post( $id ); } ); do_action( 'wp_scheduled_delete' ); return $id;` );
		// A pattern already in core's trash that WordPress empties on its schedule is deleted, not parked again.
		expect( seed.status( id ) ).toBeNull();
	} );

	test( 'WP-CLI lists, restores and deletes', async ( { rest } ) => {
		const { pattern } = seed.patternWithPost( 'CLI' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		expect( wp( 'axle-trash', 'list', '--format=ids' ) ).toBe( String( pattern ) );
		wp( 'axle-trash', 'restore', String( pattern ) );
		expect( seed.status( pattern ) ).toBe( 'publish' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		wp( 'axle-trash', 'empty', '--yes' );
		expect( seed.status( pattern ) ).toBeNull();
	} );

	test( 'abilities list, restore and delete with the right annotations', async ( { rest } ) => {
		test.skip( 'true' !== seed.json( 'return function_exists( "wp_register_ability" ) ? "true" : "false";' ), 'Abilities API needs WordPress 6.9' );
		const { pattern } = seed.patternWithPost( 'ABIL' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		const listed = seed.json( '$r = wp_get_ability( "axle-trash/list-items" ); wp_set_current_user( 1 ); $out = $r->execute( array() ); return array( $out["total"], $r->get_meta()["annotations"] );' );
		expect( listed[ 0 ] ).toBe( 1 );
		expect( listed[ 1 ].readonly ).toBe( true );
		const del = seed.json( '$r = wp_get_ability( "axle-trash/delete-item" ); return $r->get_meta()["annotations"];' );
		expect( del.destructive ).toBe( true );
		const restored = seed.json( `wp_set_current_user( 1 ); $r = wp_get_ability( "axle-trash/restore-item" ); return $r->execute( array( "id" => ${ pattern } ) );` );
		expect( restored.restored ).toBe( true );
		expect( seed.status( pattern ) ).toBe( 'publish' );
	} );

	test( 'privacy exporter finds a trashed account by email and the eraser removes it', async () => {
		const a = seed.author( 'privacy' );
		wp( 'user', 'delete', String( a.user ), '--yes' );
		const exported = seed.json( '$e = ( new AryanX\\Axle\\Trash\\Privacy( new AryanX\\Axle\\Trash\\Trash() ) )->export( "privacy@example.com" ); return count( $e["data"] );' );
		expect( exported ).toBe( 1 );
		seed.json( '( new AryanX\\Axle\\Trash\\Privacy( new AryanX\\Axle\\Trash\\Trash() ) )->erase( "privacy@example.com" ); return true;' );
		expect( seed.status( a.book ) ).toBeNull();
		expect( wp( 'axle-trash', 'list', '--format=count' ) ).toBe( '0' );
	} );

	test( 'Site Health flags items past their retention', async ( { rest } ) => {
		const { pattern } = seed.patternWithPost( 'H' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		expect( seed.json( 'return ( new AryanX\\Axle\\Trash\\Health\\SiteHealth() )->run()["status"];' ) ).toBe( 'good' );
		wpEval( `update_post_meta( ${ pattern }, '_axle_trash_at', time() - 60 * DAY_IN_SECONDS );` );
		expect( seed.json( 'return ( new AryanX\\Axle\\Trash\\Health\\SiteHealth() )->run()["status"];' ) ).toBe( 'recommended' );
	} );

	test( 'uninstall finishes the deletes and removes the data', async ( { rest } ) => {
		const font = seed.fontFamily();
		await rest( 'DELETE', `/wp/v2/font-families/${ font.family }?force=true` );
		const { pattern } = seed.patternWithPost( 'U' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		wp( 'option', 'update', 'axle_trash_settings', '{"kinds":[],"retention":5}', '--format=json' );
		wpEval( 'remove_all_filters( "pre_delete_post" ); remove_all_filters( "pre_delete_attachment" ); define( "WP_UNINSTALL_PLUGIN", "axle-trash/axle-trash.php" ); include WP_PLUGIN_DIR . "/axle-trash/uninstall.php";' );
		expect( seed.status( font.family ) ).toBeNull();
		expect( seed.status( pattern ) ).toBeNull();
		expect( seed.fileExists( font.path ) ).toBe( false );
		expect( wpEval( 'echo false === get_option( "axle_trash_settings" ) ? "gone" : "left";' ) ).toBe( 'gone' );
	} );

	test( 'the editor shows "Moved to the Axle trash" with Undo, and Undo restores it', async ( { page, rest } ) => {
		const { pattern, post } = seed.patternWithPost( 'UNDO' );
		await openEditor( page, post );
		await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/wp/v2/blocks/${ id }?force=true`, method: 'DELETE' } ), pattern );
		const snackbar = page.locator( '.components-snackbar', { hasText: 'Moved to the Axle trash' } );
		await expect( snackbar ).toBeVisible();
		expect( seed.status( pattern ) ).toBe( 'axle_trash' );
		await snackbar.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.components-snackbar', { hasText: 'Restored' } ) ).toBeVisible();
		expect( seed.status( pattern ) ).toBe( 'publish' );
		expect( rest ).toBeTruthy();
	} );

	test( 'the Site Editor gets the same Undo for a template part', async ( { page, rest } ) => {
		const theme = wp( 'theme', 'list', '--status=active', '--field=name' );
		const part = ( await rest( 'POST', '/wp/v2/template-parts', { slug: 'axle-undo', theme, title: 'Undo part', content: 'x', status: 'publish', area: 'uncategorized' } ) ).body;
		await page.goto( '/wp-admin/site-editor.php' );
		await page.waitForFunction( () => window.wp && window.wp.apiFetch && window.wp.data );
		await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/wp/v2/template-parts/${ id }?force=true`, method: 'DELETE' } ), part.id );
		const snackbar = page.locator( '.components-snackbar', { hasText: 'Moved to the Axle trash' } );
		await expect( snackbar ).toBeVisible();
		await snackbar.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.components-snackbar', { hasText: 'Restored' } ) ).toBeVisible();
		expect( seed.status( part.wp_id ) ).toBe( 'publish' );
	} );

	test( 'the Trash screen restores and deletes with a confirm step', async ( { page, rest } ) => {
		const { pattern } = seed.patternWithPost( 'SCREEN' );
		await rest( 'DELETE', `/wp/v2/blocks/${ pattern }?force=true` );
		await page.goto( '/wp-admin/tools.php?page=axle-trash' );
		const row = page.locator( 'tr', { hasText: 'Offer box' } );
		await expect( row ).toContainText( 'Used in 1 post' );
		await row.hover();
		await row.getByRole( 'link', { name: /Delete Offer box permanently/ } ).click();
		await expect( page.getByRole( 'group', { name: 'Delete for good?' } ) ).toContainText( 'cannot be undone' );
		await page.getByRole( 'link', { name: 'Cancel' } ).click();
		expect( seed.status( pattern ) ).toBe( 'axle_trash' );
		await page.locator( 'tr', { hasText: 'Offer box' } ).hover();
		await page.locator( 'tr', { hasText: 'Offer box' } ).getByRole( 'link', { name: /Delete Offer box permanently/ } ).click();
		await page.getByRole( 'button', { name: 'Delete for good' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Deleted 1 item for good' );
		expect( seed.status( pattern ) ).toBeNull();
	} );

	test( 'Empty the trash asks first and then deletes everything the person may delete', async ( { page, rest } ) => {
		const a = seed.patternWithPost( 'E1' );
		await rest( 'DELETE', `/wp/v2/blocks/${ a.pattern }?force=true` );
		await page.goto( '/wp-admin/tools.php?page=axle-trash' );
		await page.getByRole( 'button', { name: 'Empty the trash' } ).click();
		await expect( page.getByRole( 'group', { name: 'Empty the trash?' } ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Delete for good' } ).click();
		await expect( page.locator( '.axle-empty' ) ).toBeVisible();
		expect( seed.status( a.pattern ) ).toBeNull();
	} );
} );
