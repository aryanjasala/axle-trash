<?php
/**
 * Removes everything Axle Trash stored. Runs when the plugin is deleted from wp-admin.
 *
 * @package AryanX\Axle\Trash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// TODO: delete every option, table, meta key, cron event and capability the plugin created.
// On multisite, loop over get_sites() and switch_to_blog() for per-site data.
