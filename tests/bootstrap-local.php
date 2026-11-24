<?php
/**
 * Extra stand-ins for the unit tests. The shared template never overwrites this file.
 *
 * @package AryanX\Axle\Trash
 */

// phpcs:ignoreFile

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * A post type with the capability map.
	 */
	class WP_Post_Type {
		/** @var object */
		public $cap;

		public function __construct( array $cap = array() ) {
			$this->cap = (object) $cap;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user with the fields the plugin reads.
	 */
	#[\AllowDynamicProperties]
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $user_login = '';
		/** @var string */
		public $user_email = '';
		/** @var string */
		public $display_name = '';
		/** @var array */
		public $roles = array();

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The constants routes use.
	 */
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}
