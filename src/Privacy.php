<?php
/**
 * Personal data: the privacy policy text, the exporter and the eraser.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * A trashed user keeps an account snapshot for the retention period. The exporter lists it
 * by email address and the eraser deletes it, with the content parked for it.
 */
final class Privacy implements Service {

	/** The most snapshots one exporter or eraser page handles. */
	private const LIMIT = 50;

	/**
	 * Parking and deleting.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up privacy.
	 *
	 * @param Trash $trash Parking and deleting.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks into the privacy tools.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporter( $exporters ): array {
		$exporters               = (array) $exporters;
		$exporters['axle-trash'] = array(
			'exporter_friendly_name' => __( 'Axle Trash', 'axle-trash' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers['axle-trash'] = array(
			'eraser_friendly_name' => __( 'Axle Trash', 'axle-trash' ),
			'callback'             => array( $this, 'erase' ),
		);

		return (array) $erasers;
	}

	/**
	 * The snapshots kept for an email address.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public function snapshots_for( string $email ): array {
		$ids = get_posts(
			array(
				'post_type'              => Status::SNAPSHOT,
				'post_status'            => Status::STATUS,
				'posts_per_page'         => self::LIMIT,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => Status::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds one person's snapshots.
				'meta_value'             => strtolower( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup.
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Exports the snapshot kept for an email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email ): array {
		$groups = array();

		foreach ( $this->snapshots_for( (string) $email ) as $id ) {
			$summary  = ( new Users() )->summary( $id );
			$groups[] = array(
				'group_id'          => 'axle-trash-user',
				'group_label'       => __( 'Deleted account kept in the trash', 'axle-trash' ),
				'group_description' => __( 'The account was deleted from the site. A copy of its profile (username, email, display name and profile fields, but not the password) is kept for a short time so a mistaken delete can be undone.', 'axle-trash' ),
				'item_id'           => 'axle-trash-user-' . $id,
				'data'              => array(
					array(
						'name'  => __( 'Username', 'axle-trash' ),
						'value' => $summary['login'],
					),
					array(
						'name'  => __( 'Email', 'axle-trash' ),
						'value' => $summary['email'],
					),
					array(
						'name'  => __( 'Display name', 'axle-trash' ),
						'value' => $summary['name'],
					),
					array(
						'name'  => __( 'Deleted', 'axle-trash' ),
						'value' => wp_date( 'c', Items::parked_at( $id ) ),
					),
				),
			);
		}

		return array(
			'data' => $groups,
			'done' => true,
		);
	}

	/**
	 * Erases the snapshot kept for an email address, with the content parked for it.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$removed = false;

		$ids = $this->snapshots_for( (string) $email );

		// A full page means there may be more. Core calls the eraser again until `done` is true.
		$done = count( $ids ) < self::LIMIT;

		foreach ( $ids as $id ) {
			$left    = $this->trash->purge( $id );
			$removed = true;

			if ( is_int( $left ) && $left > 0 ) {
				$done = false;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	/**
	 * Adds the suggested privacy policy paragraph.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = Settings::retention_days();

		wp_add_privacy_policy_content(
			__( 'Axle Trash', 'axle-trash' ),
			wp_kses_post(
				'<p class="privacy-policy-tutorial">' . __( 'Axle Trash keeps what is deleted from the site for a short time, so a mistake can be undone. You can copy this text into your privacy policy.', 'axle-trash' ) . '</p>' .
				'<p><strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'axle-trash' ) . '</strong> ' .
				sprintf(
					/* translators: %d: number of days. */
					_n( 'When an account is deleted from this site, a copy of its profile (username, email address, display name and profile fields) is kept for %d day before it is erased for good, together with any posts and uploads that were deleted with the account. This lets us undo a mistaken delete. The copy does not include the account\'s password or its password reset key. If the account is restored, it gets a new random password and the person sets their own through a password reset link. On request, we can erase the copy sooner.', 'When an account is deleted from this site, a copy of its profile (username, email address, display name and profile fields) is kept for %d days before it is erased for good, together with any posts and uploads that were deleted with the account. This lets us undo a mistaken delete. The copy does not include the account\'s password or its password reset key. If the account is restored, it gets a new random password and the person sets their own through a password reset link. On request, we can erase the copy sooner.', $days, 'axle-trash' ),
					$days
				) . '</p>'
			)
		);
	}
}
