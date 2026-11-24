<?php
/**
 * The privacy text, exporter and eraser.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Privacy;
use AryanX\Axle\Trash\Trash;
use Brain\Monkey\Functions;

/**
 * What the policy text promises, and that the eraser pages through everything.
 */
final class PrivacyTest extends TestCase {

	public function test_the_policy_text_says_the_password_is_not_kept(): void {
		$text = '';
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			static function ( $name, $content ) use ( &$text ) {
				$text = $content;
			}
		);

		( new Privacy( new Trash() ) )->policy_text();

		$this->assertStringContainsString( 'does not include the account\'s password', $text );
		$this->assertStringContainsString( 'new random password', $text );
		$this->assertStringContainsString( 'password reset link', $text );
		$this->assertStringNotContainsString( "\u{2014}", $text );
		$this->assertStringNotContainsString( "\u{2013}", $text );
	}

	public function test_the_eraser_is_not_done_while_a_full_page_remains(): void {
		Functions\when( 'get_posts' )->justReturn( range( 1, 50 ) );
		Functions\when( 'get_post' )->justReturn( null );

		$result = ( new Privacy( new Trash() ) )->erase( 'dana@example.test' );

		$this->assertFalse( $result['done'] );
	}

	public function test_the_eraser_is_done_when_nothing_is_left(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		$result = ( new Privacy( new Trash() ) )->erase( 'dana@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertFalse( $result['items_removed'] );
	}
}
