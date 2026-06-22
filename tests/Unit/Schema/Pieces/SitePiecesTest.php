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
		$this->assertSame( 'https://example.com/#organizationLogo', $data['logo']['@id'] );
		$this->assertSame( 'https://example.com/#organizationLogo', $data['image']['@id'] );
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

	public function test_website_uses_configured_name_and_alternate(): void {
		$piece = new WebSite(
			$this->options(
				array(
					'schema_site_type'             => 'Organization',
					'local_website_name'           => 'Acme Site',
					'local_website_alternate_name' => 'Acme',
				)
			)
		);

		$data = $piece->data();
		$this->assertSame( 'Acme Site', $data['name'] );
		$this->assertSame( 'Acme', $data['alternateName'] );
	}

	public function test_website_name_falls_back_to_bloginfo_and_omits_alternate(): void {
		$data = ( new WebSite( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->data();

		$this->assertSame( 'My Site', $data['name'] );
		$this->assertArrayNotHasKey( 'alternateName', $data );
	}

	public function test_website_and_organization_names_do_not_cross(): void {
		$stored = array(
			'schema_site_type'   => 'Organization',
			'local_website_name' => 'A',
			'schema_site_name'   => 'B',
		);

		$this->assertSame( 'A', ( new WebSite( $this->options( $stored ) ) )->data()['name'] );
		$this->assertSame( 'B', ( new Organization( $this->options( $stored ) ) )->data()['name'] );
	}

	public function test_organization_emits_email_and_url_override(): void {
		$org = new Organization(
			$this->options(
				array(
					'schema_site_type' => 'Organization',
					'local_url'        => 'https://brand.example',
					'local_email'      => 'hi@example.com',
				)
			)
		);

		$data = $org->data();
		$this->assertSame( 'https://brand.example', $data['url'] );
		$this->assertSame( 'hi@example.com', $data['email'] );
	}

	public function test_organization_url_falls_back_and_omits_email(): void {
		$data = ( new Organization( $this->options( array( 'schema_site_type' => 'Organization' ) ) ) )->data();

		$this->assertSame( 'https://example.com/', $data['url'] );
		$this->assertArrayNotHasKey( 'email', $data );
	}

	public function test_person_emits_email_and_url_override(): void {
		$person = new Person(
			$this->options(
				array(
					'schema_site_type' => 'Person',
					'local_url'        => 'https://me.example',
					'local_email'      => 'me@example.com',
				)
			)
		);

		$data = $person->data();
		$this->assertSame( 'https://me.example', $data['url'] );
		$this->assertSame( 'me@example.com', $data['email'] );
	}
}
