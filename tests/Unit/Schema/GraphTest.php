<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
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
}
