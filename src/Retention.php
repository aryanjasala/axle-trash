<?php
/**
 * The daily job that deletes old items for good.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes parked items older than the retention, in small batches.
 */
final class Retention implements Service {

	/** The cron event. */
	public const EVENT = 'axle_trash_purge';

	/** The follow-up event when a batch left more to do. */
	public const MORE = 'axle_trash_purge_more';

	/** The most top-level items one run deletes. */
	private const BATCH = 25;

	/**
	 * Parking and deleting.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up retention.
	 *
	 * @param Trash $trash Parking and deleting.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the job.
	 */
	public function register(): void {
		add_action( self::EVENT, array( $this, 'on_event' ) );
		add_action( self::MORE, array( $this, 'on_event' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * The cron callback.
	 */
	public function on_event(): void {
		$this->run();
	}

	/**
	 * Schedules the daily job when it is missing.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	/**
	 * Removes the job. Parked items stay parked.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT );
		wp_clear_scheduled_hook( self::MORE );
	}

	/**
	 * The cut-off: items parked before it are due.
	 *
	 * @return int Unix time.
	 */
	public static function cutoff(): int {
		return time() - Settings::retention_days() * DAY_IN_SECONDS;
	}

	/**
	 * Deletes one batch of due items. When more are left, another run follows in a minute.
	 *
	 * @return int How many top-level items were deleted for good.
	 */
	public function run(): int {
		$due     = Items::expired( self::cutoff(), self::BATCH );
		$deleted = 0;
		$more    = count( $due ) >= self::BATCH;

		foreach ( $due as $id ) {
			$left = $this->trash->purge( (int) $id );

			if ( is_int( $left ) && 0 === $left ) {
				++$deleted;
			} else {
				$more = true;
			}
		}

		if ( $more && ! wp_next_scheduled( self::MORE ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::MORE );
		}

		/**
		 * Fires after the daily job deleted a batch of old items for good.
		 *
		 * @param int $deleted How many top-level items it deleted.
		 */
		do_action( 'axle_trash_purged', $deleted );

		return $deleted;
	}
}
