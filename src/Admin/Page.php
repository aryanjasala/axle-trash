<?php
/**
 * Tools > Trash.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Admin;

use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Kinds;
use AryanX\Axle\Trash\Service;
use AryanX\Axle\Trash\Settings;
use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Trash;
use const AryanX\Axle\Trash\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * The screen: a list of what was deleted with Restore and Delete permanently, an
 * "Empty the trash" step, and a settings card. Every change is a nonce-checked
 * request, and every item is checked against what the person could have deleted.
 */
final class Page implements Service {

	public const SLUG = 'axle-trash';

	/**
	 * Parking and restoring.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * The screen's hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * The table, once built.
	 *
	 * @var ItemsTable|null
	 */
	private ?ItemsTable $table = null;

	/**
	 * Sets up the screen.
	 *
	 * @param Trash $trash Parking and restoring.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the menu, assets and handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_axle_trash_save_settings', array( $this, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * The screen URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * Adds Tools > Trash for anyone who could have deleted something that ends up here.
	 */
	public function menu(): void {
		if ( ! Items::can_use() ) {
			return;
		}

		$this->hook = (string) add_management_page( __( 'Trash', 'axle-trash' ), __( 'Trash', 'axle-trash' ), 'read', self::SLUG, array( $this, 'render' ) );

		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'load' ) );
		}
	}

	/**
	 * A link on the Plugins screen.
	 *
	 * @param mixed $links Links.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = (array) $links;

		if ( Items::can_use() ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the trash', 'axle-trash' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Loads the stylesheet on the screen.
	 *
	 * @param string $hook_suffix The current screen.
	 */
	public function assets( $hook_suffix ): void {
		if ( '' !== $this->hook && $hook_suffix === $this->hook ) {
			AxleUi::enqueue();
		}
	}

	/**
	 * Runs before the page prints: handles actions, then adds the Help tab.
	 */
	public function load(): void {
		$this->handle();

		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-trash' ),
					'content' => '<p>' . esc_html__( 'WordPress deletes some things for good with no trash: templates, template parts, patterns, navigation menus and fonts in the Site Editor, and a user together with everything they wrote. Axle Trash keeps them here instead, with their original ID, so pages that point at them work again after a restore.', 'axle-trash' ) . '</p>',
				),
				array(
					'id'      => 'restore',
					'title'   => __( 'Restoring', 'axle-trash' ),
					'content' => '<p>' . esc_html__( 'Restore puts an item back as it was. If a newer template or part took its name, you can restore it and move the newer one to the trash. A restored user comes back with the same ID, so their posts belong to them again, unless the username or email now belongs to someone else.', 'axle-trash' ) . '</p>',
				),
				array(
					'id'      => 'permissions',
					'title'   => __( 'Who sees what', 'axle-trash' ),
					'content' => '<p>' . esc_html__( 'You only see items you could have deleted yourself. Templates, parts, menus and fonts need the right to edit the theme. Patterns follow the usual delete rule for patterns. Users need the right to delete users.', 'axle-trash' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Handles the screen's requests, then redirects. Prints a confirm step by returning to render().
	 */
	private function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Each branch below verifies its own nonce before it changes anything.
		$action = isset( $_REQUEST['axle_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['axle_action'] ) ) : '';
		$bulk   = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$bulk   = '' === $bulk && isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : $bulk;
		// phpcs:enable

		if ( isset( $_POST['axle_trash_empty'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only a redirect to the confirm step, which changes nothing.
			wp_safe_redirect( self::url( array( 'axle_action' => 'confirm_empty' ) ) );
			exit;
		}

		if ( in_array( $bulk, array( 'restore', 'delete' ), true ) && isset( $_POST['item'] ) ) {
			check_admin_referer( 'bulk-items' );
			$ids = $this->posted_ids();
			if ( 'delete' === $bulk ) {
				// A step before anything is deleted: the confirm card, carrying the ids.
				$this->confirm_ids = $ids;
				$this->confirming  = 'delete';
				return;
			}
			$this->finish( 'restored', $this->restore_many( $ids ) );
		}

		if ( 'restore' === $action ) {
			$id = isset( $_REQUEST['item'] ) ? absint( wp_unslash( $_REQUEST['item'] ) ) : 0;
			check_admin_referer( 'axle_trash_restore_' . $id );
			$replace = isset( $_REQUEST['replace'] ) && '1' === $_REQUEST['replace']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
			$post    = $this->item( $id );

			if ( null === $post ) {
				$this->finish( 'missing', array() );
			}

			$result = $this->trash->restore( $id, $replace );

			if ( is_wp_error( $result ) && 'axle_trash_conflict' === $result->get_error_code() ) {
				$this->confirm_ids = array( $id );
				$this->confirming  = 'conflict';
				return;
			}

			$this->finish( is_wp_error( $result ) ? 'failed' : 'restored', array( is_wp_error( $result ) ? $result->get_error_message() : $id ), is_wp_error( $result ) ? $result : null );
		}

		if ( 'confirm_delete' === $action ) {
			$this->confirm_ids = array( isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shows a step; changes nothing.
			$this->confirming  = 'delete';
			return;
		}

		if ( 'confirm_empty' === $action ) {
			$this->confirming = 'empty';
			return;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'axle_trash_delete' );
			$this->finish( 'deleted', $this->delete_many( $this->posted_ids() ) );
		}

		if ( 'empty' === $action ) {
			check_admin_referer( 'axle_trash_empty' );
			$ids = array_map( static fn ( \WP_Post $post ) => $post->ID, Items::visible() );
			$this->finish( 'deleted', $this->delete_many( $ids ) );
		}
	}

	/**
	 * Ids the request carries, from `item[]`.
	 *
	 * @return int[]
	 */
	private function posted_ids(): array {
		$raw = isset( $_POST['item'] ) ? (array) wp_unslash( $_POST['item'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by the caller, and cast to integers next.

		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * A parked item the person may act on, or null. Anything else looks the same as an item that is gone.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null
	 */
	private function item( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && Status::STATUS === $post->post_status && Items::can( $post ) ? $post : null;
	}

	/**
	 * Restores several items. Conflicts are skipped and counted.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, mixed> `done` and `skipped` counts.
	 */
	private function restore_many( array $ids ): array {
		$done    = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			if ( null !== $this->item( $id ) && true === $this->trash->restore( $id ) ) {
				++$done;
			} else {
				++$skipped;
			}
		}

		return array( $done, $skipped );
	}

	/**
	 * Deletes several items for good, in one batch each.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, mixed> `done` and `later` counts.
	 */
	private function delete_many( array $ids ): array {
		$done  = 0;
		$later = 0;

		foreach ( $ids as $id ) {
			if ( null === $this->item( $id ) ) {
				continue;
			}

			$left = $this->trash->purge( $id );

			if ( is_int( $left ) && 0 === $left ) {
				++$done;
			} else {
				++$later;
				wp_schedule_single_event( time() + 30, \AryanX\Axle\Trash\Retention::MORE );
			}
		}

		return array( $done, $later );
	}

	/**
	 * Redirects back to the list with a result.
	 *
	 * @param string            $result  What happened: restored, deleted, missing, failed or saved.
	 * @param array<int, mixed> $numbers Counts.
	 * @param \WP_Error|null    $error   The reason, for a failure.
	 */
	private function finish( string $result, array $numbers, ?\WP_Error $error = null ): void {
		$args = array(
			'axle_done' => $result,
			'n'         => isset( $numbers[0] ) && is_int( $numbers[0] ) ? $numbers[0] : 0,
			'm'         => isset( $numbers[1] ) && is_int( $numbers[1] ) ? $numbers[1] : 0,
		);

		if ( null !== $error ) {
			$args['why'] = $error->get_error_code();
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Saves the settings card.
	 */
	public function save_settings(): void {
		check_admin_referer( 'axle_trash_save_settings' );

		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'axle-trash' ), '', array( 'response' => 403 ) );
		}

		Settings::save(
			array(
				'kinds'     => isset( $_POST['kinds'] ) ? (array) wp_unslash( $_POST['kinds'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised key by key in Settings::save().
				'retention' => isset( $_POST['retention'] ) ? absint( wp_unslash( $_POST['retention'] ) ) : Settings::DEFAULT_DAYS,
			)
		);

		wp_safe_redirect( self::url( array( 'axle_done' => 'saved' ) ) );
		exit;
	}

	/**
	 * The capability that changes the settings.
	 *
	 * @return string
	 */
	public static function settings_capability(): string {
		/**
		 * Filters the capability that changes which kinds the trash keeps and for how long.
		 *
		 * @param string $cap Capability. Default `manage_options`.
		 */
		$cap = (string) apply_filters( 'axle_trash_settings_capability', 'manage_options' );

		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Ids the confirm step is about.
	 *
	 * @var int[]
	 */
	private array $confirm_ids = array();

	/**
	 * Which confirm step to show: delete, conflict or empty.
	 *
	 * @var string
	 */
	private string $confirming = '';

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		if ( ! Items::can_use() ) {
			wp_die( esc_html__( 'You are not allowed to see the trash.', 'axle-trash' ), '', array( 'response' => 403 ) );
		}

		$this->table = new ItemsTable();
		$this->table->prepare_items();

		echo '<div class="wrap axle-ui">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Trash', 'axle-trash' ) . '</h1><hr class="wp-header-end">';

		$this->notice();

		echo '<p class="axle-intro">' . esc_html__( 'Things WordPress deletes for good wait here: templates, template parts, patterns, navigation menus and fonts from the Site Editor, and users deleted with their content. Restore brings one back with its original ID.', 'axle-trash' ) . '</p>';

		$this->confirm();

		if ( ! $this->table->has_any() ) {
			echo '<div class="axle-empty"><p>' . esc_html__( 'The trash is empty. When something is deleted for good in the Site Editor or on the Users screen, it will wait here until you restore it or it is removed.', 'axle-trash' ) . '</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( self::url() ) . '">';
			$this->table->views();
			$this->table->display();
			echo '</form>';
		}

		$this->settings_card();
		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * The result notice after an action.
	 */
	private function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A message chosen from a fixed list; the numbers are cast to integers.
		$done = isset( $_GET['axle_done'] ) ? sanitize_key( wp_unslash( $_GET['axle_done'] ) ) : '';
		$n    = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
		$m    = isset( $_GET['m'] ) ? absint( wp_unslash( $_GET['m'] ) ) : 0;
		$why  = isset( $_GET['why'] ) ? sanitize_key( wp_unslash( $_GET['why'] ) ) : '';
		// phpcs:enable

		$type = 'success';
		$text = '';

		switch ( $done ) {
			case 'restored':
				/* translators: %s: number of items. */
				$text = sprintf( _n( 'Restored %s item.', 'Restored %s items.', $n, 'axle-trash' ), number_format_i18n( $n ) );
				if ( $m > 0 ) {
					/* translators: %s: number of items. */
					$text .= ' ' . sprintf( _n( '%s item could not be restored. Open it to see why.', '%s items could not be restored. Open one to see why.', $m, 'axle-trash' ), number_format_i18n( $m ) );
					$type  = 'warning';
				}
				break;
			case 'deleted':
				/* translators: %s: number of items. */
				$text = sprintf( _n( 'Deleted %s item for good.', 'Deleted %s items for good.', $n, 'axle-trash' ), number_format_i18n( $n ) );
				if ( $m > 0 ) {
					/* translators: %s: number of items. */
					$text .= ' ' . sprintf( _n( '%s more is still being removed in the background.', '%s more are still being removed in the background.', $m, 'axle-trash' ), number_format_i18n( $m ) );
					$type  = 'info';
				}
				break;
			case 'saved':
				$text = __( 'Settings saved.', 'axle-trash' );
				break;
			case 'missing':
				$text = __( 'That item is not in the trash any more.', 'axle-trash' );
				$type = 'warning';
				break;
			case 'failed':
				$type = 'error';
				$text = $this->failure( $why );
				break;
		}

		if ( '' === $text ) {
			return;
		}

		/**
		 * Filters the result notice on the trash screen.
		 *
		 * @param string $text Message.
		 * @param string $done What happened: restored, deleted, saved, missing or failed.
		 */
		$text = (string) apply_filters( 'axle_trash_notice', $text, $done );

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/**
	 * Why a restore failed, in plain words.
	 *
	 * @param string $code The error code.
	 * @return string
	 */
	private function failure( string $code ): string {
		$known = array(
			'axle_trash_user_exists'   => __( 'Someone else has this user ID now, so the account cannot come back with it.', 'axle-trash' ),
			'axle_trash_login_taken'   => __( 'The username now belongs to someone else, so the account cannot come back.', 'axle-trash' ),
			'axle_trash_email_taken'   => __( 'The email address now belongs to someone else, so the account cannot come back.', 'axle-trash' ),
			'axle_trash_parent_parked' => $this->trash->message( 'parent_parked' ),
			'axle_trash_user_data'     => __( 'This snapshot has no saved account, so it cannot be restored.', 'axle-trash' ),
		);

		return $known[ $code ] ?? __( 'The item could not be restored.', 'axle-trash' );
	}

	/**
	 * The step before something is deleted for good or replaced.
	 */
	private function confirm(): void {
		if ( '' === $this->confirming ) {
			return;
		}

		$posts = array();

		foreach ( $this->confirm_ids as $id ) {
			$post = $this->item( (int) $id );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}

		if ( 'empty' === $this->confirming ) {
			$posts = Items::visible();
		}

		if ( array() === $posts ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'There is nothing to do. The item is not in the trash any more.', 'axle-trash' ) . '</p></div>';
			return;
		}

		$names = array_map( static fn ( \WP_Post $post ) => Items::describe( $post )['title'], $posts );

		echo '<div class="axle-confirm axle-confirm--danger" role="group" aria-labelledby="axle-trash-confirm-title">';
		echo '<h2 id="axle-trash-confirm-title">';

		if ( 'conflict' === $this->confirming ) {
			esc_html_e( 'A newer item has the same name', 'axle-trash' );
		} elseif ( 'empty' === $this->confirming ) {
			esc_html_e( 'Empty the trash?', 'axle-trash' );
		} else {
			esc_html_e( 'Delete for good?', 'axle-trash' );
		}

		echo '</h2>';

		if ( 'conflict' === $this->confirming ) {
			$post = $posts[0];
			echo '<p>' . esc_html( $this->trash->message( 'conflict' ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( self::url() ) . '" class="axle-actions">';
			wp_nonce_field( 'axle_trash_restore_' . $post->ID );
			echo '<input type="hidden" name="axle_action" value="restore"><input type="hidden" name="item" value="' . (int) $post->ID . '"><input type="hidden" name="replace" value="1">';
			echo '<button type="submit" class="button">' . esc_html__( 'Restore and move the current one to the trash', 'axle-trash' ) . '</button> ';
			echo '<a class="button-link" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'axle-trash' ) . '</a>';
			echo '</form></div>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: names of the items, separated by commas. */
				__( 'This deletes %s for good. It cannot be undone.', 'axle-trash' ),
				implode( ', ', array_slice( $names, 0, 5 ) ) . ( count( $names ) > 5 ? sprintf( /* translators: %d: number of further items. */ __( ' and %d more', 'axle-trash' ), count( $names ) - 5 ) : '' )
			)
		) . '</p>';

		foreach ( $posts as $post ) {
			if ( Status::SNAPSHOT === $post->post_type ) {
				echo '<p>' . esc_html__( 'Deleting a user also deletes the posts, pages and files that were deleted with them. Font files and uploads are removed from disk.', 'axle-trash' ) . '</p>';
				break;
			}
		}

		echo '<form method="post" action="' . esc_url( self::url() ) . '" class="axle-actions">';

		if ( 'empty' === $this->confirming ) {
			wp_nonce_field( 'axle_trash_empty' );
			echo '<input type="hidden" name="axle_action" value="empty">';
		} else {
			wp_nonce_field( 'axle_trash_delete' );
			echo '<input type="hidden" name="axle_action" value="delete">';
			foreach ( $posts as $post ) {
				echo '<input type="hidden" name="item[]" value="' . (int) $post->ID . '">';
			}
		}

		echo '<button type="submit" class="button axle-button--danger">' . esc_html__( 'Delete for good', 'axle-trash' ) . '</button> ';
		echo '<a class="button-link" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'axle-trash' ) . '</a>';
		echo '</form></div>';
	}

	/**
	 * Settings: which kinds are kept and how long.
	 */
	private function settings_card(): void {
		if ( ! current_user_can( self::settings_capability() ) ) {
			return;
		}

		$saved = Settings::saved();

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Settings', 'axle-trash' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'axle_trash_save_settings' );
		echo '<input type="hidden" name="action" value="axle_trash_save_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Keep these', 'axle-trash' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Kinds of item to keep', 'axle-trash' ) . '</legend>';

		foreach ( Kinds::all() as $key => $kind ) {
			printf(
				'<label><input type="checkbox" name="kinds[]" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( (string) $key ),
				checked( in_array( $key, $saved['kinds'], true ), true, false ),
				esc_html( $kind['plural'] )
			);
		}

		echo '</fieldset><p class="description">' . esc_html__( 'Anything you untick is deleted for good the way WordPress does it.', 'axle-trash' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="axle-trash-retention">' . esc_html__( 'Keep for', 'axle-trash' ) . '</label></th><td>';
		printf( '<input type="number" id="axle-trash-retention" name="retention" min="1" max="3650" value="%d" class="small-text"> %s', (int) $saved['retention'], esc_html__( 'days', 'axle-trash' ) );
		echo '<p class="description">' . esc_html__( 'A daily job deletes items older than this for good. Deleted users are kept for the same time.', 'axle-trash' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'axle-trash' ) );
		echo '</form></section>';
	}
}
