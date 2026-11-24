<?php
/**
 * Who may see, restore and delete a parked item, on every way in.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Abilities;
use AryanX\Axle\Trash\Items;
use AryanX\Axle\Trash\Rest\Controller;
use AryanX\Axle\Trash\Status;
use AryanX\Axle\Trash\Trash;
use AryanX\Axle\Trash\Usage;
use Brain\Monkey\Functions;

/**
 * Capability checks per item kind, and the same answer for a missing and a forbidden item.
 */
final class AccessTest extends TestCase {

	/**
	 * Capabilities the fake person holds.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'user_can' )->alias( fn ( $user, $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'current_user_can' )->alias( fn ( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_post_type_object' )->justReturn(
			new \WP_Post_Type(
				array(
					'delete_posts'           => 'delete_posts',
					'delete_others_posts'    => 'delete_others_posts',
					'delete_published_posts' => 'delete_published_posts',
					'delete_private_posts'   => 'delete_private_posts',
				)
			)
		);
	}

	private function parked( string $type, string $was = 'publish', int $author = 3, int $id = 20 ): \WP_Post {
		Functions\when( 'get_post_meta' )->alias( static fn ( $post_id, $key ) => Status::META_STATUS === $key ? $was : '' );

		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => Status::STATUS,
				'post_author' => $author,
			)
		);
	}

	public function test_a_published_pattern_needs_the_right_to_delete_published_items(): void {
		$this->caps = array( 'delete_posts', 'delete_others_posts' );

		$this->assertFalse( Items::can( $this->parked( 'wp_block', 'publish' ) ) );
	}

	public function test_a_draft_pattern_does_not(): void {
		$this->caps = array( 'delete_others_posts' );

		$this->assertTrue( Items::can( $this->parked( 'wp_block', 'draft' ) ) );
	}

	public function test_the_status_before_parking_counts_not_the_parked_one(): void {
		$this->caps = array( 'delete_others_posts', 'delete_published_posts' );

		$this->assertTrue( Items::can( $this->parked( 'wp_block', 'publish' ) ) );
	}

	public function test_someone_elses_pattern_needs_the_right_to_delete_others_posts(): void {
		$this->caps = array( 'delete_posts', 'delete_published_posts' );

		$this->assertFalse( Items::can( $this->parked( 'wp_block', 'publish', 3 ) ) );
		$this->assertTrue( Items::can( $this->parked( 'wp_block', 'publish', 7 ) ) );
	}

	public function test_a_private_pattern_needs_the_private_right(): void {
		$this->caps = array( 'delete_others_posts' );

		$this->assertFalse( Items::can( $this->parked( 'wp_block', 'private' ) ) );
	}

	public function test_templates_and_users_use_their_own_capability(): void {
		$this->caps = array( 'edit_theme_options' );
		$this->assertTrue( Items::can( $this->parked( 'wp_template' ) ) );
		$this->assertTrue( Items::can( $this->parked( 'wp_navigation' ) ) );
		$this->assertTrue( Items::can( $this->parked( 'wp_font_family' ) ) );
		$this->assertFalse( Items::can( $this->parked( Status::SNAPSHOT ) ) );

		$this->caps = array( 'delete_users' );
		$this->assertTrue( Items::can( $this->parked( Status::SNAPSHOT ) ) );
		$this->assertFalse( Items::can( $this->parked( 'wp_template' ) ) );
	}

	public function test_content_of_an_unknown_post_type_is_never_actionable(): void {
		$this->caps = array( 'edit_theme_options', 'delete_users', 'delete_posts', 'manage_options' );

		$this->assertFalse( Items::can( $this->parked( 'attachment' ) ) );
		$this->assertFalse( Items::can( $this->parked( 'axle_book' ) ) );
	}

	public function test_the_rest_route_answers_missing_and_forbidden_alike(): void {
		$controller = new Controller( new Trash() );
		$request    = new \WP_REST_Request( array( 'id' => 20 ) );

		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		Functions\when( 'get_post' )->justReturn( null );
		$missing = $controller->can_restore( $request );

		$this->caps = array();
		Functions\when( 'get_post' )->justReturn( $this->parked( 'wp_template' ) );
		$forbidden = $controller->can_restore( $request );

		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertInstanceOf( \WP_Error::class, $forbidden );
		$this->assertSame( $missing->get_error_code(), $forbidden->get_error_code() );
		$this->assertSame( $missing->get_error_message(), $forbidden->get_error_message() );

		$this->caps = array( 'edit_theme_options' );
		$this->assertTrue( $controller->can_restore( $request ) );
	}

	public function test_the_rest_route_refuses_a_post_that_is_not_parked(): void {
		$this->caps = array( 'edit_theme_options', 'delete_users' );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'get_post' )->justReturn( new \WP_Post( array( 'ID' => 9, 'post_type' => 'wp_template', 'post_status' => 'publish' ) ) );

		$this->assertInstanceOf( \WP_Error::class, ( new Controller( new Trash() ) )->can_restore( new \WP_REST_Request( array( 'id' => 9 ) ) ) );
	}

	public function test_abilities_check_the_item_before_restore_and_delete(): void {
		$abilities = new Abilities( new Trash() );

		Functions\when( 'get_post' )->justReturn( $this->parked( 'wp_template' ) );
		$this->caps = array();

		$this->assertFalse( $abilities->can_act( array( 'id' => 20 ) ) );
		$this->assertFalse( $abilities->can_act( null ) );
		$this->assertFalse( $abilities->can_act( array( 'id' => -4 ) ) );

		// The execute methods check again, so calling them directly cannot skip the permission callback.
		$restore = $abilities->restore_item( array( 'id' => 20 ) );
		$delete  = $abilities->delete_item( array( 'id' => 20 ) );

		$this->assertInstanceOf( \WP_Error::class, $restore );
		$this->assertSame( 'axle_trash_forbidden', $restore->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $delete );
		$this->assertSame( 'axle_trash_forbidden', $delete->get_error_code() );

		$this->caps = array( 'edit_theme_options' );
		$this->assertTrue( $abilities->can_act( array( 'id' => 20 ) ) );
	}

	public function test_a_usage_count_only_counts_what_the_person_can_edit(): void {
		Functions\when( 'get_posts' )->justReturn( range( 1, 300 ) );
		Functions\when( '_n' )->alias( static fn ( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => (string) $n );

		$template = new \WP_Post( array( 'ID' => 20, 'post_type' => 'wp_template', 'post_name' => 'landing' ) );

		// 300 pages use it, none can be edited: the answer is empty, with no "+" and no number.
		$this->caps = array();
		$this->assertSame( '', ( new Usage() )->describe( $template ) );

		// All 300 can be edited: the count is shown and capped.
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertSame( 'Used by 300+ pages', ( new Usage() )->describe( $template ) );
	}
}
