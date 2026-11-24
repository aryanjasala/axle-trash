<?php
/**
 * What still points at a parked item.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the posts that use a parked pattern, navigation menu or template. Only
 * posts the person could edit are counted, so the number never reveals a post they cannot see.
 */
final class Usage {

	/** The most posts the person can edit that one count reaches before it shows "+". */
	private const LIMIT = 300;

	/** The most pages of LIMIT posts read for one count, so a count never runs unbounded. */
	private const ROUNDS = 10;

	/**
	 * A short description of what uses the item, or an empty string.
	 *
	 * @param \WP_Post $post The parked post.
	 * @return string Already translated, plain text.
	 */
	public function describe( \WP_Post $post ): string {
		switch ( $post->post_type ) {
			case 'wp_block':
				$count = $this->referenced( 'wp:block', $post->ID );
				/* translators: %s: a number of posts. */
				return $this->phrase( $count, __( 'Used in %s post', 'axle-trash' ), __( 'Used in %s posts', 'axle-trash' ) );
			case 'wp_navigation':
				$count = $this->referenced( 'wp:navigation', $post->ID );
				/* translators: %s: a number of templates, parts and posts. */
				return $this->phrase( $count, __( 'Used in %s place', 'axle-trash' ), __( 'Used in %s places', 'axle-trash' ) );
			case 'wp_template':
				$count = $this->pages_using( $post->post_name );
				/* translators: %s: a number of pages. */
				return $this->phrase( $count, __( 'Used by %s page', 'axle-trash' ), __( 'Used by %s pages', 'axle-trash' ) );
			case Status::SNAPSHOT:
				$count = ( new Users() )->content_count( $post->ID );
				/* translators: %s: a number of items. */
				return $this->phrase( array( $count, false ), __( 'Authored %s item', 'axle-trash' ), __( 'Authored %s items', 'axle-trash' ) );
			default:
				return '';
		}
	}

	/**
	 * Builds "Used in 34 posts" from a count and its capped flag.
	 *
	 * @param array{0: int, 1: bool} $count  Count and whether more may exist.
	 * @param string                 $one    Singular format.
	 * @param string                 $many   Plural format.
	 * @return string
	 */
	private function phrase( array $count, string $one, string $many ): string {
		if ( $count[0] <= 0 ) {
			return '';
		}

		$number = number_format_i18n( $count[0] ) . ( $count[1] ? '+' : '' );

		// Both formats are translated by their callers; this only picks one.
		return sprintf( 1 === $count[0] && ! $count[1] ? $one : $many, $number );
	}

	/**
	 * Posts whose content holds a block that points at the ID.
	 *
	 * @param string $block The block name without the comment marker, such as `wp:block`.
	 * @param int    $ref   The referenced post ID.
	 * @return array{0: int, 1: bool} Count of posts the person can edit, and whether more of them may exist.
	 */
	private function referenced( string $block, int $ref ): array {
		global $wpdb;

		$like    = '%' . $wpdb->esc_like( '<!-- ' . $block . ' {' ) . '%' . $wpdb->esc_like( '"ref":' . $ref ) . '%';
		$pattern = '/<!-- ' . preg_quote( $block, '/' ) . ' \{[^}]*"ref":' . $ref . '[,}]/';
		$count   = 0;
		$offset  = 0;

		// Read in pages until the person's own count reaches the limit or the posts run out, so
		// the number and the "+" only ever describe posts the person can edit.
		for ( $round = 0; $round < self::ROUNDS; $round++ ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded scan of post content for a block reference; core has no index for it.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ( 'auto-draft', 'inherit', 'trash', %s ) AND post_type <> 'revision' ORDER BY ID ASC LIMIT %d OFFSET %d",
					$like,
					Status::STATUS,
					self::LIMIT,
					$offset
				)
			);

			foreach ( (array) $ids as $post_id ) {
				$post = get_post( (int) $post_id );

				if ( ! $post instanceof \WP_Post || 1 !== preg_match( $pattern, $post->post_content ) ) {
					continue;
				}

				if ( current_user_can( 'edit_post', $post->ID ) ) {
					++$count;

					if ( $count >= self::LIMIT ) {
						return array( $count, true );
					}
				}
			}

			if ( count( (array) $ids ) < self::LIMIT ) {
				break;
			}

			$offset += self::LIMIT;
		}

		return array( $count, false );
	}

	/**
	 * Posts that use a page template.
	 *
	 * @param string $slug The template's slug.
	 * @return array{0: int, 1: bool} Count of posts the person can edit, and whether more of them may exist.
	 */
	private function pages_using( string $slug ): array {
		$count = 0;

		for ( $round = 1; $round <= self::ROUNDS; $round++ ) {
			$ids = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'         => self::LIMIT,
					'paged'                  => $round,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_key'               => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The only place a page's template is stored.
					'meta_value'             => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup.
				)
			);

			foreach ( $ids as $post_id ) {
				if ( current_user_can( 'edit_post', (int) $post_id ) ) {
					++$count;

					if ( $count >= self::LIMIT ) {
						return array( $count, true );
					}
				}
			}

			if ( count( $ids ) < self::LIMIT ) {
				break;
			}
		}

		return array( $count, false );
	}
}
