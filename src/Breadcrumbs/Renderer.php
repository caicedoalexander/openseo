<?php
/**
 * Renders the breadcrumb trail as escaped HTML.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Breadcrumbs;

use OpenSEO\Settings\Options;

/**
 * Turns trail items into an accessible <nav><ol> structure. Every value is
 * escaped here, so callers can echo the returned string directly.
 */
final class Renderer {

	/**
	 * Constructor.
	 *
	 * @param Options $options Settings accessor (provides the default separator).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Render the trail.
	 *
	 * @param array<int, array{name: string, url: string}> $items Trail crumbs.
	 * @param array<string, mixed>                         $args  Display options.
	 * @return string Fully-escaped HTML string, or empty string when $items is empty.
	 */
	public function render( array $items, array $args = array() ): string {
		$args = array_merge(
			array(
				'separator'  => (string) $this->options->get( 'breadcrumb_separator' ),
				'show_home'  => true,
				'text_align' => '',
			),
			$args
		);

		if ( ! (bool) $args['show_home'] ) {
			$items = array_values(
				array_filter(
					$items,
					static fn( $item ) => __( 'Home', 'openseo' ) !== $item['name']
				)
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		$style = '' !== $args['text_align']
			? ' style="text-align:' . esc_attr( (string) $args['text_align'] ) . '"'
			: '';

		$html  = '<nav class="openseo-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'openseo' ) . '"' . $style . '>';
		$html .= '<ol class="openseo-breadcrumbs__list">';

		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$html .= '<li class="openseo-breadcrumbs__item">';

			if ( $index !== $last && '' !== $item['url'] ) {
				$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			} else {
				$html .= '<span aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			}

			$html .= '</li>';

			if ( $index !== $last ) {
				$html .= '<li class="openseo-breadcrumbs__sep" aria-hidden="true">' . esc_html( (string) $args['separator'] ) . '</li>';
			}
		}

		$html .= '</ol></nav>';

		return $html;
	}
}
