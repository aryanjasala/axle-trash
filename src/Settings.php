<?php
/**
 * Settings: which kinds are kept and for how long.
 *
 * @package AryanX\Axle\Trash
 */

namespace AryanX\Axle\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the plugin's one option.
 */
final class Settings {

	/** The option name. */
	public const OPTION = 'axle_trash_settings';

	/** The default retention in days. */
	public const DEFAULT_DAYS = 30;

	/**
	 * The saved settings with defaults filled in.
	 *
	 * @return array{kinds: string[], retention: int}
	 */
	public static function saved(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$known = array_keys( Kinds::all() );

		$kinds = isset( $saved['kinds'] ) && is_array( $saved['kinds'] ) ? array_values( array_intersect( $known, array_map( 'strval', $saved['kinds'] ) ) ) : $known;
		$days  = isset( $saved['retention'] ) ? (int) $saved['retention'] : self::DEFAULT_DAYS;

		return array(
			'kinds'     => $kinds,
			'retention' => $days > 0 ? $days : self::DEFAULT_DAYS,
		);
	}

	/**
	 * The kinds being kept, after the filter.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		$known = array_keys( Kinds::all() );

		/**
		 * Filters the kinds the trash keeps.
		 *
		 * @param string[] $kinds Kind keys: template, template_part, pattern, navigation, font, user.
		 */
		$filtered = (array) apply_filters( 'axle_trash_kinds', self::saved()['kinds'] );

		return array_values( array_intersect( $known, array_map( 'strval', $filtered ) ) );
	}

	/**
	 * Whether a kind is being kept.
	 *
	 * @param string $kind Kind key.
	 * @return bool
	 */
	public static function keeps( string $kind ): bool {
		return in_array( $kind, self::kinds(), true );
	}

	/**
	 * How many days an item stays, after the filter and clamped to 1 to 3650.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days a parked item stays before the daily job deletes it for good.
		 *
		 * @param int $days Days. Clamped to 1 to 3650.
		 */
		$days = (int) apply_filters( 'axle_trash_retention_days', self::saved()['retention'] );

		return max( 1, min( 3650, $days ) );
	}

	/**
	 * Saves the settings from a form submission.
	 *
	 * @param array<string, mixed> $input Raw input: `kinds` (list) and `retention` (number).
	 */
	public static function save( array $input ): void {
		$known = array_keys( Kinds::all() );
		$kinds = isset( $input['kinds'] ) && is_array( $input['kinds'] ) ? array_values( array_intersect( $known, array_map( 'sanitize_key', $input['kinds'] ) ) ) : array();
		$days  = isset( $input['retention'] ) ? (int) $input['retention'] : self::DEFAULT_DAYS;

		update_option(
			self::OPTION,
			array(
				'kinds'     => $kinds,
				'retention' => max( 1, min( 3650, $days ) ),
			),
			false
		);
	}
}
