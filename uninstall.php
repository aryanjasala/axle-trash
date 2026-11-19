<?php
/**
 * Removes everything Axle Trash stored. Runs when the plugin is deleted from wp-admin.
 *
 * What people deleted on purpose and was waiting in the trash is deleted for good, the way
 * WordPress would have done it (font files and upload files included). Content that went to
 * WordPress's own trash stays there.
 *
 * @package AryanX\Axle\Trash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Finishes the deletes on the current site and removes the plugin's data.
 */
function axle_trash_uninstall_site(): void {
	global $wpdb;

	// The plugin is not loaded here, so WordPress's own delete code runs unhindered.
	do {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY ID ASC LIMIT 100", 'axle_trash' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	} while ( ! empty( $ids ) );

	delete_post_meta_by_key( '_axle_trash_batch' );
	delete_post_meta_by_key( '_axle_trash_status' );
	delete_post_meta_by_key( '_axle_trash_by' );
	delete_post_meta_by_key( '_axle_trash_at' );
	delete_post_meta_by_key( '_axle_trash_theme' );
	delete_post_meta_by_key( '_axle_trash_user' );
	delete_post_meta_by_key( '_axle_trash_email' );

	delete_option( 'axle_trash_settings' );
	delete_option( 'axle_trash_notice' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_trash_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_trash_' ) . '%' ) );

	wp_unschedule_hook( 'axle_trash_purge' );
	wp_unschedule_hook( 'axle_trash_purge_more' );
}

if ( is_multisite() ) {
	$axle_trash_offset = 0;
	do {
		$axle_trash_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_trash_offset,
			)
		);

		foreach ( $axle_trash_sites as $axle_trash_site ) {
			switch_to_blog( (int) $axle_trash_site );
			axle_trash_uninstall_site();
			restore_current_blog();
		}

		$axle_trash_offset += 100;
		$axle_trash_more    = count( $axle_trash_sites ) === 100;
	} while ( $axle_trash_more );
} else {
	axle_trash_uninstall_site();
}
