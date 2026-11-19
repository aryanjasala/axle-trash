<?php
/**
 * Catches the deletes core would make for good.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks `pre_delete_post`, `pre_delete_attachment` and the user deletion hooks.
 *
 * Returning the post from `pre_delete_post` makes `wp_delete_post()` return it, so core's
 * REST controllers, the Site Editor and the Users screen all answer as if the delete worked.
 * `before_delete_post` never fires, so font files and upload files stay on disk.
 */
final class Interceptor implements Service {

	/** The transient that remembers, per signed-in user, the users that were just parked. */
	public const RECENT = 'axle_trash_recent_';

	/**
	 * Parking.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Snapshots.
	 *
	 * @var Users
	 */
	private Users $users;

	/**
	 * The user being deleted with their content: `user` ID and `batch` snapshot ID.
	 *
	 * @var array{user: int, batch: int}|null
	 */
	private ?array $deleting = null;

	/**
	 * Top-level items parked in this request, in order.
	 *
	 * @var int[]
	 */
	private array $parked = array();

	/**
	 * Sets up the interceptor.
	 *
	 * @param Trash $trash Parking.
	 * @param Users $users Snapshots.
	 */
	public function __construct( Trash $trash, Users $users ) {
		$this->trash = $trash;
		$this->users = $users;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'pre_delete_post', array( $this, 'pre_delete_post' ), 10, 3 );
		add_filter( 'pre_delete_attachment', array( $this, 'pre_delete_attachment' ), 10, 3 );
		add_action( 'delete_user', array( $this, 'before_user_delete' ), 5, 3 );
		add_action( 'deleted_user', array( $this, 'after_user_delete' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'link_core_trash' ), 10, 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'annotate_response' ), 10, 3 );
	}

	/**
	 * Parks what core is about to delete for good.
	 *
	 * @param mixed $check        Null to let core go on.
	 * @param mixed $post         The post.
	 * @param bool  $force_delete Whether the delete bypasses core's trash.
	 * @return mixed The post when it was parked, so core stops. The input otherwise.
	 */
	public function pre_delete_post( $check, $post, $force_delete = false ) {
		unset( $force_delete );

		if ( null !== $check || ! $post instanceof \WP_Post ) {
			return $check;
		}

		if ( Status::STATUS === $post->post_status || Status::SNAPSHOT === $post->post_type ) {
			return $check;
		}

		if ( $this->owned_by_deleted_user( $post ) ) {
			return $this->trash->park( $post, (int) $this->deleting['batch'] ) ? $post : $check;
		}

		$kind = Kinds::for_post_type( $post->post_type );

		if ( '' === $kind || ! Settings::keeps( $kind ) ) {
			return $check;
		}

		// Core empties its own trash on a schedule. That item already had its grace period.
		if ( 'trash' === $post->post_status && doing_action( 'wp_scheduled_delete' ) ) {
			return $check;
		}

		/**
		 * Filters whether an item core is about to delete for good goes to the trash instead.
		 *
		 * @param bool     $park Whether to park it. Default true.
		 * @param \WP_Post $post The post.
		 * @param string   $kind Kind key.
		 */
		if ( ! apply_filters( 'axle_trash_park', true, $post, $kind ) ) {
			return $check;
		}

		if ( ! $this->trash->park( $post ) ) {
			return $check;
		}

		$this->parked[] = $post->ID;

		return $post;
	}

	/**
	 * Parks an attachment a user took with them, files and all. Only while a user is deleted
	 * with their content: plain media deletes stay core's.
	 *
	 * @param mixed $check        Null to let core go on.
	 * @param mixed $post         The attachment.
	 * @param bool  $force_delete Whether the delete bypasses core's media trash.
	 * @return mixed
	 */
	public function pre_delete_attachment( $check, $post, $force_delete = false ) {
		unset( $force_delete );

		if ( null !== $check || ! $post instanceof \WP_Post || Status::STATUS === $post->post_status ) {
			return $check;
		}

		if ( $this->owned_by_deleted_user( $post ) ) {
			return $this->trash->park( $post, (int) $this->deleting['batch'] ) ? $post : $check;
		}

		return $check;
	}

	/**
	 * Saves the snapshot before core deletes the user and, when asked to, their content.
	 *
	 * @param mixed $user_id  The user's ID.
	 * @param mixed $reassign The user the content goes to, or null for "Delete all content".
	 * @param mixed $user     The user object.
	 */
	public function before_user_delete( $user_id, $reassign = null, $user = null ): void {
		$this->deleting = null;

		if ( ! Settings::keeps( Kinds::USER ) || $this->erasing() ) {
			return;
		}

		/**
		 * Filters whether a user about to be deleted is kept in the trash.
		 *
		 * @param bool $park    Whether to keep the account and its content. Default true.
		 * @param int  $user_id The user's ID.
		 */
		if ( ! apply_filters( 'axle_trash_park_user', true, (int) $user_id ) ) {
			return;
		}

		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user_id );

		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$snapshot = $this->users->snapshot( $user, null === $reassign ? null : (int) $reassign );

		if ( $snapshot <= 0 ) {
			return;
		}

		$this->remember_recent( $snapshot );

		if ( null === $reassign ) {
			$this->deleting = array(
				'user'  => (int) $user->ID,
				'batch' => $snapshot,
			);
		}

		/**
		 * Fires after a user's account was saved in the trash, just before core deletes it.
		 *
		 * @param int      $snapshot The snapshot's post ID.
		 * @param \WP_User $user     The user.
		 * @param bool     $content  Whether the user's content is parked too ("Delete all content").
		 */
		do_action( 'axle_trash_user_parked', $snapshot, $user, null === $reassign );
	}

	/**
	 * Ends the user deletion.
	 */
	public function after_user_delete(): void {
		$this->deleting = null;
	}

	/**
	 * Links a post or page that core sent to its own trash, during a user deletion, to the snapshot.
	 *
	 * @param mixed $post_id The trashed post's ID.
	 */
	public function link_core_trash( $post_id ): void {
		$post = null !== $this->deleting ? get_post( (int) $post_id ) : null;

		if ( $post instanceof \WP_Post && $this->owned_by_deleted_user( $post ) ) {
			update_post_meta( $post->ID, Status::META_BATCH, (int) $this->deleting['batch'] );
		}
	}

	/**
	 * Tells the editor which item to restore: adds `axle_trash` to the REST response of a DELETE.
	 *
	 * @param mixed $result  The response.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public function annotate_response( $result, $server, $request ) {
		unset( $server );

		if ( ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || 'DELETE' !== $request->get_method() || array() === $this->parked ) {
			return $result;
		}

		$data = $result->get_data();

		if ( ! is_array( $data ) || empty( $data['deleted'] ) ) {
			return $result;
		}

		$id   = (int) end( $this->parked );
		$post = get_post( $id );

		if ( $post instanceof \WP_Post ) {
			$data['axle_trash'] = array(
				'id'      => $id,
				'kind'    => Kinds::for_post_type( $post->post_type ),
				'restore' => rest_url( 'axle-trash/v1/items/' . $id . '/restore' ),
			);
			$result->set_data( $data );
		}

		return $result;
	}

	/**
	 * Whether the post belongs to the user being deleted with their content.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private function owned_by_deleted_user( \WP_Post $post ): bool {
		return null !== $this->deleting && (int) $post->post_author === $this->deleting['user'];
	}

	/**
	 * Whether core's personal data eraser is running. A person who asked to be erased is never parked.
	 *
	 * @return bool
	 */
	private function erasing(): bool {
		return doing_action( 'wp_ajax_wp-privacy-erase-personal-data' ) || doing_action( 'wp_privacy_personal_data_erased' );
	}

	/**
	 * Remembers a snapshot so the Users screen can offer Undo on the page it redirects to.
	 *
	 * @param int $snapshot Snapshot ID.
	 */
	private function remember_recent( int $snapshot ): void {
		$uid = get_current_user_id();

		if ( $uid <= 0 ) {
			return;
		}

		$list   = get_transient( self::RECENT . $uid );
		$list   = is_array( $list ) ? $list : array();
		$list[] = $snapshot;

		set_transient( self::RECENT . $uid, array_values( array_unique( array_map( 'intval', $list ) ) ), 5 * MINUTE_IN_SECONDS );
	}
}
