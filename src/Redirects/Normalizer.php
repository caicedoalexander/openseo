<?php
/**
 * Normalizes a request URI into a comparable path.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure path normalizer (no WordPress). The home subdirectory is injected so the
 * class stays testable in isolation.
 */
final class Normalizer {

	/**
	 * Constructor.
	 *
	 * @param string $home_path Path component of home_url() (e.g. '/wp'), or ''.
	 */
	public function __construct( private readonly string $home_path = '' ) {}

	/**
	 * Normalize a raw request URI to a comparable path.
	 *
	 * @param string $request_uri Raw REQUEST_URI.
	 */
	public function normalize( string $request_uri ): string {
		// Strip query string and fragment by position (not strtok, which skips leading delimiters).
		$cut  = strcspn( $request_uri, '?#' );
		$path = substr( $request_uri, 0, $cut );
		$path = rawurldecode( $path );

		// Remove the home subdirectory prefix on subdir installs (respect segment boundary).
		if ( '' !== $this->home_path
			&& ( $path === $this->home_path || str_starts_with( $path, $this->home_path . '/' ) ) ) {
			$path = substr( $path, strlen( $this->home_path ) );
		}

		// Exactly one leading slash; no trailing slash except for root.
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $path;
	}
}
