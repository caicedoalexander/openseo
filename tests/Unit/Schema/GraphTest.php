<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\Graph;
use OpenSEO\Schema\Piece;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function piece( bool $needed, array $data ): Piece {
		return new class( $needed, $data ) implements Piece {
			/** @param array<string,mixed> $data */
			public function __construct( private bool $needed, private array $data ) {}
			public function is_needed(): bool {
				return $this->needed;
			}
			public function id(): string {
				return $this->data['@id'] ?? '';
			}
			public function data(): array {
				return $this->data;
			}
		};
	}

	public function test_build_includes_only_needed_pieces(): void {
		$graph = new Graph(
			array(
				$this->piece( true, array( '@type' => 'WebSite', '@id' => 'a' ) ),
				$this->piece( false, array( '@type' => 'Article', '@id' => 'b' ) ),
				$this->piece( true, array( '@type' => 'WebPage', '@id' => 'c' ) ),
			)
		);

		$built = $graph->build();

		$this->assertSame( 'https://schema.org', $built['@context'] );
		$this->assertCount( 2, $built['@graph'] );
		$this->assertSame( array( 'WebSite', 'WebPage' ), array_column( $built['@graph'], '@type' ) );
	}

	public function test_print_graph_hex_escapes_a_script_close_tag_in_a_value(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0 ) => json_encode( $data, $flags )
		);

		$graph = new Graph(
			array(
				$this->piece(
					true,
					array( '@type' => 'WebPage', '@id' => 'a', 'name' => 'Hi </script><script>alert(1)</script>' ),
				),
			)
		);

		ob_start();
		$graph->print_graph();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		// The dangerous markup from the value must be hex-escaped, never emitted raw.
		$this->assertStringNotContainsString( '</script><script>', $output );
		// JSON_HEX_TAG encodes '<' as the 6-char escape <; build the needle
		// without writing the escape literally so it is not misread as Unicode.
		$hex_lt = '\\' . 'u003C';
		$this->assertStringContainsString( $hex_lt, $output );
	}

	public function test_print_graph_outputs_nothing_when_no_piece_is_needed(): void {
		$graph = new Graph(
			array( $this->piece( false, array( '@type' => 'Article', '@id' => 'a' ) ) )
		);

		ob_start();
		$graph->print_graph();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}
}
