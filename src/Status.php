<?php
/**
 * The internal post status and the record type the plugin uses.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `axle_trash` post status and the `axle_trash_user` record type.
 *
 * A parked post keeps its ID, its terms, its meta, its comments and its
 * children. Only its status changes, to one nothing on the site queries for:
 * the status is internal and excluded from search, and WordPress reads block
 * templates, synced patterns, navigation menus and fonts by status. Because the
 * row still exists, its ID stays reserved and every `ref` that points at it
 * resolves again after a restore.
 */
final class Status implements Service {

	/** The post status of everything parked. */
	public const STATUS = 'axle_trash';

	/** The post type of a deleted user's snapshot (at most 20 characters). */
	public const SNAPSHOT = 'axle_trash_user';

	/** The status the item had before it was parked. */
	public const META_STATUS = '_axle_trash_status';

	/** The user ID that deleted it (0 for WP-CLI, cron or unknown). */
	public const META_BY = '_axle_trash_by';

	/** When it was parked, as a Unix timestamp. */
	public const META_AT = '_axle_trash_at';

	/** The theme a template or part belonged to. */
	public const META_THEME = '_axle_trash_theme';

	/** The ID of the snapshot or font family this item travels with. */
	public const META_BATCH = '_axle_trash_batch';

	/** A snapshot's account email, lower case, so the privacy tools can find it. */
	public const META_EMAIL = '_axle_trash_email';

	/** A snapshot's saved user row, user meta and the "Delete all content" choice, as JSON. */
	public const META_USER = '_axle_trash_user';

	/**
	 * Hooks the registrations.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registrations' ), 0 );
	}

	/**
	 * Registers the status and the record type.
	 */
	public function registrations(): void {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Axle trash', 'post status', 'axle-trash' ),
				'public'                    => false,
				'private'                   => false,
				'protected'                 => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);

		register_post_type(
			self::SNAPSHOT,
			array(
				'label'               => __( 'Deleted users', 'axle-trash' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'can_export'          => false,
				'delete_with_user'    => false,
			)
		);
	}
}
