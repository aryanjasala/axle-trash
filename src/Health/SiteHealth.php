<?php
/**
 * Site Health test.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Health;

use AryanX\Axle\Trash\Admin\Page;
use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Retention;
use AryanX\Axle\Trash\Service;
use AryanX\Axle\Trash\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "The trash is emptied on time" to Tools > Site Health.
 */
final class SiteHealth implements Service {

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test.
	 *
	 * @param mixed $tests Tests.
	 * @return array<string, mixed>
	 */
	public function add_test( $tests ): array {
		$tests                         = (array) $tests;
		$tests['direct']['axle_trash'] = array(
			'label' => __( 'The Axle trash is emptied on time', 'axle-trash' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Recommended when items older than the retention are still there: the daily job is not running.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$days   = Settings::retention_days();
		$late   = Items::expired( time() - ( $days + 1 ) * DAY_IN_SECONDS, 100 );
		$result = array(
			'label'       => __( 'The Axle trash is emptied on time', 'axle-trash' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Trash', 'axle-trash' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Deleted items are kept for %d day, then removed for good.', 'Deleted items are kept for %d days, then removed for good.', $days, 'axle-trash' ),
					$days
				)
			) . '</p>',
			'actions'     => '',
			'test'        => 'axle_trash',
		);

		if ( array() === $late ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'The Axle trash holds items past their retention', 'axle-trash' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of items, 2: number of days. */
				_n( '%1$d item has been in the trash for more than %2$d days. The daily job that removes old items is not running.', '%1$d items have been in the trash for more than %2$d days. The daily job that removes old items is not running.', count( $late ), 'axle-trash' ),
				count( $late ),
				$days + 1
			)
		) . '</p>';
		$result['actions']        = sprintf(
			'<p><a href="%1$s">%2$s</a></p><p>%3$s</p>',
			esc_url( Page::url() ),
			esc_html__( 'Open the trash', 'axle-trash' ),
			esc_html__( 'Check that WP-Cron runs, or run the job from the server: wp cron event run axle_trash_purge', 'axle-trash' )
		);
		$result['badge']['color'] = 'orange';

		return $result;
	}
}
