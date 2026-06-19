<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema\Pieces;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\Pieces\Organization;
use OpenSEO\Schema\Pieces\Person;
use OpenSEO\Schema\Pieces\WebSite;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SitePiecesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'My Site' : 'A tagline'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function options( array $stored ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Options();
	}

	public function test_website_always_needed_and_links_to_identity(): void {
		$piece = new WebSite( $this->options( array( 'schema_site_type' => 'Organization' ) ) );

		$this->assertTrue( $piece->is_needed() );

		$data = $piece->data();
		$this->assertSame( 'WebSite', $data['@type'] );
		$this->assertSame( 'https://example.com/#website', $data['@id'] );
		$this->assertSame( 'https://example.com/#organization', $data['publisher']['@id'] );
		$this->assertSame( 'SearchAction', $data['potentialAction']['@type'] );
	}

	public function test_website_publisher_points_to_person_when_chosen(): void {
		$piece = new WebSite( $this->options( array( 'schema_site_type' => 'Person' ) ) );

		$this->assertSame( 'https://example.com/#person', $piece->data()['publisher']['@id'] );
	}

	public function test_organization_needed_only_for_organization_type(): void {
		$org = new Organization( $this->options( array( 'schema_site_type' => 'Organization' ) ) );
		$this->assertTrue( $org->is_needed() );

		$off = new Organization( $this->options( array( 'schema_site_type' => 'Person' ) ) );
		$this->assertFalse( $off->is_needed() );

		$data = $org->data();
		$this->assertSame( 'Organization', $data['@type'] );
		$this->assertSame( 'https://example.com/#organization', $data['@id'] );
		$this->assertSame( 'My Site', $data['name'] );
	}

	public function test_organization_uses_custom_name_and_logo(): void {
		$org = new Organization(
			$this->options(
				array(
					'schema_site_type' => 'Organization',
					'schema_site_name' => 'Acme Inc',
					'schema_logo'      => 'https://example.com/logo.png',
				)
			)
		);

		$data = $org->data();
		$this->assertSame( 'Acme Inc', $data['name'] );
		$this->assertSame( 'ImageObject', $data['logo']['@type'] );
		$this->assertSame( 'https://example.com/logo.png', $data['logo']['url'] );
	}

	public function test_person_needed_only_for_person_type(): void {
		$person = new Person( $this->options( array( 'schema_site_type' => 'Person' ) ) );
		$this->assertTrue( $person->is_needed() );
		$this->assertFalse(
			( new Person( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->is_needed()
		);

		$data = $person->data();
		$this->assertSame( 'Person', $data['@type'] );
		$this->assertSame( 'https://example.com/#person', $data['@id'] );
	}
}
