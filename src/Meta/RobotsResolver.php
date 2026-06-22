<?php
/**
 * Per-directive robots cascade: entry → type → global.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Resolves one robots directive across the three levels. Pure (no WordPress):
 * the most specific level with an opinion wins. Tri-state strings: 'on'/'1' =
 * force on, 'off' = force off, '' (or anything else) = inherit the next level.
 */
final class RobotsResolver {

	/**
	 * Effective boolean for one directive.
	 *
	 * @param string $entry          Per-entry value ('on'|'1'|'off'|'').
	 * @param string $type           Per-type value ('on'|'off'|'').
	 * @param bool   $global_default Global default (already cast to bool by the caller).
	 */
	public static function resolve( string $entry, string $type, bool $global_default ): bool {
		$at_entry = self::level( $entry );
		if ( null !== $at_entry ) {
			return $at_entry;
		}

		$at_type = self::level( $type );
		if ( null !== $at_type ) {
			return $at_type;
		}

		return $global_default;
	}

	/**
	 * Tri-state value to bool|null (null = inherit).
	 *
	 * @param string $value Tri-state string.
	 */
	private static function level( string $value ): ?bool {
		if ( 'on' === $value || '1' === $value ) {
			return true;
		}
		if ( 'off' === $value ) {
			return false;
		}
		return null;
	}
}
