<?php
/**
 * Abilities for agents and other tools (WordPress 6.9+).
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Registers list-items, restore-item and delete-item. Each checks the item's own capability,
 * the same one the screen uses, and never reveals an item the person could not have deleted.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-trash';

	/**
	 * Parking, restoring and deleting.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up the abilities.
	 *
	 * @param Trash $trash Parking, restoring and deleting.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Trash', 'axle-trash' ),
				'description' => __( 'List what was deleted for good, restore it with its original ID, or delete it for real.', 'axle-trash' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$meta = static function ( array $annotations ): array {
			return array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			);
		};

		$item = array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'kind'            => array( 'type' => 'string' ),
				'kind_label'      => array( 'type' => 'string' ),
				'title'           => array( 'type' => 'string' ),
				'deleted_by'      => array( 'type' => 'integer' ),
				'deleted_by_name' => array( 'type' => 'string' ),
				'deleted_at'      => array( 'type' => 'integer' ),
				'details'         => array( 'type' => 'string' ),
			),
		);

		$id_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);

		wp_register_ability(
			'axle-trash/list-items',
			array(
				'label'               => __( 'List trashed items', 'axle-trash' ),
				'description'         => __( 'Lists what was deleted for good and is waiting in the trash, newest first. Only items the current user could have deleted are returned.', 'axle-trash' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind' => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), array_keys( Kinds::all() ) ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array(
							'type'  => 'array',
							'items' => $item,
						),
					),
				),
				'execute_callback'    => array( $this, 'list_items' ),
				'permission_callback' => array( Items::class, 'can_use' ),
				'meta'                => $meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);

		wp_register_ability(
			'axle-trash/restore-item',
			array(
				'label'               => __( 'Restore a trashed item', 'axle-trash' ),
				'description'         => __( 'Brings an item back from the trash with its original ID. A template or part whose name was taken since is not replaced.', 'axle-trash' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $id_input,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'restored'                => array( 'type' => 'boolean' ),
						'id'                      => array( 'type' => 'integer' ),
						'user_id'                 => array( 'type' => 'integer' ),
						'password_reset_required' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'restore_item' ),
				'permission_callback' => array( $this, 'can_act' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			)
		);

		wp_register_ability(
			'axle-trash/delete-item',
			array(
				'label'               => __( 'Delete a trashed item for good', 'axle-trash' ),
				'description'         => __( 'Deletes one item in the trash for good, with its files. A user takes the content parked with them. This cannot be undone.', 'axle-trash' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $id_input,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'deleted'   => array( 'type' => 'boolean' ),
						'remaining' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'can_act' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * Whether the current user may act on the item the input names.
	 *
	 * @param mixed $input The input.
	 * @return bool
	 */
	public function can_act( $input = null ): bool {
		$id   = is_array( $input ) && isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && Status::STATUS === $post->post_status && Items::can( $post );
	}

	/**
	 * Lists the items.
	 *
	 * @param mixed $input The input.
	 * @return array<string, mixed>
	 */
	public function list_items( $input = null ): array {
		$kind  = is_array( $input ) && isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$items = array();

		foreach ( Items::visible() as $post ) {
			$row = Items::describe( $post );

			if ( '' === $kind || $row['kind'] === $kind ) {
				$items[] = $row;
			}
		}

		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Restores an item.
	 *
	 * @param mixed $input The input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_item( $input = null ) {
		$id = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;

		// Re-check here: the permission callback runs first, but this method is also public.
		if ( ! $this->can_act( array( 'id' => $id ) ) ) {
			return new \WP_Error( 'axle_trash_forbidden', __( 'You cannot restore this item.', 'axle-trash' ), array( 'status' => 403 ) );
		}

		$reset  = ( new Users() )->needs_reset( $id );
		$result = $this->trash->restore( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array(
			'restored' => true,
			'id'       => $id,
		);

		if ( $reset > 0 ) {
			// A new random password was set. Nothing is sent; a person sends the reset link.
			$out['user_id']                 = $reset;
			$out['password_reset_required'] = true;
		}

		return $out;
	}

	/**
	 * Deletes an item for good.
	 *
	 * @param mixed $input The input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_item( $input = null ) {
		$id = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;

		if ( ! $this->can_act( array( 'id' => $id ) ) ) {
			return new \WP_Error( 'axle_trash_forbidden', __( 'You cannot delete this item.', 'axle-trash' ), array( 'status' => 403 ) );
		}

		$left = $this->trash->purge( $id );

		if ( is_wp_error( $left ) ) {
			return $left;
		}

		if ( $left > 0 ) {
			wp_schedule_single_event( time() + 30, Retention::MORE );
		}

		return array(
			'deleted'   => 0 === $left,
			'remaining' => $left,
		);
	}
}
