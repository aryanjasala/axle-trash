<?php
/**
 * The list of parked items.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Admin;

use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Kinds;
use AryanX\Axle\Trash\Usage;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A core list table with views, bulk actions and row actions. It only ever holds items
 * the person could have deleted.
 */
final class ItemsTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Counts for the views, from what the person may see.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * What still points at an item.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Sets up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'item',
				'plural'   => 'items',
				'ajax'     => false,
			)
		);
		$this->usage = new Usage();
	}

	/**
	 * The view being shown.
	 *
	 * @return string A view key, or an empty string for all.
	 */
	public static function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A filter on a list, not a state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		return isset( Kinds::views()[ $view ] ) ? $view : '';
	}

	/**
	 * Loads the rows for the current page.
	 */
	public function prepare_items(): void {
		$this->counts = Items::counts();
		$posts        = Items::visible( self::current_view() );
		$page         = $this->get_pagenum();

		$this->set_pagination_args(
			array(
				'total_items' => count( $posts ),
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
		$this->items           = array_slice( $posts, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
	}

	/**
	 * Whether there is anything in the trash for this person.
	 *
	 * @return bool
	 */
	public function has_any(): bool {
		return ( $this->counts['all'] ?? 0 ) > 0;
	}

	/**
	 * The views: All, Site Editor, Fonts, Users, with counts of what the person may see.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = self::current_view();
		$base    = Page::url();
		$views   = array(
			'all' => array( __( 'All', 'axle-trash' ), $this->counts['all'] ?? 0, '' ),
		);

		foreach ( Kinds::views() as $key => $label ) {
			$views[ $key ] = array( $label, $this->counts[ $key ] ?? 0, (string) $key );
		}

		$links = array();

		foreach ( $views as $key => $view ) {
			$links[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( '' === $view[2] ? $base : add_query_arg( 'view', $view[2], $base ) ),
				$view[2] === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $view[0] ),
				esc_html( number_format_i18n( $view[1] ) )
			);
		}

		return $links;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox" />',
			'title'   => __( 'Item', 'axle-trash' ),
			'kind'    => __( 'Type', 'axle-trash' ),
			'deleted' => __( 'Deleted', 'axle-trash' ),
			'used'    => __( 'Still in use', 'axle-trash' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'restore' => __( 'Restore', 'axle-trash' ),
			'delete'  => __( 'Delete permanently', 'axle-trash' ),
		);
	}

	/**
	 * The checkbox.
	 *
	 * @param \WP_Post $item The parked post.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		$row = Items::describe( $item );

		return sprintf(
			'<input type="checkbox" name="item[]" value="%1$d" id="axle-trash-cb-%1$d" /><label for="axle-trash-cb-%1$d"><span class="screen-reader-text">%2$s</span></label>',
			(int) $item->ID,
			/* translators: %s: the name of a deleted item. */
			esc_html( sprintf( __( 'Select %s', 'axle-trash' ), $row['title'] ) )
		);
	}

	/**
	 * The name, with Restore and Delete permanently under it.
	 *
	 * @param \WP_Post $item The parked post.
	 * @return string
	 */
	protected function column_title( $item ): string {
		$row     = Items::describe( $item );
		$restore = wp_nonce_url(
			add_query_arg(
				array(
					'axle_action' => 'restore',
					'item'        => $item->ID,
				),
				Page::url() 
			),
			'axle_trash_restore_' . $item->ID 
		);
		$delete  = add_query_arg(
			array(
				'axle_action' => 'confirm_delete',
				'item'        => $item->ID,
			),
			Page::url() 
		);
		$title   = '<strong>' . esc_html( $row['title'] ) . '</strong>';

		if ( '' !== $row['details'] ) {
			$title .= '<br><span class="description">' . esc_html( $row['details'] ) . '</span>';
		}

		return $title . $this->row_actions(
			array(
				'restore' => sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $restore ),
					/* translators: %s: the name of a deleted item. */
					esc_attr( sprintf( __( 'Restore %s', 'axle-trash' ), $row['title'] ) ),
					esc_html__( 'Restore', 'axle-trash' )
				),
				'delete'  => sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $delete ),
					/* translators: %s: the name of a deleted item. */
					esc_attr( sprintf( __( 'Delete %s permanently', 'axle-trash' ), $row['title'] ) ),
					esc_html__( 'Delete permanently', 'axle-trash' )
				),
			)
		);
	}

	/**
	 * What kind of item it is.
	 *
	 * @param \WP_Post $item The parked post.
	 * @return string
	 */
	protected function column_kind( $item ): string {
		return esc_html( Items::describe( $item )['kind_label'] );
	}

	/**
	 * Who deleted it and when.
	 *
	 * @param \WP_Post $item The parked post.
	 * @return string
	 */
	protected function column_deleted( $item ): string {
		$row = Items::describe( $item );
		$who = '' !== $row['deleted_by_name']
			/* translators: %s: a person's name. */
			? sprintf( __( 'by %s', 'axle-trash' ), $row['deleted_by_name'] )
			: __( 'by a script or WP-CLI', 'axle-trash' );

		return AxleUi::relative_time( $row['deleted_at'] ) . '<br><span class="description">' . esc_html( $who ) . '</span>';
	}

	/**
	 * What still points at it.
	 *
	 * @param \WP_Post $item The parked post.
	 * @return string
	 */
	protected function column_used( $item ): string {
		$text = $this->usage->describe( $item );

		return '' === $text ? '<span class="axle-muted">' . esc_html__( 'Not in use', 'axle-trash' ) . '</span>' : esc_html( $text );
	}

	/**
	 * Empty trash, next to the bulk actions.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || ! $this->has_any() ) {
			return;
		}

		echo '<div class="alignleft actions">';
		submit_button( __( 'Empty the trash', 'axle-trash' ), '', 'axle_trash_empty', false );
		echo '</div>';
	}

	/**
	 * What to show when there are no rows.
	 */
	public function no_items(): void {
		esc_html_e( 'Nothing here.', 'axle-trash' );
	}
}
