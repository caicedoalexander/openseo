<?php
/**
 * Safe, plugin-controlled regex matching for redirect rules.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure regex utilities. The plugin owns the delimiter and flags; the user only
 * ever supplies the bare pattern, which bounds the ReDoS / injection surface.
 */
final class Regex {

	/**
	 * Delimiter the plugin wraps around every user pattern.
	 */
	private const DELIMITER = '#';

	/**
	 * Maximum accepted pattern length.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * Wrap a bare user pattern with the controlled delimiter and flags.
	 *
	 * @param string $pattern Bare user pattern.
	 * @return string Compiled regex pattern with delimiter and flags.
	 */
	private static function compile( string $pattern ): string {
		$escaped = str_replace( self::DELIMITER, '\\' . self::DELIMITER, $pattern );

		return self::DELIMITER . $escaped . self::DELIMITER . 'u';
	}

	/**
	 * Whether a bare pattern is within length and compiles cleanly.
	 *
	 * @param string $pattern Bare user pattern.
	 * @return bool True if pattern is valid; false otherwise.
	 */
	public static function is_valid( string $pattern ): bool {
		if ( '' === $pattern || strlen( $pattern ) > self::MAX_LENGTH ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- Silencing is intentional; we check the result.
		return false !== @preg_match( self::compile( $pattern ), '' );
	}

	/**
	 * Match a path; return capture groups (index 0 = full match) or null.
	 *
	 * @param string $pattern Bare user pattern.
	 * @param string $path    Path to match against.
	 * @return array<int, string>|null Capture groups on match, null otherwise.
	 */
	public static function match( string $pattern, string $path ): ?array {
		$matches = array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors -- Silencing is intentional; we check the result.
		$result = @preg_match( self::compile( $pattern ), $path, $matches );

		return 1 === $result ? $matches : null;
	}

	/**
	 * Replace $1 / ${1} backreferences in a target with capture groups.
	 *
	 * @param string             $target  Target string with $1 / ${1} backreferences.
	 * @param array<int, string> $matches Capture groups from match().
	 * @return string Target with backreferences replaced.
	 */
	public static function substitute( string $target, array $matches ): string {
		return (string) preg_replace_callback(
			'/\$\{?(\d+)\}?/',
			static fn ( array $m ): string => $matches[ (int) $m[1] ] ?? '',
			$target
		);
	}
}
