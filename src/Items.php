<?php
/**
 * What is in the trash, and who may see it.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Lists parked items. Every list is cut down to the items the person could have deleted
 * before anything is counted, shown or returned: nothing about other items leaks.
 */
final class Items {

	/**
	 * Whether the person may see and act on an item. The check is the one core makes for a delete.
	 *
	 * @param \WP_Post $post       The parked post.
	 * @param int|null $user_id    The user. Null means the current user.
	 * @param bool     $check_caps False for WP-CLI, which runs with full rights.
	 * @return bool
	 */
	public static function can( \WP_Post $post, ?int $user_id = null, bool $check_caps = true ): bool {
		$kind = Status::SNAPSHOT === $post->post_type ? Kinds::USER : Kinds::for_post_type( $post->post_type );

		if ( '' === $kind ) {
			return false;
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		$allowed = true;

		if ( $check_caps ) {
			$cap     = Kinds::capability( $kind );
			$allowed = 'delete_post' === $cap ? self::could_delete( $post, $user_id ) : user_can( $user_id, $cap );
		}

		/**
		 * Filters whether a person may see, restore and delete one parked item.
		 *
		 * @param bool     $allowed Whether they may. Default: the kind's capability, checked against the item.
		 * @param \WP_Post $post    The parked post.
		 * @param string   $kind    Kind key.
		 * @param int      $user_id The user's ID. Zero for WP-CLI.
		 */
		return (bool) apply_filters( 'axle_trash_can', $allowed, $post, $kind, $user_id );
	}

	/**
	 * Whether the person could have deleted the post, judged as it was before it was parked.
	 *
	 * Core's `delete_post` check reads the post's current status. A parked post has the internal
	 * status, so that check would ignore "published" and let a person without the right to delete
	 * published items see and restore one. This runs the same steps core does, with the old status.
	 *
	 * @param \WP_Post $post    The parked post.
	 * @param int      $user_id The user.
	 * @return bool
	 */
	public static function could_delete( \WP_Post $post, int $user_id ): bool {
		$type = get_post_type_object( $post->post_type );

		if ( ! $type instanceof \WP_Post_Type ) {
			return false;
		}

		$caps = $type->cap;
		$was  = (string) get_post_meta( $post->ID, Status::META_STATUS, true );
		$need = array();

		// The same steps as core's map_meta_cap() for `delete_post`, with the status the post had.
		if ( $user_id > 0 && (int) $post->post_author === $user_id ) {
			$need[] = in_array( $was, array( 'publish', 'future' ), true ) ? (string) $caps->delete_published_posts : (string) $caps->delete_posts;
		} else {
			$need[] = (string) $caps->delete_others_posts;

			if ( in_array( $was, array( 'publish', 'future' ), true ) ) {
				$need[] = (string) $caps->delete_published_posts;
			} elseif ( 'private' === $was ) {
				$need[] = (string) $caps->delete_private_posts;
			}
		}

		foreach ( array_unique( array_filter( $need ) ) as $cap ) {
			if ( ! user_can( $user_id, $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the person could see anything in the trash at all. Decides the menu item.
	 *
	 * @return bool
	 */
	public static function can_use(): bool {
		foreach ( array_keys( Kinds::all() ) as $kind ) {
			$cap = Kinds::capability( (string) $kind );
			if ( 'delete_post' === $cap ? current_user_can( 'delete_posts' ) : current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every top-level parked post, newest deletion first, cut down to what the person may see.
	 *
	 * @param string $view       A view key, or an empty string for all.
	 * @param bool   $check_caps False for WP-CLI.
	 * @return \WP_Post[]
	 */
	public static function visible( string $view = '', bool $check_caps = true ): array {
		$types = Kinds::post_types();

		if ( '' !== $view ) {
			$types = array();
			foreach ( Kinds::in_view( $view ) as $kind ) {
				$types = array_merge( $types, Kinds::all()[ $kind ]['types'] );
			}
		}

		if ( array() === $types ) {
			return array();
		}

		$found = array();
		$paged = 1;

		do {
			$page  = get_posts(
				array(
					'post_type'              => $types,
					'post_status'            => Status::STATUS,
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Top-level items only, over a small set.
						array(
							'key'     => Status::META_BATCH,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			$found = array_merge( $found, $page );
			++$paged;
			$more = count( $page ) >= 100;
		} while ( $more );

		$items = array();

		foreach ( $found as $post ) {
			if ( self::can( $post, null, $check_caps ) ) {
				$items[] = $post;
			}
		}

		usort(
			$items,
			static fn ( \WP_Post $a, \WP_Post $b ) => self::parked_at( $b->ID ) <=> self::parked_at( $a->ID )
		);

		return $items;
	}

	/**
	 * Counts for the views, from what the person may see.
	 *
	 * @param bool $check_caps False for WP-CLI.
	 * @return array<string, int> `all` plus one entry per view.
	 */
	public static function counts( bool $check_caps = true ): array {
		$counts = array( 'all' => 0 );

		foreach ( array_keys( Kinds::views() ) as $view ) {
			$counts[ $view ] = 0;
		}

		foreach ( self::visible( '', $check_caps ) as $post ) {
			$kind = Status::SNAPSHOT === $post->post_type ? Kinds::USER : Kinds::for_post_type( $post->post_type );
			$view = Kinds::all()[ $kind ]['view'];
			++$counts['all'];
			++$counts[ $view ];
		}

		return $counts;
	}

	/**
	 * Every parked post the daily job may delete, whoever may see it.
	 *
	 * @param int $older_than Only items parked before this Unix time. Zero for all.
	 * @param int $limit      The most to return.
	 * @return int[] IDs, oldest first.
	 */
	public static function expired( int $older_than = 0, int $limit = 50 ): array {
		$meta = array(
			array(
				'key'     => Status::META_BATCH,
				'compare' => 'NOT EXISTS',
			),
		);

		if ( $older_than > 0 ) {
			$meta[] = array(
				'key'     => Status::META_AT,
				'value'   => $older_than,
				'type'    => 'NUMERIC',
				'compare' => '<',
			);
		}

		$ids = get_posts(
			array(
				'post_type'              => Kinds::post_types(),
				'post_status'            => Status::STATUS,
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Finds expired items by their parked time.
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * When an item was parked.
	 *
	 * @param int $post_id Post ID.
	 * @return int Unix time, or zero.
	 */
	public static function parked_at( int $post_id ): int {
		return (int) get_post_meta( $post_id, Status::META_AT, true );
	}

	/**
	 * A row for the list, the CLI and the abilities: plain data about one parked post.
	 *
	 * @param \WP_Post $post The parked post.
	 * @return array{id: int, kind: string, kind_label: string, title: string, deleted_by: int, deleted_by_name: string, deleted_at: int, details: string}
	 */
	public static function describe( \WP_Post $post ): array {
		$is_user = Status::SNAPSHOT === $post->post_type;
		$kind    = $is_user ? Kinds::USER : Kinds::for_post_type( $post->post_type );
		$all     = Kinds::all();
		$label   = isset( $all[ $kind ] ) ? $all[ $kind ]['label'] : '';
		$by      = (int) get_post_meta( $post->ID, Status::META_BY, true );
		$by_user = $by > 0 ? get_userdata( $by ) : false;
		$title   = $post->post_title;
		$details = '';

		if ( $is_user ) {
			$summary = ( new Users() )->summary( $post->ID );
			$title   = '' !== $summary['name'] ? $summary['name'] : $summary['login'];
			$details = $summary['login'];
		} elseif ( 'wp_font_face' === $post->post_type ) {
			$family = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
			$title  = $family instanceof \WP_Post ? $family->post_title . ' ' . $title : $title;
			$label  = __( 'Font face', 'axle-trash' );
		} elseif ( 'wp_font_family' === $post->post_type ) {
			$label = __( 'Font family', 'axle-trash' );
		}

		if ( '' === trim( $title ) ) {
			$title = $post->post_name;
		}

		return array(
			'id'              => (int) $post->ID,
			'kind'            => $kind,
			'kind_label'      => $label,
			'title'           => $title,
			'deleted_by'      => $by,
			'deleted_by_name' => $by_user instanceof \WP_User ? $by_user->display_name : '',
			'deleted_at'      => self::parked_at( $post->ID ),
			'details'         => $details,
		);
	}
}
