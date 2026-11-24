<?php
/**
 * Test fixture for Axle Trash (a must-use plugin copied in by the e2e specs).
 * Registers a custom post type whose posts a deleted author takes with them, and
 * turns on application passwords on the plain HTTP test site.
 */

add_filter( 'wp_is_application_passwords_available', '__return_true' );

// No mail leaves the test site. Each attempt is counted, so a test can prove that nothing was sent by itself.
add_filter(
	'pre_wp_mail',
	static function () {
		update_option( 'axle_trash_test_mails', (int) get_option( 'axle_trash_test_mails', 0 ) + 1, false );
		return true;
	}
);

add_action(
	'init',
	static function () {
		register_post_type(
			'axle_book',
			array(
				'label'    => 'Books',
				'public'   => true,
				'supports' => array( 'title', 'editor', 'author' ),
			)
		);
	}
);
