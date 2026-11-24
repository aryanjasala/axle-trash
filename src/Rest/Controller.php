<?php
/**
 * REST route that restores an item. The editor's Undo uses it.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Rest;

use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Service;
use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Trash;
use AryanX\Axle\Trash\Users;

defined( 'ABSPATH' ) || exit;

/**
 * POST /axle-trash/v1/items/{id}/restore. The check is the item's own capability. Core's REST
 * nonce (or an application password) authenticates the caller; the permission callback decides.
 */
final class Controller implements Service {

	/**
	 * Parking and restoring.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up the controller.
	 *
	 * @param Trash $trash Parking and restoring.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function routes(): void {
		register_rest_route(
			'axle-trash/v1',
			'/items/(?P<id>\d+)/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'can_restore' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'replace' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Whether the person may restore this item. A missing item answers the same way as a forbidden one.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool|\WP_Error
	 */
	public function can_restore( \WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );

		if ( $post instanceof \WP_Post && Status::STATUS === $post->post_status && Items::can( $post ) ) {
			return true;
		}

		return new \WP_Error( 'rest_forbidden', __( 'You cannot restore this item.', 'axle-trash' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Restores the item.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$reset = ( new Users() )->needs_reset( $id );

		$result = $this->trash->restore( $id, (bool) $request->get_param( 'replace' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array(
			'restored' => true,
			'id'       => $id,
		);

		if ( $reset > 0 ) {
			// The account came back with a new random password. Nothing is sent: the caller decides.
			$data['user_id']                 = $reset;
			$data['password_reset_required'] = true;
		}

		return new \WP_REST_Response( $data );
	}
}
