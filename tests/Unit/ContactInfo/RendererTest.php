<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\ContactInfo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\ContactInfo\Renderer;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( array $stored, array $sections = array(), string $class = '' ): string {
		Functions\when( 'get_option' )->justReturn( $stored );
		return ( new Renderer( new Options() ) )->render( $sections, $class );
	}

	public function test_empty_options_render_nothing(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		$this->assertSame( '', $this->render( array() ) );
	}

	public function test_name_links_to_url(): void {
		$html = $this->render(
			array( 'schema_site_name' => 'Acme', 'local_url' => 'https://acme.test' ),
			array( 'name' )
		);
		$this->assertStringContainsString( '<div class="openseo-contact-info">', $html );
		$this->assertStringContainsString( '<a href="https://acme.test">Acme</a>', $html );
	}

	public function test_email_is_a_mailto_link(): void {
		$html = $this->render( array( 'local_email' => 'hi@acme.test' ), array( 'email' ) );
		$this->assertStringContainsString( 'href="mailto:hi@acme.test"', $html );
	}

	public function test_phone_primary_and_additional_with_type_label(): void {
		$html = $this->render(
			array(
				'local_phone'         => '+1 (555) 010-0',
				'local_phone_numbers' => array( array( 'type' => 'sales', 'number' => '+1-555-0199' ) ),
			),
			array( 'phone' )
		);
		$this->assertStringContainsString( 'href="tel:+15550100"', $html ); // cleaned
		$this->assertStringContainsString( '+1 (555) 010-0', $html );       // visible original
		$this->assertStringContainsString( 'Sales', $html );                 // type label
		$this->assertStringContainsString( 'href="tel:+15550199"', $html );
	}

	public function test_address_joins_non_empty_parts(): void {
		$html = $this->render(
			array( 'local_address' => array( 'street' => 'Main St', 'locality' => 'NYC', 'region' => '', 'postal_code' => '10001', 'country' => 'US' ) ),
			array( 'address' )
		);
		$this->assertStringContainsString( '<address class="openseo-contact-info__address">Main St, NYC, 10001, US</address>', $html );
	}

	public function test_hours_use_day_label_and_literal_endash(): void {
		$html = $this->render(
			array( 'local_opening_hours' => array( array( 'day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00' ) ) ),
			array( 'hours' )
		);
		$this->assertStringContainsString( '<li>Monday: 09:00&#8211;17:00</li>', $html );
	}

	public function test_map_from_geo_then_address(): void {
		// esc_url is mocked as returnArg here, so the literal `&` survives. In
		// real WP, esc_url encodes `&` to `&#038;` in the attribute (the browser
		// decodes it back) — that is correct output, not a bug.
		$geo = $this->render( array( 'local_geo' => '40.7128,-74.006' ), array( 'map' ) );
		$this->assertStringContainsString( 'maps/search/?api=1&query=40.7128%2C-74.006', $geo );
		$this->assertStringContainsString( 'rel="noopener"', $geo );

		$addr = $this->render(
			array( 'local_address' => array( 'street' => 'Main St', 'locality' => 'NYC', 'region' => '', 'postal_code' => '', 'country' => '' ) ),
			array( 'map' )
		);
		$this->assertStringContainsString( 'query=Main%20St%2C%20NYC', $addr );
	}

	public function test_show_filters_sections(): void {
		$html = $this->render(
			array( 'schema_site_name' => 'Acme', 'local_email' => 'hi@acme.test', 'local_description' => 'About' ),
			array( 'name', 'email' )
		);
		$this->assertStringContainsString( 'Acme', $html );
		$this->assertStringContainsString( 'mailto:hi@acme.test', $html );
		$this->assertStringNotContainsString( 'About', $html ); // description filtered out
	}

	public function test_extra_class_and_escaping(): void {
		$html = $this->render( array( 'schema_site_name' => '<b>"X"</b>' ), array( 'name' ), 'my-card' );
		$this->assertStringContainsString( 'class="openseo-contact-info my-card"', $html );
		$this->assertStringContainsString( '&lt;b&gt;&quot;X&quot;&lt;/b&gt;', $html );
	}
}
