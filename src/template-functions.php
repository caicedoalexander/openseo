<?php
/**
 * Public template functions for theme authors.
 *
 * Loaded via Composer's autoload.files so the global is always available.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

use OpenSEO\Breadcrumbs\Renderer;
use OpenSEO\Breadcrumbs\Trail;
use OpenSEO\Settings\Options;

if ( ! function_exists( 'openseo_breadcrumbs' ) ) {
	/**
	 * Echo the OpenSEO breadcrumb trail.
	 *
	 * @param array<string, mixed> $args Optional display overrides
	 *                                   (separator, show_home, text_align).
	 */
	function openseo_breadcrumbs( array $args = array() ): void {
		$renderer = new Renderer( new Options() );

		// Renderer escapes every value it outputs.
		echo $renderer->render( ( new Trail() )->items(), $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns fully-escaped HTML.
	}
}
