// Shared set-up for the Axle Trash specs and the accessibility checks.
const { wp, wpEval } = require( './utils' );

const json = ( php ) => JSON.parse( wpEval( `echo wp_json_encode( ( function () { ${ php } } )() );` ) );

/** Copies the must-use fixture (an author-supporting post type, application passwords). */
function installFixture() {
	wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/axle-trash/tests/e2e/fixtures/axle-trash-fixture.php", WPMU_PLUGIN_DIR . "/axle-trash-fixture.php" );' );
}

/** Puts WordPress and the plugin back to a clean state for one test. */
function reset() {
	json(
		`global $wpdb;
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_delete_attachment' );
		remove_all_actions( 'delete_user' );
		foreach ( get_users( array( 'role__not_in' => array( 'administrator' ) ) ) as $u ) { wp_delete_user( $u->ID ); }
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'axle_trash'" );
		foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'axle_book', 'page', 'post', 'attachment' ) as $type ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( (int) $id, true ); }
		}
		delete_option( 'axle_trash_settings' );
		delete_option( 'axle_trash_test_mails' );
		return true;`
	);
}

/** Creates a pattern and a post that uses it. */
function patternWithPost( marker = 'PATTERN-MARKER' ) {
	return json(
		`$pattern = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Offer box', 'post_content' => '<!-- wp:paragraph --><p>' . ${ JSON.stringify( marker ) } . '</p><!-- /wp:paragraph -->' ) );
		$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Uses the pattern', 'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->' ) );
		return array( 'pattern' => $pattern, 'post' => $post, 'url' => get_permalink( $post ) );`
	);
}

/** Creates a user with a role and a password. */
function makeUser( login, role ) {
	return json( `return wp_insert_user( array( 'user_login' => ${ JSON.stringify( login ) }, 'user_pass' => 'pass-' . ${ JSON.stringify( login ) }, 'user_email' => ${ JSON.stringify( login ) } . '@example.com', 'role' => ${ JSON.stringify( role ) }, 'display_name' => ${ JSON.stringify( login ) } ) );` );
}

/** Row status and existence from the database. */
function status( id ) {
	return json( `$p = get_post( ${ Number( id ) } ); return $p ? $p->post_status : null;` );
}

/** Seeds a font family with one face and a real file on disk. */
function fontFamily() {
	return json(
		`$dir = wp_upload_dir()['basedir'] . '/fonts'; wp_mkdir_p( $dir );
		$file = 'axle-test-font.woff2'; file_put_contents( $dir . '/' . $file, 'wOF2-test' );
		$family = wp_insert_post( array( 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'post_title' => 'Axle Sans', 'post_name' => 'axle-sans', 'post_content' => wp_json_encode( array( 'name' => 'Axle Sans', 'slug' => 'axle-sans', 'fontFamily' => 'Axle Sans' ) ) ) );
		$face = wp_insert_post( array( 'post_type' => 'wp_font_face', 'post_status' => 'publish', 'post_title' => 'Axle Sans 400', 'post_parent' => $family, 'post_content' => wp_json_encode( array( 'fontFamily' => 'Axle Sans', 'fontWeight' => '400', 'fontStyle' => 'normal', 'src' => content_url( 'uploads/fonts/' . $file ) ) ) ) );
		update_post_meta( $face, '_wp_font_face_file', $file );
		return array( 'family' => $family, 'face' => $face, 'path' => $dir . '/' . $file );`
	);
}

function fileExists( path ) {
	return json( `return file_exists( ${ JSON.stringify( path ) } );` );
}

/** Seeds one item of each kind the person in the capability test must not see. */
function seedOthers() {
	return json(
		`$out = array();
		$out['template'] = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'secret-template', 'post_title' => 'Secret template', 'post_content' => 'x' ) );
		wp_set_object_terms( $out['template'], get_stylesheet(), 'wp_theme' );
		$out['nav'] = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Secret menu', 'post_content' => '' ) );
		$out['pattern'] = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Shared pattern', 'post_content' => 'p' ) );
		return $out;`
	);
}

/** A theme-agnostic template in the database. */
function dbTemplate( slug, marker ) {
	return json(
		`$id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => ${ JSON.stringify( slug ) }, 'post_title' => ${ JSON.stringify( slug ) }, 'post_content' => '<!-- wp:paragraph --><p>' . ${ JSON.stringify( marker ) } . '</p><!-- /wp:paragraph --><!-- wp:post-content /-->' ) );
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		return $id;`
	);
}

function pageWithTemplate( slug ) {
	return json(
		`$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Landing', 'post_content' => 'Landing body' ) );
		update_post_meta( $id, '_wp_page_template', ${ JSON.stringify( slug ) } );
		return array( 'id' => $id, 'url' => get_permalink( $id ) );`
	);
}

/** A deleted-author scenario: a user with a post, a book (custom type) and an image. */
function author( login = 'writer' ) {
	return json(
		`$uid = wp_insert_user( array( 'user_login' => ${ JSON.stringify( login ) }, 'user_pass' => 'pass-writer', 'user_email' => ${ JSON.stringify( login ) } . '@example.com', 'role' => 'editor', 'display_name' => 'Wanda Writer' ) );
		update_user_meta( $uid, 'axle_trash_probe', 'kept' );
		$book = wp_insert_post( array( 'post_type' => 'axle_book', 'post_status' => 'publish', 'post_title' => 'Her book', 'post_author' => $uid ) );
		$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Her post', 'post_author' => $uid ) );
		$dir = wp_upload_dir(); $file = $dir['path'] . '/wanda.png';
		file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
		$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Her image', 'post_status' => 'inherit', 'post_author' => $uid ), $file );
		return array( 'user' => $uid, 'book' => $book, 'post' => $post, 'att' => $att, 'file' => $file );`
	);
}

/** Parks one item of every kind, through the same wp_delete_post() calls core makes. */
function populate() {
	installFixture();
	reset();
	return json(
		`$theme = get_stylesheet();
		$out = array();
		$out['pattern'] = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Offer box', 'post_content' => 'x' ) );
		wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Uses the pattern', 'post_content' => '<!-- wp:block {"ref":' . $out['pattern'] . '} /-->' ) );
		$out['template'] = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'axle-clash', 'post_title' => 'Axle clash', 'post_content' => 'x' ) );
		wp_set_object_terms( $out['template'], $theme, 'wp_theme' );
		$out['nav'] = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Footer links', 'post_content' => '' ) );
		$family = wp_insert_post( array( 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'post_title' => 'Axle Sans', 'post_name' => 'axle-sans', 'post_content' => '{}' ) );
		wp_insert_post( array( 'post_type' => 'wp_font_face', 'post_status' => 'publish', 'post_title' => 'Axle Sans 400', 'post_parent' => $family, 'post_content' => '{}' ) );
		$out['family'] = $family;
		$uid = wp_insert_user( array( 'user_login' => 'wanda', 'user_pass' => 'x-wanda-1', 'user_email' => 'wanda@example.com', 'role' => 'editor', 'display_name' => 'Wanda Writer' ) );
		wp_insert_post( array( 'post_type' => 'axle_book', 'post_status' => 'publish', 'post_title' => 'Her book', 'post_author' => $uid ) );
		foreach ( array( 'pattern', 'template', 'nav', 'family' ) as $k ) { wp_delete_post( $out[ $k ], true ); }
		wp_delete_user( $uid );
		$out['user'] = $uid;
		// A newer template with the same name, so Restore asks first.
		$new = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'axle-clash', 'post_title' => 'Axle clash', 'post_content' => 'newer' ) );
		wp_set_object_terms( $new, $theme, 'wp_theme' );
		return $out;`
	);
}

module.exports = { populate, installFixture, reset, patternWithPost, makeUser, status, fontFamily, fileExists, seedOthers, dbTemplate, pageWithTemplate, author, json };
