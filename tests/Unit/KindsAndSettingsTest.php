<?php
/**
 * Kinds, capabilities and settings.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash\Tests\Unit;

use AryanX\Axle\Trash\Kinds;
use AryanX\Axle\Trash\Settings;
use Brain\Monkey\Filters;

/**
 * The defaults, the filters and what falls back when a filter returns rubbish.
 */
final class KindsAndSettingsTest extends TestCase {

	public function test_post_types_map_to_kinds(): void {
		$this->assertSame( 'pattern', Kinds::for_post_type( 'wp_block' ) );
		$this->assertSame( 'font', Kinds::for_post_type( 'wp_font_face' ) );
		$this->assertSame( 'font', Kinds::for_post_type( 'wp_font_family' ) );
		$this->assertSame( 'user', Kinds::for_post_type( 'axle_trash_user' ) );
		$this->assertSame( '', Kinds::for_post_type( 'post' ) );
	}

	public function test_every_kind_belongs_to_a_view(): void {
		foreach ( Kinds::all() as $kind ) {
			$this->assertArrayHasKey( $kind['view'], Kinds::views() );
		}
		$this->assertSame( array( 'font' ), Kinds::in_view( 'fonts' ) );
	}

	public function test_capability_defaults_and_filter(): void {
		$this->assertSame( 'edit_theme_options', Kinds::capability( 'template' ) );
		$this->assertSame( 'delete_post', Kinds::capability( 'pattern' ) );
		$this->assertSame( 'delete_users', Kinds::capability( 'user' ) );
		Filters\expectApplied( 'axle_trash_capability' )->once()->with( 'edit_theme_options', 'navigation' )->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Kinds::capability( 'navigation' ) );
	}

	public function test_empty_capability_filter_falls_back(): void {
		Filters\expectApplied( 'axle_trash_capability' )->once()->andReturn( '' );
		$this->assertSame( 'delete_users', Kinds::capability( 'user' ) );
	}

	public function test_every_kind_is_kept_by_default(): void {
		$this->assertSame( array_keys( Kinds::all() ), Settings::kinds() );
		$this->assertSame( 30, Settings::retention_days() );
	}

	public function test_saved_kinds_and_retention(): void {
		$this->options[ Settings::OPTION ] = array(
			'kinds'     => array( 'template', 'nonsense' ),
			'retention' => 7,
		);
		$this->assertSame( array( 'template' ), Settings::kinds() );
		$this->assertTrue( Settings::keeps( 'template' ) );
		$this->assertFalse( Settings::keeps( 'pattern' ) );
		$this->assertSame( 7, Settings::retention_days() );
	}

	public function test_retention_is_clamped_after_the_filter(): void {
		Filters\expectApplied( 'axle_trash_retention_days' )->once()->andReturn( 99999 );
		$this->assertSame( 3650, Settings::retention_days() );
		Filters\expectApplied( 'axle_trash_retention_days' )->once()->andReturn( -4 );
		$this->assertSame( 1, Settings::retention_days() );
	}

	public function test_kinds_filter_drops_unknown_kinds(): void {
		Filters\expectApplied( 'axle_trash_kinds' )->once()->andReturn( array( 'user', 'bogus' ) );
		$this->assertSame( array( 'user' ), Settings::kinds() );
	}

	public function test_save_clamps_and_sanitises(): void {
		\Brain\Monkey\Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );
		Settings::save(
			array(
				'kinds'     => array( 'pattern', 'x' ),
				'retention' => 9000,
			)
		);
		$this->assertSame(
			array(
				'kinds'     => array( 'pattern' ),
				'retention' => 3650,
			),
			$this->options[ Settings::OPTION ]
		);
	}
}
