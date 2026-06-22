<?php
/**
 * Small pure string helpers (no WordPress).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Support;

/**
 * Multibyte-safe string utilities.
 */
final class Str {

	/**
	 * Uppercase the first letter of each word, preserving the rest of the word
	 * and the original whitespace. Mirrors Rank Math's Str::mb_ucwords (only the
	 * initial character is forced up; "iPhone" → "IPhone").
	 *
	 * @param string $value Input string.
	 */
	public static function mb_ucwords( string $value ): string {
		$words = preg_split( '/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $words ) ) {
			return $value;
		}

		$out = '';
		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			$out .= mb_strtoupper( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
		}

		return $out;
	}
}
