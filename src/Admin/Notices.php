<?php
/**
 * The "Moved to Tools > Trash" notice on the Users screen.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Admin;

use AryanX\Axle\Trash\Interceptor;
use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Service;
use AryanX\Axle\Trash\Status;

defined( 'ABSPATH' ) || exit;

/**
 * After Users > Delete, says where the account went and offers Undo.
 */
final class Notices implements Service {

	/**
	 * Hooks the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'users_notice' ) );
	}

	/**
	 * Prints the notice on the page core redirects to after a delete.
	 */
	public function users_notice(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads core's own redirect flag.
		$update = isset( $_GET['update'] ) ? sanitize_key( wp_unslash( $_GET['update'] ) ) : '';

		if ( 'users.php' !== $pagenow || ! in_array( $update, array( 'del', 'del_many' ), true ) ) {
			return;
		}

		$uid  = get_current_user_id();
		$list = get_transient( Interceptor::RECENT . $uid );

		if ( ! is_array( $list ) || array() === $list ) {
			return;
		}

		delete_transient( Interceptor::RECENT . $uid );

		$links = array();

		foreach ( $list as $id ) {
			$post = get_post( (int) $id );

			if ( ! $post instanceof \WP_Post || Status::STATUS !== $post->post_status || ! Items::can( $post ) ) {
				continue;
			}

			$row     = Items::describe( $post );
			$links[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					wp_nonce_url(
						Page::url(
							array(
								'axle_action' => 'restore',
								'item'        => $post->ID,
							) 
						),
						'axle_trash_restore_' . $post->ID 
					) 
				),
				esc_html(
					/* translators: %s: a user's name. */
					sprintf( __( 'Undo for %s', 'axle-trash' ), $row['title'] )
				)
			);
		}

		if ( array() === $links ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%1$s %2$s %3$s</p></div>',
			esc_html__( 'Moved to Tools > Trash. The account and what was deleted with it stay there until you restore it or the retention runs out.', 'axle-trash' ),
			wp_kses( implode( ' &middot; ', $links ), array( 'a' => array( 'href' => array() ) ) ),
			''
		);
	}
}
