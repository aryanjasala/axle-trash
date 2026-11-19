<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

use AryanX\Axle\Trash\Admin\Notices;
use AryanX\Axle\Trash\Admin\Page;
use AryanX\Axle\Trash\Cli\Command;
use AryanX\Axle\Trash\Health\SiteHealth;
use AryanX\Axle\Trash\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance, for other plugins.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Parking, restoring and deleting for good.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->trash = new Trash();
	}

	/**
	 * Parking, restoring and deleting for good, for other plugins.
	 *
	 * @return Trash
	 */
	public function trash(): Trash {
		return $this->trash;
	}

	/**
	 * The plugin's services: one per concern (settings screen, abilities, WP-CLI, ...).
	 *
	 * @return Service[]
	 */
	private function services(): array {
		return array(
			new Status(),
			new Interceptor( $this->trash, new Users() ),
			new Retention( $this->trash ),
			new Page( $this->trash ),
			new Notices(),
			new Editor(),
			new Controller( $this->trash ),
			new SiteHealth(),
			new Privacy( $this->trash ),
			new Abilities( $this->trash ),
		);
	}

	/**
	 * Registers every service.
	 */
	private function register(): void {
		foreach ( $this->services() as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-trash', new Command( $this->trash ) );
		}
	}
}
