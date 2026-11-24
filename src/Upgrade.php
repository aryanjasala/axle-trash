<?php
/**
 * Data changes between versions.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per site when the stored data format is older than the code.
 *
 * Version 2: a deleted user's snapshot no longer holds the password hash, the activation key
 * or hashed application passwords. Snapshots saved before that have those fields blanked.
 */
final class Upgrade implements Service {

	/** The option that holds the data format of this site. */
	public const OPTION = 'axle_trash_data_version';

	/** The data format the code writes. */
	public const CURRENT = 2;

	/** Snapshots rewritten per query. */
	private const BATCH = 50;

	/**
	 * Hooks the check.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_run' ), 20 );
	}

	/**
	 * Runs the routine when this site has not seen it yet.
	 */
	public function maybe_run(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::CURRENT ) {
			return;
		}

		$this->scrub_snapshots();

		update_option( self::OPTION, self::CURRENT, true );
	}

	/**
	 * Blanks password hashes and activation keys in every saved snapshot.
	 *
	 * @return int How many snapshots were rewritten.
	 */
	public function scrub_snapshots(): int {
		$users   = new Users();
		$changed = 0;
		$paged   = 1;
		$more    = true;

		while ( $more ) {
			$ids = get_posts(
				array(
					'post_type'              => Status::SNAPSHOT,
					'post_status'            => Status::STATUS,
					'posts_per_page'         => self::BATCH,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $ids as $id ) {
				if ( $users->scrub( (int) $id ) ) {
					++$changed;
				}
			}

			++$paged;
			$more = count( $ids ) >= self::BATCH;
		}

		return $changed;
	}
}
