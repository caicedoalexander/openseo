<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\LocalSeoSanitizer;
use PHPUnit\Framework\TestCase;

final class LocalSeoSanitizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_absent_keys_are_not_returned(): void {
		$clean = LocalSeoSanitizer::sanitize( array( 'unrelated' => 'x' ), array() );
		$this->assertSame( array(), $clean );
	}

	public function test_business_type_whitelist(): void {
		$ok  = LocalSeoSanitizer::sanitize( array( 'local_business_type' => 'Restaurant' ), array() );
		$bad = LocalSeoSanitizer::sanitize( array( 'local_business_type' => 'Bogus' ), array() );
		$this->assertSame( 'Restaurant', $ok['local_business_type'] );
		$this->assertSame( '', $bad['local_business_type'] );
	}

	public function test_geo_parses_valid_and_rejects_invalid(): void {
		$ok  = LocalSeoSanitizer::sanitize( array( 'local_geo' => ' 40.7128, -74.006 ' ), array() );
		$bad = LocalSeoSanitizer::sanitize( array( 'local_geo' => '200,abc' ), array() );
		$this->assertSame( '40.7128,-74.006', $ok['local_geo'] );
		$this->assertSame( '', $bad['local_geo'] );
		$range = LocalSeoSanitizer::sanitize( array( 'local_geo' => '200,0' ), array() );
		$this->assertSame( '', $range['local_geo'] );
	}

	public function test_address_merges_over_current(): void {
		$clean = LocalSeoSanitizer::sanitize(
			array( 'local_address' => array( 'street' => 'Main St', 'bogus' => 'x' ) ),
			array( 'local_address' => array( 'locality' => 'Town' ) )
		);
		$this->assertSame( 'Main St', $clean['local_address']['street'] );
		$this->assertSame( 'Town', $clean['local_address']['locality'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['local_address'] );
	}

	public function test_opening_hours_drops_invalid_rows(): void {
		$clean = LocalSeoSanitizer::sanitize(
			array(
				'local_opening_hours' => array(
					array( 'day' => 'Monday', 'opens' => '09:00', 'closes' => '17:00' ),
					array( 'day' => 'Funday', 'opens' => '09:00', 'closes' => '17:00' ),
					array( 'day' => 'Tuesday', 'opens' => '25:00', 'closes' => '17:00' ),
					array( 'day' => 'Wednesday', 'opens' => '09:00', 'closes' => '' ),
				),
			),
			array()
		);
		$this->assertCount( 1, $clean['local_opening_hours'] );
		$this->assertSame( 'Monday', $clean['local_opening_hours'][0]['day'] );
	}

	public function test_phone_numbers_and_additional_info_rows(): void {
		$clean = LocalSeoSanitizer::sanitize(
			array(
				'local_phone_numbers'   => array(
					array( 'type' => 'sales', 'number' => '+1-555' ),
					array( 'type' => 'sales', 'number' => '' ),
				),
				'local_additional_info' => array(
					array( 'type' => 'legalName', 'value' => 'Acme Inc' ),
					array( 'type' => 'bogus', 'value' => 'x' ),
					array( 'type' => 'vatID', 'value' => '' ),
				),
			),
			array()
		);
		$this->assertCount( 1, $clean['local_phone_numbers'] );
		$this->assertSame( '+1-555', $clean['local_phone_numbers'][0]['number'] );
		$this->assertCount( 1, $clean['local_additional_info'] );
		$this->assertSame( 'legalName', $clean['local_additional_info'][0]['type'] );
	}
}
