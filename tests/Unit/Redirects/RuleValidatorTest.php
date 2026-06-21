<?php
/**
 * Unit tests for the redirect rule validator (extracted from RedirectsPage).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\RedirectLookup;
use OpenSEO\Redirects\RuleValidator;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class RuleValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// Real WP esc_url_raw returns '' for a bare relative path and echoes absolute URLs.
		Functions\when( 'esc_url_raw' )->alias(
			static fn( $url ) => str_contains( (string) $url, '://' ) ? (string) $url : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function validator( ?Redirect $back = null ): RuleValidator {
		$lookup = new class( $back ) implements RedirectLookup {
			public function __construct( private ?Redirect $back ) {}
			public function find_active_by_source( string $path ): ?Redirect {
				return $this->back;
			}
		};

		return new RuleValidator( $lookup );
	}

	public function test_normalizes_exact_source_and_returns_clean_data(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/old/',
				'target'      => 'https://example.com/new',
				'status_code' => 301,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '/old', $clean['source_path'] ); // trailing slash stripped by Normalizer.
		$this->assertSame( 'https://example.com/new', $clean['target'] );
		$this->assertSame( 301, $clean['status_code'] );
		$this->assertFalse( $clean['is_regex'] );
		$this->assertTrue( $clean['enabled'] );
	}

	public function test_accepts_root_relative_target(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '/new', $clean['target'] );
	}

	public function test_invalid_status_returns_error(): void {
		$result = $this->validator()->validate(
			array(
				'source_path' => '/x',
				'target'      => 'https://e.com',
				'status_code' => 999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid', $result->get_error_code() );
	}

	public function test_410_clears_target(): void {
		$clean = $this->validator()->validate(
			array(
				'source_path' => '/gone',
				'target'      => '',
				'status_code' => 410,
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( '', $clean['target'] );
		$this->assertSame( 410, $clean['status_code'] );
	}

	public function test_invalid_regex_returns_error(): void {
		$result = $this->validator()->validate(
			array(
				'source_path' => '(',           // unbalanced group → invalid pattern.
				'target'      => 'https://e.com',
				'status_code' => 301,
				'is_regex'    => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid_regex', $result->get_error_code() );
	}

	public function test_detects_direct_cycle_for_exact_rules(): void {
		// Existing active rule: /new -> /old. New rule /old -> /new closes the loop.
		$back   = new Redirect( 7, '/new', '/old', 301, false, true );
		$result = $this->validator( $back )->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_cycle', $result->get_error_code() );
	}

	public function test_editing_same_row_is_not_a_cycle(): void {
		$back   = new Redirect( 7, '/new', '/old', 301, false, true );
		$result = $this->validator( $back )->validate(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
			),
			7 // editing row 7 itself → excluded from the lookup.
		);

		$this->assertIsArray( $result );
	}
}
