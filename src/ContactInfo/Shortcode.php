<?php
/**
 * Registers the [openseo_contact_info] shortcode.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\ContactInfo;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Front-end shortcode that renders the contact card. The callback RETURNS the
 * Renderer's (already-escaped) HTML — shortcodes return, they do not echo.
 */
final class Shortcode implements Hookable {

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( 'openseo_contact_info', array( $this, 'render' ) );
	}

	/**
	 * Render callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes ('' when none).
	 * @return string Escaped HTML, or '' when nothing is configured.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'show'  => '',
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'openseo_contact_info'
		);

		return ( new Renderer( new Options() ) )->render(
			self::parse_sections( (string) $atts['show'] ),
			(string) $atts['class']
		);
	}

	/**
	 * Parse the `show` attribute into a list of section keys ('' = all).
	 *
	 * @param string $show Comma-separated section keys.
	 * @return array<int, string>
	 */
	public static function parse_sections( string $show ): array {
		if ( '' === $show ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $show ) ) ) );
	}
}
