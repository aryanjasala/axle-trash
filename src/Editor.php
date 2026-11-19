<?php
/**
 * The editor's "Moved to the Axle trash" snackbar with Undo.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

use const AryanX\Axle\Trash\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a small script in the block editor and the Site Editor. It watches for a
 * successful DELETE on the item routes and offers Undo.
 */
final class Editor implements Service {

	/**
	 * Hooks the script.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the script for people who can see anything in the trash.
	 */
	public function enqueue(): void {
		if ( ! Items::can_use() ) {
			return;
		}

		wp_enqueue_script(
			'axle-trash-undo',
			plugins_url( 'assets/undo.js', PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n', 'wp-hooks', 'wp-a11y' ),
			VERSION,
			true
		);

		$strings = array(
			'moved'    => __( 'Moved to the Axle trash.', 'axle-trash' ),
			'undo'     => __( 'Undo', 'axle-trash' ),
			'restored' => __( 'Restored.', 'axle-trash' ),
			'failed'   => __( 'Could not restore it.', 'axle-trash' ),
		);

		/**
		 * Filters the words in the editor's snackbar.
		 *
		 * @param array<string, string> $strings Keys: moved, undo, restored, failed.
		 */
		$filtered = array_merge( $strings, array_filter( (array) apply_filters( 'axle_trash_editor_strings', $strings ), 'is_string' ) );

		wp_add_inline_script( 'axle-trash-undo', 'window.axleTrashL10n = ' . wp_json_encode( $filtered ) . ';', 'before' );
	}
}
