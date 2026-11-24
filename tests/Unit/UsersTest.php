<?php
/**
 * A deleted user's snapshot: no password, a new one on restore, a reset link only on request.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Upgrade;
use AryanX\Axle\Trash\Users;
use Brain\Monkey\Functions;

/**
 * A database that returns one user row and records what is inserted.
 */
final class UsersWpdb {
	public $users    = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $posts    = 'wp_posts';
	/** @var array */
	public $row = array();
	/** @var array */
	public $meta = array();
	/** @var array */
	public $inserted = array();

	public function prepare( $query ) {
		return $query;
	}

	public function get_row() {
		return $this->row;
	}

	public function get_results() {
		return $this->meta;
	}

	public function insert( $table, $data ) {
		$this->inserted[] = array( $table, $data );
		return 1;
	}
}

/**
 * Passwords stay out of the snapshot, and a restored account gets a random one.
 */
final class UsersTest extends TestCase {

	private UsersWpdb $db;

	/**
	 * Post meta written, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = array();

	/**
	 * Post meta read, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	protected function setUp(): void {
		parent::setUp();

		$this->db         = new UsersWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->saved     = array();
		$this->stored    = array();

		$this->db->row = array(
			'ID'                  => 9,
			'user_login'          => 'dana',
			'user_pass'           => '$P$OLDHASH',
			'user_nicename'       => 'dana',
			'user_email'          => 'dana@example.test',
			'user_activation_key' => '1700000000:RESETKEY',
			'display_name'        => 'Dana',
		);
		$this->db->meta = array(
			array( 'meta_key' => 'nickname', 'meta_value' => 'dana' ),
			array( 'meta_key' => 'session_tokens', 'meta_value' => 'a:0:{}' ),
			array( 'meta_key' => '_application_passwords', 'meta_value' => 'a:1:{i:0;a:1:{s:8:"password";s:6:"HASHED";}}' ),
		);

		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_insert_post' )->justReturn( 55 );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->saved[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key ) => $this->stored[ $key ] ?? '' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_the_snapshot_holds_no_password_hash_key_or_api_password(): void {
		$id = ( new Users() )->snapshot( new \WP_User( array( 'ID' => 9, 'user_login' => 'dana', 'user_email' => 'dana@example.test', 'display_name' => 'Dana' ) ), null );

		$this->assertSame( 55, $id );

		$json = (string) $this->saved[ Status::META_USER ];
		$data = json_decode( $json, true );

		$this->assertArrayNotHasKey( 'user_pass', $data['row'] );
		$this->assertArrayNotHasKey( 'user_activation_key', $data['row'] );
		$this->assertStringNotContainsString( 'OLDHASH', $json );
		$this->assertStringNotContainsString( 'RESETKEY', $json );
		$this->assertStringNotContainsString( 'HASHED', $json );
		$this->assertSame( array( array( 'nickname', 'dana' ) ), $data['meta'] );
	}

	private function keep( array $row, array $meta = array(), array $roles = array( 'editor' ) ): void {
		$this->stored[ Status::META_USER ] = json_encode(
			array(
				'row'   => $row,
				'meta'  => $meta,
				'roles' => $roles,
			)
		);
	}

	private function allow_restore(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'email_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-password' );
		Functions\when( 'wp_hash_password' )->alias( static fn ( $pw ) => 'hash-of-' . $pw );
		Functions\when( 'clean_user_cache' )->justReturn( null );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'wp_delete_post' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'roles' => array( 'editor' => array(), 'subscriber' => array() ) ) );
	}

	public function test_a_restored_account_gets_a_new_random_password_even_from_an_old_snapshot(): void {
		$this->allow_restore();
		// A snapshot saved before the privacy change still holds the old hash and an extra column.
		$this->keep(
			array_merge( $this->db->row, array( 'evil_column' => 'x' ) ),
			array( array( 'nickname', 'dana' ), array( '_application_passwords', 'HASHED' ), array( 'session_tokens', 'x' ) )
		);

		$this->assertTrue( ( new Users() )->restore( 55 ) );

		$this->assertSame( 'wp_users', $this->db->inserted[0][0] );
		$user = $this->db->inserted[0][1];
		$this->assertSame( 'hash-of-random-password', $user['user_pass'] );
		$this->assertSame( '', $user['user_activation_key'] );
		$this->assertArrayNotHasKey( 'evil_column', $user );
		$this->assertSame( 9, $user['ID'] );

		$meta_keys = array_map( static fn ( $call ) => $call[1]['meta_key'] ?? '', array_slice( $this->db->inserted, 1 ) );
		$this->assertSame( array( 'nickname' ), $meta_keys );
	}

	public function test_nothing_is_sent_when_an_account_comes_back(): void {
		$this->allow_restore();
		$this->keep( $this->db->row );

		Functions\expect( 'retrieve_password' )->never();
		Functions\expect( 'wp_mail' )->never();

		$this->assertTrue( ( new Users() )->restore( 55 ) );
	}

	public function test_a_role_the_person_may_not_give_blocks_the_restore(): void {
		$this->allow_restore();
		$this->keep( $this->db->row, array(), array( 'administrator' ) );

		$result = ( new Users() )->restore( 55 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_trash_role_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), $this->db->inserted );
	}

	public function test_wp_cli_and_cron_may_restore_any_role(): void {
		$this->allow_restore();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->keep( $this->db->row, array(), array( 'administrator' ) );

		$this->assertTrue( ( new Users() )->restore( 55 ) );
	}

	public function test_the_reset_link_needs_the_right_to_edit_that_user(): void {
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 9, 'user_login' => 'dana' ) ) );
		Functions\when( 'current_user_can' )->alias( static fn ( $cap, $id = 0 ) => false );
		Functions\expect( 'retrieve_password' )->never();

		$result = ( new Users() )->send_reset_link( 9 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_trash_reset_denied', $result->get_error_code() );
	}

	public function test_the_reset_link_goes_out_through_core_when_asked(): void {
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 9, 'user_login' => 'dana' ) ) );
		Functions\when( 'current_user_can' )->alias( static fn ( $cap, $id = 0 ) => 'edit_user' === $cap && 9 === $id );
		Functions\expect( 'retrieve_password' )->once()->with( 'dana' )->andReturn( true );

		$this->assertTrue( ( new Users() )->send_reset_link( 9 ) );
	}

	public function test_a_missing_user_gets_no_reset_link(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\expect( 'retrieve_password' )->never();

		$this->assertInstanceOf( \WP_Error::class, ( new Users() )->send_reset_link( 404 ) );
	}

	public function test_the_restore_notice_is_remembered_for_one_person_and_shown_once(): void {
		$transients = array();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				unset( $transients[ $key ] );
				return true;
			}
		);

		$users = new Users();
		$users->queue_reset_notice( 9, 'dana' );

		$this->assertSame( array( 'axle_trash_reset_3' ), array_keys( $transients ) );
		$this->assertSame( array( 9 => 'dana' ), $users->take_reset_notices() );
		$this->assertSame( array(), $users->take_reset_notices() );
	}

	public function test_an_account_that_is_still_present_needs_no_reset(): void {
		$this->keep( $this->db->row );
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->assertSame( 9, ( new Users() )->needs_reset( 55 ) );

		Functions\when( 'get_userdata' )->justReturn( new \WP_User( array( 'ID' => 9 ) ) );
		$this->assertSame( 0, ( new Users() )->needs_reset( 55 ) );
		$this->assertSame( 0, ( new Users() )->needs_reset( 0 ) );
	}

	public function test_scrub_blanks_what_an_old_snapshot_kept(): void {
		$this->keep(
			$this->db->row,
			array( array( 'nickname', 'dana' ), array( '_application_passwords', 'HASHED' ) )
		);

		$this->assertTrue( ( new Users() )->scrub( 55 ) );

		$json = (string) $this->saved[ Status::META_USER ];
		$this->assertStringNotContainsString( 'OLDHASH', $json );
		$this->assertStringNotContainsString( 'RESETKEY', $json );
		$this->assertStringNotContainsString( 'HASHED', $json );
		$this->assertStringContainsString( 'dana@example.test', $json );
	}

	public function test_scrub_leaves_a_clean_snapshot_alone(): void {
		$this->keep( Users::without_secrets( $this->db->row ), array( array( 'nickname', 'dana' ) ) );

		$this->assertFalse( ( new Users() )->scrub( 55 ) );
		$this->assertSame( array(), $this->saved );
	}

	public function test_the_upgrade_runs_once_and_remembers_it(): void {
		$scrubbed = 0;
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$scrubbed ) {
				++$scrubbed;
				return array();
			}
		);

		$upgrade = new Upgrade();
		$upgrade->maybe_run();

		$this->assertSame( 1, $scrubbed );
		$this->assertSame( Upgrade::CURRENT, $this->options[ Upgrade::OPTION ] );

		$upgrade->maybe_run();
		$this->assertSame( 1, $scrubbed );
	}

	public function test_the_upgrade_rewrites_every_old_snapshot(): void {
		$this->keep( $this->db->row );
		Functions\when( 'get_posts' )->justReturn( array( 55 ) );

		$this->assertSame( 1, ( new Upgrade() )->scrub_snapshots() );
		$this->assertStringNotContainsString( 'OLDHASH', (string) $this->saved[ Status::META_USER ] );
	}
}
