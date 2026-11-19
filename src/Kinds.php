<?php
/**
 * The kinds of item the trash keeps.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Maps post types to kinds, kinds to the screen's views, and kinds to the
 * capability that decides who may see and restore them.
 */
final class Kinds {

	/** The user snapshot kind. */
	public const USER = 'user';

	/**
	 * Every kind: label, plural label, the view it belongs to, its post types and
	 * the capability that stands for "could have deleted it".
	 *
	 * @return array<string, array{label: string, plural: string, view: string, types: string[], cap: string}>
	 */
	public static function all(): array {
		return array(
			'template'      => array(
				'label'  => __( 'Template', 'axle-trash' ),
				'plural' => __( 'Templates', 'axle-trash' ),
				'view'   => 'site-editor',
				'types'  => array( 'wp_template' ),
				'cap'    => 'edit_theme_options',
			),
			'template_part' => array(
				'label'  => __( 'Template part', 'axle-trash' ),
				'plural' => __( 'Template parts', 'axle-trash' ),
				'view'   => 'site-editor',
				'types'  => array( 'wp_template_part' ),
				'cap'    => 'edit_theme_options',
			),
			'pattern'       => array(
				'label'  => __( 'Pattern', 'axle-trash' ),
				'plural' => __( 'Patterns', 'axle-trash' ),
				'view'   => 'site-editor',
				'types'  => array( 'wp_block' ),
				'cap'    => 'delete_post',
			),
			'navigation'    => array(
				'label'  => __( 'Navigation menu', 'axle-trash' ),
				'plural' => __( 'Navigation menus', 'axle-trash' ),
				'view'   => 'site-editor',
				'types'  => array( 'wp_navigation' ),
				'cap'    => 'edit_theme_options',
			),
			'font'          => array(
				'label'  => __( 'Font', 'axle-trash' ),
				'plural' => __( 'Fonts', 'axle-trash' ),
				'view'   => 'fonts',
				'types'  => array( 'wp_font_family', 'wp_font_face' ),
				'cap'    => 'edit_theme_options',
			),
			self::USER      => array(
				'label'  => __( 'User', 'axle-trash' ),
				'plural' => __( 'Users', 'axle-trash' ),
				'view'   => 'users',
				'types'  => array( Status::SNAPSHOT ),
				'cap'    => 'delete_users',
			),
		);
	}

	/**
	 * The screen's views, in order, with the kinds in each.
	 *
	 * @return array<string, string> View key => label.
	 */
	public static function views(): array {
		return array(
			'site-editor' => __( 'Site Editor', 'axle-trash' ),
			'fonts'       => __( 'Fonts', 'axle-trash' ),
			'users'       => __( 'Users', 'axle-trash' ),
		);
	}

	/**
	 * The kind of a post type, or an empty string when the trash does not keep it.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function for_post_type( string $post_type ): string {
		foreach ( self::all() as $key => $kind ) {
			if ( in_array( $post_type, $kind['types'], true ) ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * The kinds that belong to a view.
	 *
	 * @param string $view View key.
	 * @return string[]
	 */
	public static function in_view( string $view ): array {
		$keys = array();
		foreach ( self::all() as $key => $kind ) {
			if ( $kind['view'] === $view ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Every post type the trash lists at the top level.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( self::all() as $kind ) {
			$types = array_merge( $types, $kind['types'] );
		}

		return $types;
	}

	/**
	 * The capability for a kind, after the filter.
	 *
	 * @param string $kind Kind key.
	 * @return string The meta capability `delete_post` stands for the item's own check.
	 */
	public static function capability( string $kind ): string {
		$all = self::all();
		$cap = isset( $all[ $kind ] ) ? $all[ $kind ]['cap'] : 'manage_options';

		/**
		 * Filters the capability that decides who may see, restore and delete items of a kind.
		 *
		 * `delete_post` is checked against the item itself, like core checks a delete. Items a
		 * person could not have deleted are never listed or counted for them.
		 *
		 * @param string $cap  Capability.
		 * @param string $kind Kind key: template, template_part, pattern, navigation, font or user.
		 */
		$filtered = (string) apply_filters( 'axle_trash_capability', $cap, $kind );

		return '' !== $filtered ? $filtered : $cap;
	}
}
