<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\LocalBusiness;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class LocalBusinessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build( array $stored ): array {
		Functions\when( 'get_option' )->justReturn( $stored );
		return ( new LocalBusiness() )->build( new Options() );
	}

	public function test_empty_options_build_nothing(): void {
		$this->assertSame( array(), $this->build( array() ) );
	}

	public function test_local_business_emits_full_props(): void {
		$data = $this->build(
			array(
				'local_business_type'  => 'Restaurant',
				'local_phone'          => '+1-555-0100',
				'local_description'    => 'Best food',
				'local_price_range'    => '$$',
				'local_geo'            => '40.7128,-74.006',
				'local_address'        => array( 'street' => 'Main St', 'locality' => 'Town', 'region' => '', 'postal_code' => '10001', 'country' => 'US' ),
				'local_opening_hours'  => array( array( 'day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00' ) ),
				'local_phone_numbers'  => array( array( 'type' => 'sales', 'number' => '+1-555-0199' ) ),
				'local_additional_info' => array(
					array( 'type' => 'legalName', 'value' => 'Acme Inc' ),
					array( 'type' => 'numberOfEmployees', 'value' => '12' ),
				),
			)
		);

		$this->assertSame( '+1-555-0100', $data['telephone'] );
		$this->assertSame( 'Best food', $data['description'] );
		$this->assertSame( '$$', $data['priceRange'] );
		$this->assertSame( 'PostalAddress', $data['address']['@type'] );
		$this->assertSame( 'Main St', $data['address']['streetAddress'] );
		$this->assertSame( 'US', $data['address']['addressCountry'] );
		$this->assertSame( 'GeoCoordinates', $data['geo']['@type'] );
		$this->assertSame( 40.7128, $data['geo']['latitude'] );
		$this->assertSame( 'https://schema.org/Monday', $data['openingHoursSpecification'][0]['dayOfWeek'] );
		$this->assertSame( '+1-555-0199', $data['contactPoint'][0]['telephone'] );
		$this->assertSame( 'sales', $data['contactPoint'][0]['contactType'] );
		$this->assertSame( 'Acme Inc', $data['legalName'] );
		$this->assertSame( array( '@type' => 'QuantitativeValue', 'value' => '12' ), $data['numberOfEmployees'] );
	}

	public function test_without_business_type_omits_local_only_props(): void {
		$data = $this->build(
			array(
				'local_business_type'   => '',
				'local_price_range'     => '$$',
				'local_geo'             => '40.7128,-74.006',
				'local_opening_hours'   => array( array( 'day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00' ) ),
				'local_description'     => 'About us',
				'local_phone'           => '+1-555',
				'local_address'         => array( 'street' => 'Main St', 'locality' => '', 'region' => '', 'postal_code' => '', 'country' => '' ),
				'local_phone_numbers'   => array( array( 'type' => 'sales', 'number' => '+1-555-0199' ) ),
				'local_additional_info' => array( array( 'type' => 'numberOfEmployees', 'value' => '12' ) ),
			)
		);

		// LocalBusiness-only props are gated out (H2).
		$this->assertArrayNotHasKey( 'priceRange', $data );
		$this->assertArrayNotHasKey( 'geo', $data );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $data );

		// Organization-valid props still emit even without a business type (M-2).
		$this->assertSame( 'About us', $data['description'] );
		$this->assertSame( '+1-555', $data['telephone'] );
		$this->assertSame( 'Main St', $data['address']['streetAddress'] );
		$this->assertSame( '+1-555-0199', $data['contactPoint'][0]['telephone'] );
		$this->assertSame( array( '@type' => 'QuantitativeValue', 'value' => '12' ), $data['numberOfEmployees'] );
	}
}
