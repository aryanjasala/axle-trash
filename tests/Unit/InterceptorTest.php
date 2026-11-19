<?php
/**
 * What the interceptor parks and what it leaves to core.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Interceptor;
use AryanX\Axle\Trash\Settings;
use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Trash;
use AryanX\Axle\Trash\Users;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A fake database that records the status update.
 */
final class FakeWpdb {
	public $posts = 'wp_posts';
	public $updates = array();
	public function update( $table, $data, $where ) {
		$this->updates[] = array( $table, $data, $where );
		return 1;
	}
}

/**
 * The decision logic of pre_delete_post, pre_delete_attachment and the user hooks.
 */
final class InterceptorTest extends TestCase {

	private Interceptor $interceptor;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_get_object_terms' )->justReturn( array( 'twentytwentyfive' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'doing_action' )->justReturn( false );
		$this->interceptor = new Interceptor( new Trash(), new Users() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function post( string $type, string $status = 'publish', int $id = 12 ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => $status,
				'post_author' => 5,
			)
		);
	}

	public function test_a_synced_pattern_is_parked_and_core_gets_the_post_back(): void {
		Actions\expectDone( 'axle_trash_parked' )->once();
		$post   = $this->post( 'wp_block' );
		$result = $this->interceptor->pre_delete_post( null, $post, true );

		$this->assertSame( $post, $result );
		$this->assertSame( array( 'wp_posts', array( 'post_status' => Status::STATUS ), array( 'ID' => 12 ) ), $GLOBALS['wpdb']->updates[0] );
	}

	public function test_another_plugins_answer_is_left_alone(): void {
		$this->assertFalse( $this->interceptor->pre_delete_post( false, $this->post( 'wp_block' ), true ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->updates );
	}

	public function test_ordinary_posts_are_left_to_core(): void {
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'post', 'trash' ), true ) );
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'axle_book' ), true ) );
	}

	public function test_a_parked_item_is_deleted_for_real_when_purged(): void {
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'wp_block', Status::STATUS ), true ) );
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( Status::SNAPSHOT ), true ) );
	}

	public function test_a_kind_that_is_switched_off_is_deleted_as_before(): void {
		$this->options[ Settings::OPTION ] = array(
			'kinds'     => array( 'template' ),
			'retention' => 30,
		);
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'wp_block' ), true ) );
	}

	public function test_the_park_filter_can_say_no(): void {
		Filters\expectApplied( 'axle_trash_park' )->once()->andReturn( false );
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'wp_template' ), true ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->updates );
	}

	public function test_core_emptying_its_own_trash_is_not_parked(): void {
		Functions\when( 'doing_action' )->alias( static fn ( $name ) => 'wp_scheduled_delete' === $name );
		$this->assertNull( $this->interceptor->pre_delete_post( null, $this->post( 'wp_block', 'trash' ), true ) );
	}

	public function test_attachments_are_only_parked_during_a_user_deletion(): void {
		$this->assertNull( $this->interceptor->pre_delete_attachment( null, $this->post( 'attachment', 'inherit' ), true ) );
	}

	public function test_the_rest_response_names_what_to_restore(): void {
		Actions\expectDone( 'axle_trash_parked' )->once();
		$post = $this->post( 'wp_block', 'publish', 44 );
		$this->interceptor->pre_delete_post( null, $post, true );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'rest_url' )->alias( static fn ( $path ) => 'https://example.test/wp-json/' . $path );

		$request = new class() extends \WP_REST_Request {
			public function get_method() {
				return 'DELETE';
			}
		};
		$result  = $this->interceptor->annotate_response( new \WP_REST_Response( array( 'deleted' => true ) ), null, $request );

		$this->assertSame( 44, $result->get_data()['axle_trash']['id'] );
		$this->assertSame( 'pattern', $result->get_data()['axle_trash']['kind'] );
	}
}
