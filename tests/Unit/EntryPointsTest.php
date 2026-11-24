<?php
/**
 * Every request that changes data checks its nonce first, then what the person may do.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Admin\Page;
use AryanX\Axle\Trash\Trash;
use Brain\Monkey\Functions;

/**
 * Stops a request the moment a check refuses it.
 */
final class Refused extends \RuntimeException {
}

/**
 * The Trash screen's handlers and the settings form.
 */
final class EntryPointsTest extends TestCase {

	/**
	 * The nonce actions checked, in order.
	 *
	 * @var string[]
	 */
	private array $nonces = array();

	protected function setUp(): void {
		parent::setUp();

		$this->nonces = array();
		$_GET         = array();
		$_POST        = array();
		$_REQUEST     = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( $args, $url ) => $url . '&' . http_build_query( (array) $args ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'absint' )->alias( static fn ( $n ) => abs( (int) $n ) );
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->nonces[] = $action;
				throw new Refused( 'nonce:' . $action );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new Refused( 'die' );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new Refused( 'redirect:' . $url );
			}
		);
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Requests that change something, with the nonce action each must check.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
	 */
	public static function changing_requests(): array {
		return array(
			'restore one'    => array( array(), array( 'axle_action' => 'restore', 'item' => '5' ), 'axle_trash_restore_5' ),
			'delete'         => array( array( 'axle_action' => 'delete', 'item' => array( '5' ) ), array(), 'axle_trash_delete' ),
			'empty'          => array( array( 'axle_action' => 'empty' ), array(), 'axle_trash_empty' ),
			'send reset'     => array( array( 'axle_action' => 'send_reset', 'item' => '7' ), array(), 'axle_trash_reset_7' ),
			'bulk restore'   => array( array( 'action' => 'restore', 'item' => array( '5' ) ), array(), 'bulk-items' ),
			'bulk delete'    => array( array( 'action' => 'delete', 'item' => array( '5' ) ), array(), 'bulk-items' ),
			'empty the trash button' => array( array( 'axle_trash_empty' => 'Empty the trash' ), array(), 'bulk-items' ),
		);
	}

	/**
	 * @dataProvider changing_requests
	 *
	 * @param array<string, mixed> $post  POST fields.
	 * @param array<string, mixed> $get   GET fields.
	 * @param string               $nonce The nonce action that must be checked.
	 */
	public function test_every_changing_request_checks_its_nonce_before_anything_else( array $post, array $get, string $nonce ): void {
		$_POST    = $post;
		$_GET     = $get;
		$_REQUEST = array_merge( $get, $post );

		// If the code got past the nonce, it would read a post. That must not happen.
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'wp_delete_post' )->never();

		try {
			( new Page( new Trash() ) )->load();
			$this->fail( 'The request was not stopped.' );
		} catch ( Refused $e ) {
			$this->assertSame( 'nonce:' . $nonce, $e->getMessage() );
		}

		$this->assertSame( array( $nonce ), $this->nonces );
	}

	public function test_a_person_with_nothing_to_do_here_is_stopped_before_any_handler(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST    = array( 'axle_action' => 'delete', 'item' => array( '5' ) );
		$_REQUEST = $_POST;

		$this->expectException( Refused::class );
		$this->expectExceptionMessage( 'die' );

		( new Page( new Trash() ) )->load();
	}

	public function test_the_settings_form_checks_its_nonce_and_then_the_capability(): void {
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->nonces[] = $action;
				return 1;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$_POST = array( 'kinds' => array( 'template' ), 'retention' => '5' );

		try {
			( new Page( new Trash() ) )->save_settings();
			$this->fail( 'The request was not stopped.' );
		} catch ( Refused $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}

		$this->assertSame( array( 'axle_trash_save_settings' ), $this->nonces );
	}

	public function test_the_settings_form_saves_for_a_person_who_may(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$_POST = array( 'kinds' => array( 'template', '<b>x</b>' ), 'retention' => '5000' );

		try {
			( new Page( new Trash() ) )->save_settings();
		} catch ( Refused $e ) {
			$this->assertStringContainsString( 'axle_done=saved', $e->getMessage() );
		}

		$this->assertSame(
			array(
				'kinds'     => array( 'template' ),
				'retention' => 3650,
			),
			$this->options['axle_trash_settings']
		);
	}
}
