<?php
/**
 * Assembles and prints the JSON-LD @graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

use OpenSEO\Contracts\Hookable;

/**
 * Collects every applicable Piece into one connected @graph and prints it as a
 * single ld+json script. Core/theme markup is untouched; this only adds a script.
 */
final class Graph implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param Piece[] $pieces Ordered schema pieces.
	 */
	public function __construct( private readonly array $pieces ) {}

	/**
	 * Print the graph late in wp_head, after the meta presenters.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_graph' ), 10 );
	}

	/**
	 * Build the @graph payload from the pieces that apply to this request.
	 *
	 * @return array{ '@context': string, '@graph': array<int, array<string, mixed>> }
	 */
	public function build(): array {
		$nodes = array();

		foreach ( $this->pieces as $piece ) {
			if ( $piece->is_needed() ) {
				$nodes[] = $piece->data();
			}
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);
	}

	/**
	 * Echo the graph as an ld+json script.
	 */
	public function print_graph(): void {
		$graph = $this->build();

		if ( empty( $graph['@graph'] ) ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > so a value containing </script> cannot
		// break out of the script element; the JSON itself needs no further esc.
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );

		// wp_json_encode() returns false on failure; never print "false".
		if ( false === $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG produces script-safe output.
	}
}
