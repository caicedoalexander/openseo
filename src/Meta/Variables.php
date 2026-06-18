<?php
/**
 * Replaces title/description template variables.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * Turns a template like "%title% %sep% %sitename%" into a finished string.
 */
final class Variables {

	/**
	 * Initializes the Variables resolver with settings.
	 *
	 * @param Options $options Settings accessor (provides the separator).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Replace every supported token in the template.
	 *
	 * @param string $template Template containing %tokens%.
	 * @param int    $post_id  Post context for post-specific tokens (0 = none).
	 */
	public function replace( string $template, int $post_id = 0 ): string {
		$replacements = array(
			'%sitename%'    => (string) get_bloginfo( 'name' ),
			'%tagline%'     => (string) get_bloginfo( 'description' ),
			'%sep%'         => (string) $this->options->get( 'title_separator' ),
			'%currentyear%' => gmdate( 'Y' ),
			'%title%'       => $post_id > 0 ? (string) get_the_title( $post_id ) : '',
			'%excerpt%'     => $post_id > 0 ? wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) : '',
		);

		$output = strtr( $template, $replacements );

		// Collapse whitespace left by empty tokens.
		$output = trim( (string) preg_replace( '/\s+/', ' ', $output ) );

		// Strip leading/trailing separators left dangling by empty tokens.
		// Treat the separator as a whole string (it may be multi-character),
		// not as a character set the way trim()'s charlist would.
		$separator = trim( (string) $this->options->get( 'title_separator' ) );

		if ( '' !== $separator ) {
			$quoted = preg_quote( $separator, '/' );
			$output = (string) preg_replace(
				'/^(?:' . $quoted . '\s*)+|(?:\s*' . $quoted . ')+$/',
				'',
				$output
			);
		}

		return trim( $output );
	}
}
