<?php
/**
 * `wp axle-trash` commands.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Cli;

use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Kinds;
use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Trash;
use AryanX\Axle\Trash\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what is in the trash, restores it and deletes it for good. WP-CLI runs with full rights,
 * so these commands see every item.
 */
final class Command {

	/**
	 * Parking, restoring and deleting.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up the command.
	 *
	 * @param Trash $trash Parking, restoring and deleting.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Lists what is in the trash, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Only one kind: template, template_part, pattern, navigation, font or user.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, ids or count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-trash list --kind=pattern
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );

		$kind   = isset( $assoc_args['kind'] ) ? (string) $assoc_args['kind'] : '';
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$usage  = new Usage();
		$rows   = array();

		if ( '' !== $kind && ! isset( Kinds::all()[ $kind ] ) ) {
			\WP_CLI::error( 'Unknown kind. Use one of: ' . implode( ', ', array_keys( Kinds::all() ) ) );
		}

		foreach ( Items::visible( '', false ) as $post ) {
			$row = Items::describe( $post );

			if ( '' !== $kind && $row['kind'] !== $kind ) {
				continue;
			}

			$rows[] = array(
				'id'         => $row['id'],
				'kind'       => $row['kind'],
				'title'      => $row['title'],
				'deleted_by' => '' !== $row['deleted_by_name'] ? $row['deleted_by_name'] : '-',
				'deleted_at' => $row['deleted_at'] > 0 ? gmdate( 'Y-m-d H:i:s', $row['deleted_at'] ) : '-',
				'used'       => $usage->describe( $post ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'kind', 'title', 'deleted_by', 'deleted_at', 'used' ) );
	}

	/**
	 * Restores items with their original IDs.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs from `wp axle-trash list`.
	 *
	 * [--replace]
	 * : When a template or part with the same name exists now, move it to the trash and restore this one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-trash restore 412 --replace
	 *
	 * @param array<int, string>    $args       IDs.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$replace = \WP_CLI\Utils\get_flag_value( $assoc_args, 'replace', false );
		$failed  = 0;

		foreach ( $args as $arg ) {
			$result = $this->trash->restore( (int) $arg, (bool) $replace );

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( '%d: %s', (int) $arg, $result->get_error_message() ) );
				++$failed;
				continue;
			}

			\WP_CLI::log( sprintf( 'Restored %d.', (int) $arg ) );
		}

		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d of %d could not be restored.', $failed, count( $args ) ) );
		}

		\WP_CLI::success( 'Restored.' );
	}

	/**
	 * Deletes items for good, with their files. This cannot be undone.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs from `wp axle-trash list`.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-trash delete 412 413 --yes
	 *
	 * @param array<int, string>    $args       IDs.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		\WP_CLI::confirm( sprintf( 'Delete %d item(s) for good? This cannot be undone.', count( $args ) ), $assoc_args );

		foreach ( $args as $arg ) {
			$this->purge_all( (int) $arg );
		}

		\WP_CLI::success( 'Deleted.' );
	}

	/**
	 * Deletes everything in the trash, or only what is older than some days.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Only items deleted more than this many days ago.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-trash empty --older-than=7 --yes
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function empty( array $args, array $assoc_args ): void {
		unset( $args );

		$before = isset( $assoc_args['older-than'] ) ? time() - max( 0, (int) $assoc_args['older-than'] ) * DAY_IN_SECONDS : time() + DAY_IN_SECONDS;
		$count  = 0;

		\WP_CLI::confirm( 'Delete what is in the trash for good? This cannot be undone.', $assoc_args );

		do {
			$ids = Items::expired( $before, 100 );

			foreach ( $ids as $id ) {
				$this->purge_all( (int) $id );
				++$count;
			}
		} while ( array() !== $ids );

		\WP_CLI::success( sprintf( 'Deleted %d item(s).', $count ) );
	}

	/**
	 * Deletes one item and all the content parked with it.
	 *
	 * @param int $id The parked post ID.
	 */
	private function purge_all( int $id ): void {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || Status::STATUS !== $post->post_status ) {
			\WP_CLI::warning( sprintf( '%d is not in the trash.', $id ) );
			return;
		}

		do {
			$left = $this->trash->purge( $id );
		} while ( is_int( $left ) && $left > 0 );
	}
}
