<?php
/**
 * Deleted users: the snapshot, and bringing them back.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the `wp_users` row and the user meta of a user about to be deleted, and
 * puts them back with the same ID.
 */
final class Users {

	/** The transient that holds, per signed-in user, the accounts they just restored. */
	public const RESET_PENDING = 'axle_trash_reset_';

	/**
	 * The `wp_users` columns a snapshot may hold and a restore may write.
	 * `user_pass` and `user_activation_key` are not here: they are never kept.
	 *
	 * @var string[]
	 */
	private const COLUMNS = array(
		'ID',
		'user_login',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_status',
		'display_name',
		'spam',
		'deleted',
	);

	/**
	 * Saves a snapshot of a user. Runs on `delete_user`, before core deletes anything.
	 *
	 * @param \WP_User $user     The user about to be deleted.
	 * @param int|null $reassign The user the content goes to, or null for "Delete all content".
	 * @return int The snapshot's post ID, or zero when it could not be saved.
	 */
	public function snapshot( \WP_User $user, ?int $reassign ): int {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $user->ID ), ARRAY_A ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The raw row is what a restore needs.

		if ( ! is_array( $row ) ) {
			return 0;
		}

		// Never keep the password hash or a pending reset key of a deleted account.
		$row = self::without_secrets( $row );

		$skip = $this->skipped_meta();
		$meta = array();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw rows, so serialized values go back exactly as they were.

		foreach ( (array) $rows as $meta_row ) {
			if ( in_array( $meta_row['meta_key'], $skip, true ) ) {
				continue;
			}
			$meta[] = array( (string) $meta_row['meta_key'], (string) $meta_row['meta_value'] );
		}

		$data = array(
			'row'      => $row,
			'meta'     => $meta,
			'reassign' => $reassign,
			'roles'    => array_values( (array) $user->roles ),
			'blog_id'  => get_current_blog_id(),
		);

		$json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( ! is_string( $json ) ) {
			return 0;
		}

		$title = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;

		$id = wp_insert_post(
			array(
				'post_type'   => Status::SNAPSHOT,
				'post_status' => Status::STATUS,
				'post_title'  => $title,
				'post_name'   => 'user-' . $user->ID,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		$id = (int) $id;

		update_post_meta( $id, Status::META_USER, wp_slash( $json ) );
		update_post_meta( $id, Status::META_EMAIL, strtolower( (string) $user->user_email ) );
		update_post_meta( $id, Status::META_BY, get_current_user_id() );
		update_post_meta( $id, Status::META_AT, time() );

		return $id;
	}

	/**
	 * What a snapshot remembers, for the list and the confirm step.
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return array{login: string, email: string, name: string, roles: string[], reassign: int|null, id: int}
	 */
	public function summary( int $snapshot_id ): array {
		$data = $this->data( $snapshot_id );
		$row  = isset( $data['row'] ) && is_array( $data['row'] ) ? $data['row'] : array();

		return array(
			'id'       => isset( $row['ID'] ) ? (int) $row['ID'] : 0,
			'login'    => isset( $row['user_login'] ) ? (string) $row['user_login'] : '',
			'email'    => isset( $row['user_email'] ) ? (string) $row['user_email'] : '',
			'name'     => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
			'roles'    => isset( $data['roles'] ) && is_array( $data['roles'] ) ? array_map( 'strval', $data['roles'] ) : array(),
			'reassign' => isset( $data['reassign'] ) ? (int) $data['reassign'] : null,
		);
	}

	/**
	 * Restores a user with the same ID, the same login and email, their meta, and everything
	 * parked or core-trashed with them.
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return true|\WP_Error
	 */
	public function restore( int $snapshot_id ) {
		global $wpdb;

		$data = $this->data( $snapshot_id );
		$row  = isset( $data['row'] ) && is_array( $data['row'] ) ? $data['row'] : array();

		if ( empty( $row['ID'] ) || empty( $row['user_login'] ) ) {
			return new \WP_Error( 'axle_trash_user_data', __( 'This snapshot has no saved account, so it cannot be restored.', 'axle-trash' ), array( 'status' => 410 ) );
		}

		$user_id = (int) $row['ID'];
		$exists  = false !== get_userdata( $user_id );

		// A person may not bring back an account with a role they could not give to someone.
		if ( ! $this->may_hold_roles( (array) ( $data['roles'] ?? array() ) ) ) {
			return new \WP_Error( 'axle_trash_role_not_allowed', __( 'This account had a role you are not allowed to give, so you cannot restore it.', 'axle-trash' ), array( 'status' => 403 ) );
		}

		if ( $exists && ! is_multisite() ) {
			return new \WP_Error( 'axle_trash_user_exists', __( 'Someone else has this user ID now, so the account cannot come back with it.', 'axle-trash' ), array( 'status' => 409 ) );
		}

		if ( ! $exists ) {
			$login_owner = username_exists( (string) $row['user_login'] );
			if ( $login_owner ) {
				/* translators: %s: a username. */
				return new \WP_Error( 'axle_trash_login_taken', sprintf( __( 'The username %s now belongs to someone else.', 'axle-trash' ), (string) $row['user_login'] ), array( 'status' => 409 ) );
			}

			if ( ! empty( $row['user_email'] ) && email_exists( (string) $row['user_email'] ) ) {
				/* translators: %s: an email address. */
				return new \WP_Error( 'axle_trash_email_taken', sprintf( __( 'The email address %s now belongs to someone else.', 'axle-trash' ), (string) $row['user_email'] ), array( 'status' => 409 ) );
			}

			// Only known columns go back, and the password is new and random: the old hash was never kept.
			$insert                        = array_intersect_key( $row, array_flip( self::COLUMNS ) );
			$insert['user_pass']           = wp_hash_password( wp_generate_password( 32, true, true ) );
			$insert['user_activation_key'] = '';

			$inserted = $wpdb->insert( $wpdb->users, $insert ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no API that inserts a user with a chosen ID.

			if ( false === $inserted ) {
				return new \WP_Error( 'axle_trash_user_insert', __( 'The database refused the account.', 'axle-trash' ), array( 'status' => 500 ) );
			}

			$skip = $this->skipped_meta();

			foreach ( (array) ( $data['meta'] ?? array() ) as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || in_array( (string) $pair[0], $skip, true ) ) {
					continue;
				}
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw values go back exactly as they were saved.
					$wpdb->usermeta,
					array(
						'user_id'    => $user_id,
						'meta_key'   => (string) $pair[0], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A column name here, not a query.
						'meta_value' => (string) $pair[1], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A column name here, not a query.
					)
				);
			}

			clean_user_cache( $user_id );
		} elseif ( function_exists( 'add_user_to_blog' ) ) {
			// Multisite: the account was only removed from this site. Put it back with its role.
			$role = isset( $data['roles'][0] ) ? (string) $data['roles'][0] : get_option( 'default_role', 'subscriber' );
			add_user_to_blog( get_current_blog_id(), $user_id, $role );
		}

		$trash = new Trash();

		foreach ( $trash->batch_members( $snapshot_id ) as $member ) {
			if ( Status::STATUS === $member->post_status ) {
				$trash->unpark( $member );
			} elseif ( 'trash' === $member->post_status ) {
				$this->untrash( $member );
				delete_post_meta( $member->ID, Status::META_BATCH );
			}
		}

		wp_delete_post( $snapshot_id, true );

		/** This action is documented in src/Trash.php. For a user, the ID is the user's and the post is null. */
		do_action( 'axle_trash_restored', $user_id, Kinds::USER, null, false );

		return true;
	}

	/**
	 * Whether restoring this snapshot gives the account a new random password. True for every
	 * account that is not still present (on multisite, an account only removed from this site keeps its own).
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return int The user's ID when a reset link is needed, otherwise zero.
	 */
	public function needs_reset( int $snapshot_id ): int {
		$id = $this->summary( $snapshot_id )['id'];

		return $id > 0 && false === get_userdata( $id ) ? $id : 0;
	}

	/**
	 * How many posts and other items are linked to a snapshot.
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return int
	 */
	public function content_count( int $snapshot_id ): int {
		$q = new \WP_Query(
			array(
				'post_type'              => array_values( get_post_types() ),
				'post_status'            => array( Status::STATUS, 'trash' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => Status::META_BATCH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counts what one snapshot owns.
				'meta_value'             => (string) $snapshot_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup.
			)
		);

		return (int) $q->found_posts;
	}

	/**
	 * Takes a core-trashed post out of core's trash, back to the status it had.
	 *
	 * @param \WP_Post $post The trashed post.
	 */
	private function untrash( \WP_Post $post ): void {
		$previous = (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true );
		$restore  = static fn ( $status ) => '' !== $previous ? $previous : $status;

		add_filter( 'wp_untrash_post_status', $restore );
		wp_untrash_post( $post->ID );
		remove_filter( 'wp_untrash_post_status', $restore );
	}

	/**
	 * The decoded snapshot.
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return array<string, mixed>
	 */
	private function data( int $snapshot_id ): array {
		$json = get_post_meta( $snapshot_id, Status::META_USER, true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * User meta keys a snapshot does not keep: signed-in sessions.
	 *
	 * @return string[]
	 */
	private function skipped_meta(): array {
		/**
		 * Filters the user meta keys a snapshot leaves out.
		 *
		 * @param string[] $keys Meta keys. Default: `session_tokens` (a restored account is signed out everywhere)
		 *                       and `_application_passwords` (hashed API passwords are never kept).
		 */
		$keys = apply_filters( 'axle_trash_user_meta_skip', array( 'session_tokens', '_application_passwords' ) );

		// The filter may add keys. These two stay out whatever it returns.
		return array_values( array_unique( array_merge( array( 'session_tokens', '_application_passwords' ), array_map( 'strval', (array) $keys ) ) ) );
	}

	/**
	 * A user row without the password hash and the activation key.
	 *
	 * @param array<string, mixed> $row A `wp_users` row.
	 * @return array<string, mixed>
	 */
	public static function without_secrets( array $row ): array {
		unset( $row['user_pass'], $row['user_activation_key'] );

		return $row;
	}

	/**
	 * Removes what an older snapshot kept that a new one does not: the password hash, the activation
	 * key and hashed application passwords. Used by the upgrade routine.
	 *
	 * @param int $snapshot_id Snapshot post ID.
	 * @return bool True when the snapshot held any of it and was rewritten.
	 */
	public function scrub( int $snapshot_id ): bool {
		$data = $this->data( $snapshot_id );

		if ( array() === $data ) {
			return false;
		}

		$changed = false;
		$row     = isset( $data['row'] ) && is_array( $data['row'] ) ? $data['row'] : array();

		if ( array_key_exists( 'user_pass', $row ) || array_key_exists( 'user_activation_key', $row ) ) {
			$data['row'] = self::without_secrets( $row );
			$changed     = true;
		}

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$kept = array();

			foreach ( $data['meta'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0] ) && in_array( (string) $pair[0], $this->skipped_meta(), true ) ) {
					$changed = true;
					continue;
				}
				$kept[] = $pair;
			}

			$data['meta'] = $kept;
		}

		if ( ! $changed ) {
			return false;
		}

		$json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( ! is_string( $json ) ) {
			return false;
		}

		update_post_meta( $snapshot_id, Status::META_USER, wp_slash( $json ) );

		return true;
	}

	/**
	 * Whether the current person may give every one of these roles. WP-CLI and cron (no user) may.
	 *
	 * @param array<int, mixed> $roles Role keys from a snapshot.
	 * @return bool
	 */
	private function may_hold_roles( array $roles ): bool {
		if ( get_current_user_id() <= 0 ) {
			return true;
		}

		// The filter core's get_editable_roles() applies, so role plugins that limit who may give which role are respected.
		$editable = (array) apply_filters( 'editable_roles', wp_roles()->roles ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A core hook, applied to ask the same question core asks.

		foreach ( $roles as $role ) {
			if ( ! isset( $editable[ (string) $role ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remembers that the person just restored an account on the Trash screen, so the page they land
	 * on can offer a password reset link. Nothing is sent from here.
	 *
	 * @param int    $user_id The restored user's ID.
	 * @param string $login   Their username.
	 */
	public function queue_reset_notice( int $user_id, string $login ): void {
		$uid = get_current_user_id();

		if ( $uid <= 0 ) {
			return;
		}

		$list             = get_transient( self::RESET_PENDING . $uid );
		$list             = is_array( $list ) ? $list : array();
		$list[ $user_id ] = $login;

		set_transient( self::RESET_PENDING . $uid, $list, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The accounts the current person just restored, once. Each shows a reset button.
	 *
	 * @return array<int, string> User ID => username.
	 */
	public function take_reset_notices(): array {
		$uid = get_current_user_id();

		if ( $uid <= 0 ) {
			return array();
		}

		$list = get_transient( self::RESET_PENDING . $uid );
		delete_transient( self::RESET_PENDING . $uid );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Sends the password reset link to a restored account, when the person asks for it.
	 * The person needs the right to edit that user, like core's own "Send reset link" button.
	 *
	 * @param int $user_id The user's ID.
	 * @return true|\WP_Error
	 */
	public function send_reset_link( int $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof \WP_User || ! current_user_can( 'edit_user', $user_id ) ) {
			return new \WP_Error( 'axle_trash_reset_denied', __( 'You cannot send a password reset link to this account.', 'axle-trash' ), array( 'status' => 403 ) );
		}

		$sent = retrieve_password( $user->user_login );

		return true === $sent ? true : new \WP_Error( 'axle_trash_reset_failed', __( 'The password reset link could not be sent.', 'axle-trash' ), array( 'status' => 500 ) );
	}
}
