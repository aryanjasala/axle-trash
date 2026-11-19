<?php
/**
 * Parking, restoring and deleting for good.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Moves posts into the trash and back. A parked post changes its status and
 * nothing else, so its ID, terms, meta, comments and children stay where they are.
 */
final class Trash {

	/**
	 * Parks a post. The caller returns the post to core, which then treats the delete as done.
	 *
	 * @param \WP_Post $post  The post core was about to delete.
	 * @param int      $batch The snapshot or font family this post travels with. Zero for a top-level item.
	 * @param int|null $by    The user who deleted it. Null means the current user.
	 * @return bool True when the post is parked.
	 */
	public function park( \WP_Post $post, int $batch = 0, ?int $by = null ): bool {
		global $wpdb;

		if ( Status::STATUS === $post->post_status ) {
			return true;
		}

		$by = null === $by ? get_current_user_id() : $by;

		// A direct update: wp_update_post() would run save_post, revisions and the slug check,
		// and could rename a template whose slug now belongs to a newer one.
		$updated = $wpdb->update( $wpdb->posts, array( 'post_status' => Status::STATUS ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The status is the whole point of parking, and the cache is cleared next.

		if ( false === $updated ) {
			return false;
		}

		clean_post_cache( $post->ID );

		update_post_meta( $post->ID, Status::META_STATUS, $post->post_status );
		update_post_meta( $post->ID, Status::META_BY, $by );
		update_post_meta( $post->ID, Status::META_AT, time() );

		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			$themes = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'slugs' ) );
			if ( is_array( $themes ) && isset( $themes[0] ) ) {
				update_post_meta( $post->ID, Status::META_THEME, (string) $themes[0] );
			}
		}

		if ( $batch > 0 ) {
			update_post_meta( $post->ID, Status::META_BATCH, $batch );
		}

		if ( 'wp_font_family' === $post->post_type ) {
			foreach ( $this->font_faces( $post->ID, array( 'publish', 'draft', 'pending', 'private' ) ) as $face ) {
				$this->park( $face, $post->ID, $by );
			}
		}

		$this->flush( $post->post_type );

		/**
		 * Fires after an item is parked in the trash.
		 *
		 * @param int      $post_id The post ID, which stays the same.
		 * @param string   $kind    Kind key, or `user_content` for something a deleted user owned.
		 * @param \WP_Post $post    The post as it was before parking.
		 * @param int      $batch   The snapshot or font family it travels with, or zero.
		 */
		do_action( 'axle_trash_parked', $post->ID, $batch > 0 && 'wp_font_face' !== $post->post_type ? 'user_content' : Kinds::for_post_type( $post->post_type ), $post, $batch );

		return true;
	}

	/**
	 * Restores an item with its original ID.
	 *
	 * @param int  $id      The parked post ID.
	 * @param bool $replace Move a template or part that took the slug in the meantime to the trash.
	 * @return true|\WP_Error
	 */
	public function restore( int $id, bool $replace = false ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || Status::STATUS !== $post->post_status ) {
			return new \WP_Error( 'axle_trash_missing', $this->message( 'missing' ), array( 'status' => 404 ) );
		}

		if ( Status::SNAPSHOT === $post->post_type ) {
			return ( new Users() )->restore( $id );
		}

		if ( 'wp_font_face' === $post->post_type && $post->post_parent > 0 && Status::STATUS === get_post_status( $post->post_parent ) ) {
			return new \WP_Error( 'axle_trash_parent_parked', $this->message( 'parent_parked' ), array( 'status' => 409 ) );
		}

		$conflict = $this->conflict( $post );

		if ( $conflict instanceof \WP_Post ) {
			if ( ! $replace ) {
				return new \WP_Error(
					'axle_trash_conflict',
					$this->message( 'conflict' ),
					array(
						'status'   => 409,
						'conflict' => $conflict->ID,
					) 
				);
			}

			$this->park( $conflict );
		}

		$this->unpark( $post );

		if ( 'wp_font_family' === $post->post_type ) {
			foreach ( $this->batch_members( $id ) as $member ) {
				$this->unpark( $member );
			}
		}

		$this->flush( $post->post_type );

		/**
		 * Fires after an item is restored from the trash.
		 *
		 * @param int      $post_id  The post ID, the same as before it was deleted.
		 * @param string   $kind     Kind key.
		 * @param \WP_Post $post     The post as it was while parked.
		 * @param bool     $replaced Whether a newer item with the same slug was moved to the trash.
		 */
		do_action( 'axle_trash_restored', $id, Kinds::for_post_type( $post->post_type ), $post, $conflict instanceof \WP_Post );

		return true;
	}

	/**
	 * Puts a post back to the status it had, and forgets what the trash knew about it.
	 *
	 * @param \WP_Post $post The parked post.
	 */
	public function unpark( \WP_Post $post ): void {
		global $wpdb;

		$status = (string) get_post_meta( $post->ID, Status::META_STATUS, true );

		if ( '' === $status || Status::STATUS === $status || ! get_post_status_object( $status ) ) {
			$status = 'attachment' === $post->post_type ? 'inherit' : 'publish';
		}

		$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirrors park(); the cache is cleared next.

		foreach ( array( Status::META_STATUS, Status::META_BY, Status::META_AT, Status::META_THEME, Status::META_BATCH ) as $key ) {
			delete_post_meta( $post->ID, $key );
		}

		clean_post_cache( $post->ID );
	}

	/**
	 * The template or part that took the same slug and theme since this one was parked.
	 *
	 * @param \WP_Post $post The parked post.
	 * @return \WP_Post|null
	 */
	public function conflict( \WP_Post $post ): ?\WP_Post {
		if ( ! in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			return null;
		}

		$theme = (string) get_post_meta( $post->ID, Status::META_THEME, true );
		$args  = array(
			'post_type'              => $post->post_type,
			'name'                   => $post->post_name,
			'post_status'            => array( 'publish', 'draft', 'auto-draft' ),
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'post__not_in'           => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One ID, on a tiny result.
		);

		if ( '' !== $theme ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One lookup by theme, limited to one row.
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $theme,
				),
			);
		}

		$found = get_posts( $args );

		return $found[0] ?? null;
	}

	/**
	 * Deletes a parked item for good, the way core would have: font files go with their faces and
	 * an attachment's files go with the attachment. A user snapshot goes with the content parked for it.
	 *
	 * @param int $id    The parked post ID.
	 * @param int $limit How many pieces of parked content to delete in this call.
	 * @return int|\WP_Error How many pieces of content are left. Zero means the item itself is gone.
	 */
	public function purge( int $id, int $limit = 200 ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || Status::STATUS !== $post->post_status ) {
			return new \WP_Error( 'axle_trash_missing', $this->message( 'missing' ), array( 'status' => 404 ) );
		}

		$kind = Status::SNAPSHOT === $post->post_type ? Kinds::USER : Kinds::for_post_type( $post->post_type );

		foreach ( $this->batch_members( $id, $limit ) as $member ) {
			if ( Status::STATUS === $member->post_status ) {
				wp_delete_post( $member->ID, true );
			} else {
				// Core's own trash keeps what it already had. It only loses its link to the snapshot.
				delete_post_meta( $member->ID, Status::META_BATCH );
			}
		}

		$left = count( $this->batch_members( $id, 1 ) );

		if ( $left > 0 ) {
			return $left;
		}

		wp_delete_post( $id, true );
		$this->flush( $post->post_type );

		/**
		 * Fires after a parked item was deleted for good.
		 *
		 * @param int      $post_id The ID the post had.
		 * @param string   $kind    Kind key.
		 * @param \WP_Post $post    The post as it was while parked.
		 */
		do_action( 'axle_trash_deleted', $id, $kind, $post );

		return 0;
	}

	/**
	 * Parked pieces and core-trashed posts that travel with a snapshot or a font family.
	 *
	 * @param int $batch The snapshot or font family ID.
	 * @param int $limit How many to return. Zero or less for all.
	 * @return \WP_Post[]
	 */
	public function batch_members( int $batch, int $limit = 0 ): array {
		$found = array();
		$paged = 1;

		do {
			$page  = get_posts(
				array(
					'post_type'              => array_values( get_post_types() ),
					'post_status'            => array( Status::STATUS, 'trash' ),
					'posts_per_page'         => $limit > 0 ? min( $limit, 100 ) : 100, // phpcs:ignore
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_key'               => Status::META_BATCH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds what travels with one snapshot.
					'meta_value'             => (string) $batch, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup.
				)
			);
			$found = array_merge( $found, $page );
			++$paged;
			$more = count( $page ) >= 100 && ( $limit <= 0 || count( $found ) < $limit );
		} while ( $more );

		return $found;
	}

	/**
	 * A font family's faces.
	 *
	 * @param int      $family_id Family ID.
	 * @param string[] $statuses  Statuses to include.
	 * @return \WP_Post[]
	 */
	private function font_faces( int $family_id, array $statuses ): array {
		$found = get_posts(
			array(
				'post_type'              => 'wp_font_face',
				'post_status'            => $statuses,
				'post_parent'            => $family_id,
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return $found;
	}

	/**
	 * Clears the caches that hold what the site reads from the parked post.
	 *
	 * @param string $post_type The post type that changed.
	 */
	private function flush( string $post_type ): void {
		if ( ! in_array( $post_type, array( 'wp_font_family', 'wp_font_face' ), true ) ) {
			return;
		}

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}

		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
	}

	/**
	 * A message from the filterable set.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public function message( string $key ): string {
		$messages = array(
			'missing'       => __( 'That item is not in the trash any more.', 'axle-trash' ),
			'conflict'      => __( 'A newer item with the same name exists. Restore it and move the current one to the trash, or cancel.', 'axle-trash' ),
			'parent_parked' => __( 'Restore the font family first. This face belongs to it.', 'axle-trash' ),
		);

		/**
		 * Filters the messages the trash shows when something cannot be done.
		 *
		 * @param array<string, string> $messages Keys: missing, conflict, parent_parked.
		 */
		$filtered = (array) apply_filters( 'axle_trash_messages', $messages );

		return isset( $filtered[ $key ] ) ? (string) $filtered[ $key ] : $messages[ $key ];
	}
}
