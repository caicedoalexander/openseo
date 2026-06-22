<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Schema\LocalChoices;
use PHPUnit\Framework\TestCase;

final class LocalChoicesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_business_type_values_are_pure_and_include_localbusiness(): void {
		$values = LocalChoices::business_type_values();
		$this->assertContains( 'LocalBusiness', $values );
		$this->assertContains( 'Restaurant', $values );
	}

	public function test_choices_have_value_and_label(): void {
		$first = LocalChoices::business_types()[0];
		$this->assertArrayHasKey( 'value', $first );
		$this->assertArrayHasKey( 'label', $first );
	}

	public function test_phone_types_use_google_contact_types(): void {
		$this->assertContains( 'customer service', LocalChoices::phone_type_values() );
		$this->assertNotContains( 'customer support', LocalChoices::phone_type_values() );
	}

	public function test_days_are_seven_english_names(): void {
		$this->assertSame(
			array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			LocalChoices::day_values()
		);
	}

	public function test_additional_info_values_include_legal_and_employees(): void {
		$values = LocalChoices::additional_info_values();
		$this->assertContains( 'legalName', $values );
		$this->assertContains( 'numberOfEmployees', $values );
	}
}
