# SEO Local 2b-i (LocalBusiness campos + schema) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configurar datos de negocio local (dirección, geo, horarios, teléfonos, tipo, price range, descripción, datos legales) en la pestaña SEO Local y emitir el JSON-LD `LocalBusiness` correcto en el `@graph`, sobre la identidad de 2a.

**Architecture:** Enums en `Schema\LocalChoices` (fuente única, pasada a JS por bootstrap). 9 keys nuevas en `Options`, saneadas por un `Settings\LocalSeoSanitizer` extraído. Un helper puro `Schema\LocalBusiness::build(Options): array` traduce las opciones a props JSON-LD; `Organization::data()` mergea (con `@type` = business type) y `Person::data()` añade `telephone`. UI: componente reutilizable `RepeatableGroup` (+ helper puro `repeatable.js`) y `LocalBusinessFields` montado condicionalmente en `SeoLocalPanel`.

**Tech Stack:** PHP 8.1 (PSR-4 `OpenSEO\` → `src/`), PHPUnit + Brain Monkey, PHPStan nivel 6, `@wordpress/scripts` (React/JS + Jest), WordPress 7.0 (Schema JSON-LD), wp-env.

## Global Constraints

- WP 7.0+, PHP 8.1+. `declare(strict_types=1);` en PHP. Text domain `openseo`; prefijos `openseo`/`OpenSEO`/`OPENSEO` (PHPCS). PSR-4 file naming.
- 9 keys nuevas (defaults): `local_business_type` (''), `local_description` (''), `local_price_range` (''), `local_geo` (''), `local_phone` (''), `local_address` (grupo street/locality/region/postal_code/country, todas ''), `local_opening_hours` (array), `local_phone_numbers` (array), `local_additional_info` (array).
- `local_phone` es el **teléfono principal de ambos tipos** → `telephone` en Organization Y Person (H1). Los repetibles `local_phone_numbers` → `contactPoint[]` adicionales.
- Sanitización extraída a `Settings\LocalSeoSanitizer::sanitize(array $input, array $current): array`. **Contrato (M3):** solo procesa las `local_*` **presentes** en `$input`; las ausentes no se tocan. `Options::sanitize` la invoca solo si `array_intersect_key($input, $local_keys)` no está vacío, y mergea su retorno sobre `$clean` (que parte de `all()`).
- `local_geo`: parse `"lat,lng"` (dos floats, `lat∈[-90,90]`, `lng∈[-180,180]`) → normalizado o ''. `opening_hours` filas `{day, opens, closes}`: day en whitelist Monday..Sunday, opens/closes regex `^([01]\d|2[0-3]):[0-5]\d$`, requiere ambos o se descarta. `phone_numbers` `{type, number}`: type whitelist (o ''), number text, drop si number vacío. `additional_info` `{type, value}`: type whitelist (drop si desconocido), value text, drop si vacío.
- Schema: `@type` = `local_business_type` (revalidado) si no vacío, si no `Organization`. **Siempre que haya valor:** `telephone`, `description`, `address` (PostalAddress inline), `contactPoint[]`, props de additional info directas (`numberOfEmployees` → `{ @type:'QuantitativeValue', value }`, M1). **Solo si business type set (H2):** `geo` (GeoCoordinates), `openingHoursSpecification[]` (dayOfWeek `https://schema.org/<Day>`), `priceRange`. Person → `telephone` de `local_phone`.
- Con defaults vacíos, `LocalBusiness::build` → `[]`; nodos Organization/Person idénticos a 2a (sin regresión).
- `contactType` canónicos de Google (M2): `customer service`, `technical support`, `billing support`, `bill payment`, `sales`, `reservations`, `credit card support`, `emergency`, `package tracking`.
- `RepeatableGroup`: `<fieldset>`/`<legend>`; índice de fila como `key` es decisión consciente (controles controlados por `value`, sin estado local por fila — M5). i18n vía `__`.
- PHPStan nivel 6 sin baseline: leer `Options::get()` (`mixed`) con guards `is_array`/casts `(string)`/`(float)` + docblocks `array{...}`.
- Seguridad: sanitizar en entrada, escapar en salida (el `@graph` ya usa `wp_json_encode(JSON_HEX_TAG)`). Sin `dangerouslySetInnerHTML`.
- Gates por commit que toque su capa: `composer lint`, `composer analyze` (`--memory-limit=1G`), `composer test:unit`; JS `npm run lint:js`, `npm run lint:css` (si se toca SCSS), `npm run test:js`, `npm run build`.
- Conventional commits, SIN atribución.

---

## File Structure

**PHP (nuevos):** `src/Schema/LocalChoices.php`, `src/Settings/LocalSeoSanitizer.php`, `src/Schema/LocalBusiness.php`.
**PHP (modificados):** `src/Settings/Options.php` (defaults + delegación), `src/Schema/Pieces/Organization.php`, `src/Schema/Pieces/Person.php`, `src/Admin/Assets.php`.
**JS (nuevos):** `assets/src/admin/repeatable.js` (+ `.test.js`), `assets/src/admin/components/RepeatableGroup.js`, `assets/src/admin/components/LocalBusinessFields.js`.
**JS (modificados):** `assets/src/admin/views/Titles.js` (SeoLocalPanel), `assets/src/admin/style.scss` (estilos repeatable).
**Tests (nuevos):** `tests/Unit/Schema/LocalChoicesTest.php`, `tests/Unit/Settings/LocalSeoSanitizerTest.php`, `tests/Unit/Schema/LocalBusinessTest.php`, `assets/src/admin/repeatable.test.js`.
**Tests (modificados):** `tests/Unit/OptionsTest.php`, `tests/Unit/Schema/Pieces/SitePiecesTest.php`.

---

## Task 1: `Schema\LocalChoices` (enums fuente única)

**Files:**
- Create: `src/Schema/LocalChoices.php`
- Test: `tests/Unit/Schema/LocalChoicesTest.php`

**Interfaces:**
- Produces: `LocalChoices::business_types()/phone_types()/additional_info_types()/days()` → `array<int,array{value:string,label:string}>` (i18n); `business_type_values()/phone_type_values()/additional_info_values()/day_values()` → `array<int,string>` (puros, sin `__`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LocalChoicesTest`
Expected: FAIL — `Class "OpenSEO\Schema\LocalChoices" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Curated enumerations for Local SEO (business types, contact types, etc.).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

/**
 * Single source of truth for the Local SEO selectable values. `*_values()` are
 * pure (used by the sanitizer whitelists); the choice methods add i18n labels
 * (passed to the admin bundle via the bootstrap).
 */
final class LocalChoices {

	private const BUSINESS_TYPES = array(
		'LocalBusiness',
		'AccountingService',
		'Attorney',
		'AutomotiveBusiness',
		'AutoRepair',
		'Bakery',
		'BarOrPub',
		'BeautySalon',
		'CafeOrCoffeeShop',
		'ChildCare',
		'ClothingStore',
		'Dentist',
		'Electrician',
		'ElectronicsStore',
		'EntertainmentBusiness',
		'FinancialService',
		'FoodEstablishment',
		'GroceryStore',
		'HairSalon',
		'HardwareStore',
		'HealthAndBeautyBusiness',
		'HomeAndConstructionBusiness',
		'Hotel',
		'InsuranceAgency',
		'LegalService',
		'Locksmith',
		'LodgingBusiness',
		'MedicalBusiness',
		'Pharmacy',
		'Physician',
		'Plumber',
		'ProfessionalService',
		'RealEstateAgent',
		'Restaurant',
		'SportsActivityLocation',
		'Store',
		'TravelAgency',
	);

	private const PHONE_TYPES = array(
		'customer service',
		'technical support',
		'billing support',
		'bill payment',
		'sales',
		'reservations',
		'credit card support',
		'emergency',
		'package tracking',
	);

	private const ADDITIONAL_INFO_TYPES = array(
		'legalName',
		'foundingDate',
		'vatID',
		'taxID',
		'duns',
		'leiCode',
		'naics',
		'iso6523Code',
		'globalLocationNumber',
		'numberOfEmployees',
	);

	private const DAYS = array(
		'Monday',
		'Tuesday',
		'Wednesday',
		'Thursday',
		'Friday',
		'Saturday',
		'Sunday',
	);

	/**
	 * Business type values (pure; the @type of a LocalBusiness node).
	 *
	 * @return array<int, string>
	 */
	public static function business_type_values(): array {
		return self::BUSINESS_TYPES;
	}

	/**
	 * Contact type values (pure; ContactPoint.contactType).
	 *
	 * @return array<int, string>
	 */
	public static function phone_type_values(): array {
		return self::PHONE_TYPES;
	}

	/**
	 * Additional-info type values (pure; map to Organization properties).
	 *
	 * @return array<int, string>
	 */
	public static function additional_info_values(): array {
		return self::ADDITIONAL_INFO_TYPES;
	}

	/**
	 * Day-of-week values (pure).
	 *
	 * @return array<int, string>
	 */
	public static function day_values(): array {
		return self::DAYS;
	}

	/**
	 * Business type choices with i18n labels.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function business_types(): array {
		$labels = array(
			'LocalBusiness'               => __( 'Local business (generic)', 'openseo' ),
			'AccountingService'           => __( 'Accounting service', 'openseo' ),
			'Attorney'                    => __( 'Attorney', 'openseo' ),
			'AutomotiveBusiness'          => __( 'Automotive business', 'openseo' ),
			'AutoRepair'                  => __( 'Auto repair', 'openseo' ),
			'Bakery'                      => __( 'Bakery', 'openseo' ),
			'BarOrPub'                    => __( 'Bar or pub', 'openseo' ),
			'BeautySalon'                 => __( 'Beauty salon', 'openseo' ),
			'CafeOrCoffeeShop'            => __( 'Cafe or coffee shop', 'openseo' ),
			'ChildCare'                   => __( 'Child care', 'openseo' ),
			'ClothingStore'               => __( 'Clothing store', 'openseo' ),
			'Dentist'                     => __( 'Dentist', 'openseo' ),
			'Electrician'                 => __( 'Electrician', 'openseo' ),
			'ElectronicsStore'            => __( 'Electronics store', 'openseo' ),
			'EntertainmentBusiness'       => __( 'Entertainment business', 'openseo' ),
			'FinancialService'            => __( 'Financial service', 'openseo' ),
			'FoodEstablishment'           => __( 'Food establishment', 'openseo' ),
			'GroceryStore'                => __( 'Grocery store', 'openseo' ),
			'HairSalon'                   => __( 'Hair salon', 'openseo' ),
			'HardwareStore'               => __( 'Hardware store', 'openseo' ),
			'HealthAndBeautyBusiness'     => __( 'Health and beauty business', 'openseo' ),
			'HomeAndConstructionBusiness' => __( 'Home and construction business', 'openseo' ),
			'Hotel'                       => __( 'Hotel', 'openseo' ),
			'InsuranceAgency'             => __( 'Insurance agency', 'openseo' ),
			'LegalService'                => __( 'Legal service', 'openseo' ),
			'Locksmith'                   => __( 'Locksmith', 'openseo' ),
			'LodgingBusiness'             => __( 'Lodging business', 'openseo' ),
			'MedicalBusiness'             => __( 'Medical business', 'openseo' ),
			'Pharmacy'                    => __( 'Pharmacy', 'openseo' ),
			'Physician'                   => __( 'Physician', 'openseo' ),
			'Plumber'                     => __( 'Plumber', 'openseo' ),
			'ProfessionalService'         => __( 'Professional service', 'openseo' ),
			'RealEstateAgent'             => __( 'Real estate agent', 'openseo' ),
			'Restaurant'                  => __( 'Restaurant', 'openseo' ),
			'SportsActivityLocation'      => __( 'Sports activity location', 'openseo' ),
			'Store'                       => __( 'Store', 'openseo' ),
			'TravelAgency'                => __( 'Travel agency', 'openseo' ),
		);

		return self::to_choices( self::BUSINESS_TYPES, $labels );
	}

	/**
	 * Contact type choices with i18n labels.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function phone_types(): array {
		$labels = array(
			'customer service'    => __( 'Customer service', 'openseo' ),
			'technical support'   => __( 'Technical support', 'openseo' ),
			'billing support'     => __( 'Billing support', 'openseo' ),
			'bill payment'        => __( 'Bill payment', 'openseo' ),
			'sales'               => __( 'Sales', 'openseo' ),
			'reservations'        => __( 'Reservations', 'openseo' ),
			'credit card support' => __( 'Credit card support', 'openseo' ),
			'emergency'           => __( 'Emergency', 'openseo' ),
			'package tracking'    => __( 'Package tracking', 'openseo' ),
		);

		return self::to_choices( self::PHONE_TYPES, $labels );
	}

	/**
	 * Additional-info choices with i18n labels.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function additional_info_types(): array {
		$labels = array(
			'legalName'            => __( 'Legal name', 'openseo' ),
			'foundingDate'         => __( 'Founding date (YYYY or YYYY-MM-DD)', 'openseo' ),
			'vatID'                => __( 'VAT ID', 'openseo' ),
			'taxID'                => __( 'Tax ID', 'openseo' ),
			'duns'                 => __( 'DUNS', 'openseo' ),
			'leiCode'              => __( 'LEI code', 'openseo' ),
			'naics'                => __( 'NAICS code', 'openseo' ),
			'iso6523Code'          => __( 'ISO 6523 code', 'openseo' ),
			'globalLocationNumber' => __( 'Global location number', 'openseo' ),
			'numberOfEmployees'    => __( 'Number of employees', 'openseo' ),
		);

		return self::to_choices( self::ADDITIONAL_INFO_TYPES, $labels );
	}

	/**
	 * Day choices with i18n labels.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function days(): array {
		$labels = array(
			'Monday'    => __( 'Monday', 'openseo' ),
			'Tuesday'   => __( 'Tuesday', 'openseo' ),
			'Wednesday' => __( 'Wednesday', 'openseo' ),
			'Thursday'  => __( 'Thursday', 'openseo' ),
			'Friday'    => __( 'Friday', 'openseo' ),
			'Saturday'  => __( 'Saturday', 'openseo' ),
			'Sunday'    => __( 'Sunday', 'openseo' ),
		);

		return self::to_choices( self::DAYS, $labels );
	}

	/**
	 * Zip a value list with a label map into {value,label} choices.
	 *
	 * @param array<int, string>    $values Ordered values.
	 * @param array<string, string> $labels Value → label map.
	 * @return array<int, array{value:string,label:string}>
	 */
	private static function to_choices( array $values, array $labels ): array {
		return array_map(
			static fn( string $value ): array => array(
				'value' => $value,
				'label' => $labels[ $value ] ?? $value,
			),
			$values
		);
	}
}
```

- [ ] **Step 4: Run test + analysis**

Run: `vendor/bin/phpunit --filter LocalChoicesTest`
Expected: PASS (5 tests).

Run: `composer analyze`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Schema/LocalChoices.php tests/Unit/Schema/LocalChoicesTest.php
git commit -m "feat(schema): LocalChoices enums (business/phone/additional/days)"
```

---

## Task 2: `Settings\LocalSeoSanitizer`

**Files:**
- Create: `src/Settings/LocalSeoSanitizer.php`
- Test: `tests/Unit/Settings/LocalSeoSanitizerTest.php`

**Interfaces:**
- Consumes: `LocalChoices::*_values()` (Task 1).
- Produces: `LocalSeoSanitizer::sanitize(array $input, array $current): array` — returns ONLY the `local_*` keys present in `$input`, sanitized (geo parsed; address merged over `$current`; rows whitelisted/dropped).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LocalSeoSanitizerTest`
Expected: FAIL — `Class "OpenSEO\Settings\LocalSeoSanitizer" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Sanitizes the Local SEO (2b-i) settings keys.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

use OpenSEO\Schema\LocalChoices;

/**
 * Pure-ish sanitizer for the `local_*` LocalBusiness keys. Returns ONLY the keys
 * present in $input (partial-merge contract): Options::sanitize keeps absent keys
 * from its all() base.
 */
final class LocalSeoSanitizer {

	/**
	 * Sanitize the local keys present in $input.
	 *
	 * @param array<string, mixed> $input   Raw submission.
	 * @param array<string, mixed> $current Currently stored settings (for address merge).
	 * @return array<string, mixed> Sanitized subset (only keys present in $input).
	 */
	public static function sanitize( array $input, array $current ): array {
		$clean = array();

		if ( array_key_exists( 'local_description', $input ) ) {
			$clean['local_description'] = sanitize_textarea_field( wp_unslash( (string) $input['local_description'] ) );
		}

		foreach ( array( 'local_price_range', 'local_phone' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
			}
		}

		if ( array_key_exists( 'local_business_type', $input ) ) {
			$type                        = sanitize_text_field( wp_unslash( (string) $input['local_business_type'] ) );
			$clean['local_business_type'] = in_array( $type, LocalChoices::business_type_values(), true ) ? $type : '';
		}

		if ( array_key_exists( 'local_geo', $input ) ) {
			$clean['local_geo'] = self::parse_geo( (string) wp_unslash( (string) $input['local_geo'] ) );
		}

		if ( array_key_exists( 'local_address', $input ) ) {
			$clean['local_address'] = self::sanitize_address( $input['local_address'], $current['local_address'] ?? array() );
		}

		if ( array_key_exists( 'local_opening_hours', $input ) ) {
			$clean['local_opening_hours'] = self::sanitize_hours( $input['local_opening_hours'] );
		}

		if ( array_key_exists( 'local_phone_numbers', $input ) ) {
			$clean['local_phone_numbers'] = self::sanitize_phone_numbers( $input['local_phone_numbers'] );
		}

		if ( array_key_exists( 'local_additional_info', $input ) ) {
			$clean['local_additional_info'] = self::sanitize_additional_info( $input['local_additional_info'] );
		}

		return $clean;
	}

	/**
	 * Parse "lat,lng" → normalized or ''.
	 *
	 * @param string $raw Raw value.
	 */
	private static function parse_geo( string $raw ): string {
		$parts = explode( ',', $raw );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		$lat = filter_var( trim( $parts[0] ), FILTER_VALIDATE_FLOAT );
		$lng = filter_var( trim( $parts[1] ), FILTER_VALIDATE_FLOAT );
		if ( false === $lat || false === $lng || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			return '';
		}
		return $lat . ',' . $lng;
	}

	/**
	 * Sanitize the address group, merging present subkeys over current.
	 *
	 * @param mixed $input   Raw address.
	 * @param mixed $current Stored address.
	 * @return array{street:string,locality:string,region:string,postal_code:string,country:string}
	 */
	private static function sanitize_address( $input, $current ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = is_array( $current ) ? $current : array();
		$out     = array();
		foreach ( array( 'street', 'locality', 'region', 'postal_code', 'country' ) as $key ) {
			$out[ $key ] = array_key_exists( $key, $input )
				? sanitize_text_field( wp_unslash( (string) $input[ $key ] ) )
				: (string) ( $current[ $key ] ?? '' );
		}
		return $out;
	}

	/**
	 * @param mixed $rows Raw opening-hours rows.
	 * @return array<int, array{day:string,opens:string,closes:string}>
	 */
	private static function sanitize_hours( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$days = LocalChoices::day_values();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$day = (string) ( $row['day'] ?? '' );
			if ( ! in_array( $day, $days, true ) ) {
				continue;
			}
			$opens  = self::time_or_empty( (string) ( $row['opens'] ?? '' ) );
			$closes = self::time_or_empty( (string) ( $row['closes'] ?? '' ) );
			if ( '' === $opens || '' === $closes ) {
				continue;
			}
			$out[] = array(
				'day'    => $day,
				'opens'  => $opens,
				'closes' => $closes,
			);
		}
		return $out;
	}

	/**
	 * @param string $time Raw HH:MM.
	 */
	private static function time_or_empty( string $time ): string {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	/**
	 * @param mixed $rows Raw phone-number rows.
	 * @return array<int, array{type:string,number:string}>
	 */
	private static function sanitize_phone_numbers( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$types = LocalChoices::phone_type_values();
		$out   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = sanitize_text_field( wp_unslash( (string) ( $row['number'] ?? '' ) ) );
			if ( '' === $number ) {
				continue;
			}
			$type  = (string) ( $row['type'] ?? '' );
			$out[] = array(
				'type'   => in_array( $type, $types, true ) ? $type : '',
				'number' => $number,
			);
		}
		return $out;
	}

	/**
	 * @param mixed $rows Raw additional-info rows.
	 * @return array<int, array{type:string,value:string}>
	 */
	private static function sanitize_additional_info( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$types = LocalChoices::additional_info_values();
		$out   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = (string) ( $row['type'] ?? '' );
			if ( ! in_array( $type, $types, true ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( (string) ( $row['value'] ?? '' ) ) );
			if ( '' === $value ) {
				continue;
			}
			$out[] = array(
				'type'  => $type,
				'value' => $value,
			);
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run tests + analysis + lint**

Run: `vendor/bin/phpunit --filter LocalSeoSanitizerTest`
Expected: PASS (6 tests).

Run: `composer analyze` → No errors. Run: `composer lint` → clean.

- [ ] **Step 5: Commit**

```bash
git add src/Settings/LocalSeoSanitizer.php tests/Unit/Settings/LocalSeoSanitizerTest.php
git commit -m "feat(settings): LocalSeoSanitizer (geo/address/repeatable rows)"
```

---

## Task 3: `Options` — defaults + delegación al sanitizer

**Files:**
- Modify: `src/Settings/Options.php`
- Modify: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Consumes: `LocalSeoSanitizer::sanitize` (Task 2).
- Produces: `Options::all()` incluye las 9 keys; `sanitize()` delega los `local_*` de 2b-i.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/OptionsTest.php`:

```php
	public function test_defaults_include_local_business_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$o = new Options();

		$this->assertSame( '', $o->get( 'local_business_type' ) );
		$this->assertSame( array(), $o->get( 'local_opening_hours' ) );
		$this->assertSame(
			array( 'street' => '', 'locality' => '', 'region' => '', 'postal_code' => '', 'country' => '' ),
			$o->get( 'local_address' )
		);
	}

	public function test_sanitize_delegates_local_business_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'local_business_type' => 'Restaurant' ) );
		$this->assertSame( 'Restaurant', $clean['local_business_type'] );
	}

	public function test_sanitize_other_tab_does_not_wipe_local_keys(): void {
		// A previously saved local_business_type is stored; another tab posts only its own field.
		Functions\when( 'get_option' )->justReturn( array( 'local_business_type' => 'Store' ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'title_separator' => '|' ) );
		$this->assertSame( 'Store', $clean['local_business_type'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL — keys missing / not delegated.

- [ ] **Step 3: Add the 9 defaults**

In `src/Settings/Options.php` `defaults()`, after the `'local_email' => '',` line (the last entry), add:

```php
			'local_business_type'          => '',
			'local_description'            => '',
			'local_price_range'            => '',
			'local_geo'                    => '',
			'local_phone'                  => '',
			'local_address'                => array(
				'street'      => '',
				'locality'    => '',
				'region'      => '',
				'postal_code' => '',
				'country'     => '',
			),
			'local_opening_hours'          => array(),
			'local_phone_numbers'          => array(),
			'local_additional_info'        => array(),
```

- [ ] **Step 4: Delegate the local keys in `sanitize()`**

In `src/Settings/Options.php` `sanitize()`, immediately before `return $clean;`, add:

```php
		$local_keys = array(
			'local_business_type',
			'local_description',
			'local_price_range',
			'local_geo',
			'local_phone',
			'local_address',
			'local_opening_hours',
			'local_phone_numbers',
			'local_additional_info',
		);
		if ( array() !== array_intersect_key( $input, array_flip( $local_keys ) ) ) {
			$clean = array_merge( $clean, LocalSeoSanitizer::sanitize( $input, $clean ) );
		}
```

(`LocalSeoSanitizer` is in the same `OpenSEO\Settings` namespace, so no `use` statement is required.)

- [ ] **Step 5: Run tests + analysis**

Run: `vendor/bin/phpunit --filter OptionsTest` → PASS (existing + 3 new).
Run: `composer analyze` → No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(settings): local business defaults + delegate sanitize"
```

---

## Task 4: `Schema\LocalBusiness::build`

**Files:**
- Create: `src/Schema/LocalBusiness.php`
- Test: `tests/Unit/Schema/LocalBusinessTest.php`

**Interfaces:**
- Consumes: `Options` (the 9 keys), `LocalChoices` (Task 1).
- Produces: `( new LocalBusiness() )->build( Options ): array` — the local JSON-LD props to merge into the Organization node (no `@type`/`@id`/`name`/`url`/`email`/`logo`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LocalBusinessTest`
Expected: FAIL — `Class "OpenSEO\Schema\LocalBusiness" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
/**
 * Builds the LocalBusiness JSON-LD properties from the stored settings.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

use OpenSEO\Settings\Options;

/**
 * Pure translator from `local_*` options to the JSON-LD props merged into the
 * Organization identity node. `geo`/`openingHoursSpecification`/`priceRange` are
 * emitted only when a business type is set (they are LocalBusiness-only).
 */
final class LocalBusiness {

	/**
	 * Build the local props to merge into the Organization node.
	 *
	 * @param Options $options Settings accessor.
	 * @return array<string, mixed>
	 */
	public function build( Options $options ): array {
		$is_local = '' !== (string) $options->get( 'local_business_type' );
		$data     = array();

		$phone = (string) $options->get( 'local_phone' );
		if ( '' !== $phone ) {
			$data['telephone'] = $phone;
		}

		$description = (string) $options->get( 'local_description' );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		$address = $this->address( $options );
		if ( array() !== $address ) {
			$data['address'] = $address;
		}

		$contact_points = $this->contact_points( $options );
		if ( array() !== $contact_points ) {
			$data['contactPoint'] = $contact_points;
		}

		foreach ( $this->additional_props( $options ) as $key => $value ) {
			$data[ $key ] = $value;
		}

		if ( $is_local ) {
			$geo = $this->geo( $options );
			if ( array() !== $geo ) {
				$data['geo'] = $geo;
			}

			$hours = $this->opening_hours( $options );
			if ( array() !== $hours ) {
				$data['openingHoursSpecification'] = $hours;
			}

			$price = (string) $options->get( 'local_price_range' );
			if ( '' !== $price ) {
				$data['priceRange'] = $price;
			}
		}

		return $data;
	}

	/**
	 * @return array<string, string>
	 */
	private function address( Options $options ): array {
		$stored = $options->get( 'local_address' );
		$stored = is_array( $stored ) ? $stored : array();
		$map    = array(
			'street'      => 'streetAddress',
			'locality'    => 'addressLocality',
			'region'      => 'addressRegion',
			'postal_code' => 'postalCode',
			'country'     => 'addressCountry',
		);
		$out    = array( '@type' => 'PostalAddress' );
		$has    = false;
		foreach ( $map as $key => $schema_key ) {
			$value = (string) ( $stored[ $key ] ?? '' );
			if ( '' !== $value ) {
				$out[ $schema_key ] = $value;
				$has                = true;
			}
		}
		return $has ? $out : array();
	}

	/**
	 * @return array<string, float|string>
	 */
	private function geo( Options $options ): array {
		$raw = (string) $options->get( 'local_geo' );
		if ( '' === $raw ) {
			return array();
		}
		$parts = explode( ',', $raw );
		if ( 2 !== count( $parts ) ) {
			return array();
		}
		return array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $parts[0],
			'longitude' => (float) $parts[1],
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function opening_hours( Options $options ): array {
		$rows = $options->get( 'local_opening_hours' );
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => 'https://schema.org/' . (string) ( $row['day'] ?? '' ),
				'opens'     => (string) ( $row['opens'] ?? '' ),
				'closes'    => (string) ( $row['closes'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function contact_points( Options $options ): array {
		$rows = $options->get( 'local_phone_numbers' );
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = (string) ( $row['number'] ?? '' );
			if ( '' === $number ) {
				continue;
			}
			$point = array(
				'@type'     => 'ContactPoint',
				'telephone' => $number,
			);
			$type  = (string) ( $row['type'] ?? '' );
			if ( '' !== $type ) {
				$point['contactType'] = $type;
			}
			$out[] = $point;
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function additional_props( Options $options ): array {
		$rows = $options->get( 'local_additional_info' );
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type  = (string) ( $row['type'] ?? '' );
			$value = (string) ( $row['value'] ?? '' );
			if ( '' === $type || '' === $value ) {
				continue;
			}
			if ( 'numberOfEmployees' === $type ) {
				$out['numberOfEmployees'] = array(
					'@type' => 'QuantitativeValue',
					'value' => $value,
				);
				continue;
			}
			$out[ $type ] = $value;
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run test + analysis**

Run: `vendor/bin/phpunit --filter LocalBusinessTest` → PASS.
Run: `composer analyze` → No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Schema/LocalBusiness.php tests/Unit/Schema/LocalBusinessTest.php
git commit -m "feat(schema): LocalBusiness builder (address/geo/hours/contactPoint/extra)"
```

---

## Task 5: `Organization` + `Person` pieces — merge LocalBusiness

**Files:**
- Modify: `src/Schema/Pieces/Organization.php`
- Modify: `src/Schema/Pieces/Person.php`
- Modify: `tests/Unit/Schema/Pieces/SitePiecesTest.php`

**Interfaces:**
- Consumes: `Schema\LocalBusiness::build` (Task 4), `Schema\LocalChoices::business_type_values` (Task 1).
- Produces: Organization `@type` = business type + merged local props; Person `telephone`.

- [ ] **Step 1: Add failing tests**

Add to `tests/Unit/Schema/Pieces/SitePiecesTest.php`:

```php
	public function test_organization_becomes_local_business_with_props(): void {
		$org = new Organization(
			$this->options(
				array(
					'schema_site_type'    => 'Organization',
					'local_business_type' => 'Restaurant',
					'local_phone'         => '+1-555',
					'local_address'       => array( 'street' => 'Main St', 'locality' => '', 'region' => '', 'postal_code' => '', 'country' => 'US' ),
					'local_price_range'   => '$$',
				)
			)
		);

		$data = $org->data();
		$this->assertSame( 'Restaurant', $data['@type'] );
		$this->assertSame( '+1-555', $data['telephone'] );
		$this->assertSame( 'PostalAddress', $data['address']['@type'] );
		$this->assertSame( '$$', $data['priceRange'] );
	}

	public function test_organization_without_business_type_stays_organization(): void {
		$data = ( new Organization(
			$this->options(
				array(
					'schema_site_type'  => 'Organization',
					'local_price_range' => '$$',
					'local_description' => 'About',
				)
			)
		) )->data();

		$this->assertSame( 'Organization', $data['@type'] );
		$this->assertArrayNotHasKey( 'priceRange', $data );
		$this->assertSame( 'About', $data['description'] );
	}

	public function test_person_emits_telephone(): void {
		$data = ( new Person( $this->options( array( 'schema_site_type' => 'Person', 'local_phone' => '+1-555' ) ) ) )->data();
		$this->assertSame( '+1-555', $data['telephone'] );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SitePiecesTest`
Expected: FAIL — `@type` not LocalBusiness; no telephone.

- [ ] **Step 3: Update `Organization::data()`**

In `src/Schema/Pieces/Organization.php`, add the imports below `use OpenSEO\Settings\Options;`:

```php
use OpenSEO\Schema\LocalBusiness;
use OpenSEO\Schema\LocalChoices;
```

Replace the base `$data = array( … );` literal (the `@type`/`@id`/`name`/`url` node) with:

```php
		$business_type = (string) $this->options->get( 'local_business_type' );
		$type          = in_array( $business_type, LocalChoices::business_type_values(), true ) ? $business_type : 'Organization';

		$data = array(
			'@type' => $type,
			'@id'   => $this->id(),
			'name'  => $name,
			'url'   => $url,
		);
```

Then, immediately before `return $data;` (after the existing `email`/`logo` blocks), add:

```php
		$data = array_merge( $data, ( new LocalBusiness() )->build( $this->options ) );
```

- [ ] **Step 4: Update `Person::data()`**

In `src/Schema/Pieces/Person.php`, immediately before `return $data;` (after the existing `email`/`logo` blocks), add:

```php
		$phone = (string) $this->options->get( 'local_phone' );
		if ( '' !== $phone ) {
			$data['telephone'] = $phone;
		}
```

- [ ] **Step 5: Run tests + analysis**

Run: `vendor/bin/phpunit --filter SitePiecesTest` → PASS (existing 2a tests still green — empty defaults add nothing, `@type` stays Organization/Person).
Run: `composer test:unit` → whole suite green.
Run: `composer analyze` → No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Schema/Pieces/Organization.php src/Schema/Pieces/Person.php tests/Unit/Schema/Pieces/SitePiecesTest.php
git commit -m "feat(schema): Organization LocalBusiness @type + merge; Person telephone"
```

---

## Task 6: `Admin\Assets` — bootstrap `localChoices`

**Files:**
- Modify: `src/Admin/Assets.php`

**Interfaces:**
- Consumes: `Schema\LocalChoices` (Task 1).
- Produces: `window.openseoAdmin.localChoices = { businessTypes, phoneTypes, additionalInfoTypes, days }`.

- [ ] **Step 1: Add the import + bootstrap entry**

In `src/Admin/Assets.php`, add below the other `use` statements:

```php
use OpenSEO\Schema\LocalChoices;
```

In `bootstrap()`, in the `$data = array( … )` literal, add a `localChoices` entry alongside `variables` (e.g. right after the `'variables' => …,` line):

```php
			'localChoices' => array(
				'businessTypes'       => LocalChoices::business_types(),
				'phoneTypes'          => LocalChoices::phone_types(),
				'additionalInfoTypes' => LocalChoices::additional_info_types(),
				'days'                => LocalChoices::days(),
			),
```

- [ ] **Step 2: Verify gates (no unit test for Assets)**

Run: `composer lint` → PHPCS clean.
Run: `composer analyze` → No errors.

- [ ] **Step 3: Commit**

```bash
git add src/Admin/Assets.php
git commit -m "feat(admin): bootstrap localChoices for the Local SEO UI"
```

---

## Task 7: `repeatable.js` — helpers puros

**Files:**
- Create: `assets/src/admin/repeatable.js`
- Test: `assets/src/admin/repeatable.test.js`

**Interfaces:**
- Produces: `addRow(rows, emptyRow)`, `removeRow(rows, index)`, `updateCell(rows, index, key, value)` — immutable.

- [ ] **Step 1: Write the failing test**

```js
import { addRow, removeRow, updateCell } from './repeatable';

describe( 'repeatable helpers', () => {
	it( 'addRow appends a copy of emptyRow without mutating', () => {
		const rows = [ { a: '1' } ];
		const next = addRow( rows, { a: '' } );
		expect( next ).toEqual( [ { a: '1' }, { a: '' } ] );
		expect( rows ).toHaveLength( 1 );
	} );

	it( 'removeRow drops the row at index immutably', () => {
		const rows = [ { a: '1' }, { a: '2' }, { a: '3' } ];
		const next = removeRow( rows, 1 );
		expect( next ).toEqual( [ { a: '1' }, { a: '3' } ] );
		expect( rows ).toHaveLength( 3 );
	} );

	it( 'updateCell sets one cell of one row immutably', () => {
		const rows = [ { a: '1', b: 'x' }, { a: '2', b: 'y' } ];
		const next = updateCell( rows, 0, 'b', 'z' );
		expect( next[ 0 ] ).toEqual( { a: '1', b: 'z' } );
		expect( next[ 1 ] ).toBe( rows[ 1 ] );
		expect( rows[ 0 ].b ).toBe( 'x' );
	} );

	it( 'tolerates a missing rows array', () => {
		expect( addRow( undefined, { a: '' } ) ).toEqual( [ { a: '' } ] );
		expect( removeRow( undefined, 0 ) ).toEqual( [] );
		expect( updateCell( undefined, 0, 'a', '1' ) ).toEqual( [] );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:js -- repeatable.test.js`
Expected: FAIL — cannot find module `./repeatable`.

- [ ] **Step 3: Write the implementation**

```js
/**
 * Pure immutable helpers for repeatable-row fields.
 */

/**
 * @param {Array}  rows     Current rows.
 * @param {Object} emptyRow Shape of a new blank row.
 * @return {Array} New array with a copy of emptyRow appended.
 */
export function addRow( rows, emptyRow ) {
	return [ ...( rows ?? [] ), { ...emptyRow } ];
}

/**
 * @param {Array}  rows  Current rows.
 * @param {number} index Row to remove.
 * @return {Array} New array without that row.
 */
export function removeRow( rows, index ) {
	return ( rows ?? [] ).filter( ( _row, i ) => i !== index );
}

/**
 * @param {Array}  rows  Current rows.
 * @param {number} index Row to update.
 * @param {string} key   Cell key.
 * @param {string} value New value.
 * @return {Array} New array with one cell of one row changed.
 */
export function updateCell( rows, index, key, value ) {
	return ( rows ?? [] ).map( ( row, i ) =>
		i === index ? { ...row, [ key ]: value } : row
	);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:js -- repeatable.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/src/admin/repeatable.js assets/src/admin/repeatable.test.js
git commit -m "feat(admin): repeatable row helpers (add/remove/updateCell)"
```

---

## Task 8: `RepeatableGroup` component (+ SCSS)

**Files:**
- Create: `assets/src/admin/components/RepeatableGroup.js`
- Modify: `assets/src/admin/style.scss`

**Interfaces:**
- Consumes: `addRow`/`removeRow`/`updateCell` (Task 7); `Button`/`SelectControl`/`TextControl`/`Flex`/`FlexItem` from `@wordpress/components`.
- Produces: `RepeatableGroup({ label, value, columns, emptyRow, onChange, addLabel })`. `columns`: `[{ key, label, control: 'text'|'time'|'select', options? }]`.

- [ ] **Step 1: Write the component**

```jsx
import {
	Button,
	SelectControl,
	TextControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addRow, removeRow, updateCell } from '../repeatable';

/**
 * Generic repeatable-row editor (opening hours, phone numbers, additional info).
 * Row index is the React key on purpose: controls are fully controlled by value,
 * no per-row local state, and the whole group re-renders on each change.
 *
 * @param {Object}   props
 * @param {string}   props.label    Group legend.
 * @param {Array}    props.value    Rows.
 * @param {Array}    props.columns  Column descriptors.
 * @param {Object}   props.emptyRow Shape of a new row.
 * @param {Function} props.onChange Receives the new rows array.
 * @param {string}   props.addLabel Label for the Add button.
 */
export function RepeatableGroup( {
	label,
	value,
	columns,
	emptyRow,
	onChange,
	addLabel,
} ) {
	const rows = value ?? [];

	return (
		<fieldset className="openseo-repeatable">
			<legend>{ label }</legend>
			{ rows.map( ( row, index ) => (
				<Flex
					key={ index }
					className="openseo-repeatable__row"
					align="flex-end"
					gap={ 2 }
				>
					{ columns.map( ( col ) => (
						<FlexItem key={ col.key } isBlock>
							{ col.control === 'select' ? (
								<SelectControl
									__nextHasNoMarginBottom
									label={ col.label }
									value={ row[ col.key ] ?? '' }
									options={ col.options }
									onChange={ ( v ) =>
										onChange(
											updateCell( rows, index, col.key, v )
										)
									}
								/>
							) : (
								<TextControl
									__nextHasNoMarginBottom
									type={ col.control === 'time' ? 'time' : 'text' }
									label={ col.label }
									value={ row[ col.key ] ?? '' }
									onChange={ ( v ) =>
										onChange(
											updateCell( rows, index, col.key, v )
										)
									}
								/>
							) }
						</FlexItem>
					) ) }
					<FlexItem>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => onChange( removeRow( rows, index ) ) }
						>
							{ __( 'Remove', 'openseo' ) }
						</Button>
					</FlexItem>
				</Flex>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => onChange( addRow( rows, emptyRow ) ) }
			>
				{ addLabel }
			</Button>
		</fieldset>
	);
}
```

- [ ] **Step 2: Add SCSS for the repeatable group**

In `assets/src/admin/style.scss`, add to the "Field components" section (e.g. after `.openseo-advanced-robots { … }`):

```scss
.openseo-repeatable {
	margin: 0;
	padding: 16px;
	border: 1px solid var(--openseo-divider);
	border-radius: 6px;
	background: var(--openseo-bg-muted);

	> legend {
		padding: 0 6px;
		margin-left: -6px;
		color: var(--openseo-text);
		font-size: 12px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.02em;
	}

	&__row {
		margin-bottom: 10px;
	}
}
```

- [ ] **Step 3: Lint + build**

Run: `npm run lint:js` → no errors.
Run: `npm run lint:css` → no errors.
Run: `npm run build` → succeeds.

- [ ] **Step 4: Commit**

```bash
git add assets/src/admin/components/RepeatableGroup.js assets/src/admin/style.scss
git commit -m "feat(admin): RepeatableGroup row editor component"
```

---

## Task 9: `LocalBusinessFields` component

**Files:**
- Create: `assets/src/admin/components/LocalBusinessFields.js`

**Interfaces:**
- Consumes: `RepeatableGroup` (Task 8); `window.openseoAdmin.localChoices` (Task 6); `SelectControl`/`TextControl`/`TextareaControl` from `@wordpress/components`.
- Produces: `LocalBusinessFields({ values, change })` — the org-only LocalBusiness section.

- [ ] **Step 1: Write the component**

```jsx
import {
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { RepeatableGroup } from './RepeatableGroup';

const bootstrap = window.openseoAdmin ?? {};
const choices = bootstrap.localChoices ?? {
	businessTypes: [],
	phoneTypes: [],
	additionalInfoTypes: [],
	days: [],
};

const BUSINESS_TYPE_OPTIONS = [
	{ label: __( '— None', 'openseo' ), value: '' },
	...choices.businessTypes,
];

const PHONE_TYPE_OPTIONS = [
	{ label: __( '— Type', 'openseo' ), value: '' },
	...choices.phoneTypes,
];

const FIRST_DAY = choices.days[ 0 ]?.value ?? 'Monday';
const FIRST_INFO = choices.additionalInfoTypes[ 0 ]?.value ?? 'legalName';

const ADDRESS_FIELDS = [
	{ key: 'street', label: __( 'Street address', 'openseo' ) },
	{ key: 'locality', label: __( 'City / locality', 'openseo' ) },
	{ key: 'region', label: __( 'Region / state', 'openseo' ) },
	{ key: 'postal_code', label: __( 'Postal code', 'openseo' ) },
];

export function LocalBusinessFields( { values, change } ) {
	const address = values.local_address ?? {};
	const setAddress = ( key, v ) =>
		change( 'local_address', { ...address, [ key ]: v } );

	return (
		<>
			<h3>{ __( 'Local business', 'openseo' ) }</h3>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Business type', 'openseo' ) }
				value={ values.local_business_type ?? '' }
				options={ BUSINESS_TYPE_OPTIONS }
				onChange={ ( v ) => change( 'local_business_type', v ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Description', 'openseo' ) }
				value={ values.local_description ?? '' }
				onChange={ ( v ) => change( 'local_description', v ) }
			/>
			<h3>{ __( 'Address', 'openseo' ) }</h3>
			{ ADDRESS_FIELDS.map( ( field ) => (
				<TextControl
					key={ field.key }
					__nextHasNoMarginBottom
					label={ field.label }
					value={ address[ field.key ] ?? '' }
					onChange={ ( v ) => setAddress( field.key, v ) }
				/>
			) ) }
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Country', 'openseo' ) }
				help={ __( 'ISO 3166-1 alpha-2 code, e.g. US, ES.', 'openseo' ) }
				value={ address.country ?? '' }
				onChange={ ( v ) => setAddress( 'country', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Price range', 'openseo' ) }
				value={ values.local_price_range ?? '' }
				onChange={ ( v ) => change( 'local_price_range', v ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Geo coordinates', 'openseo' ) }
				help={ __( 'Latitude,longitude — e.g. 40.7128,-74.006.', 'openseo' ) }
				value={ values.local_geo ?? '' }
				onChange={ ( v ) => change( 'local_geo', v ) }
			/>
			<RepeatableGroup
				label={ __( 'Opening hours', 'openseo' ) }
				value={ values.local_opening_hours }
				columns={ [
					{
						key: 'day',
						label: __( 'Day', 'openseo' ),
						control: 'select',
						options: choices.days,
					},
					{ key: 'opens', label: __( 'Opens', 'openseo' ), control: 'time' },
					{ key: 'closes', label: __( 'Closes', 'openseo' ), control: 'time' },
				] }
				emptyRow={ { day: FIRST_DAY, opens: '', closes: '' } }
				onChange={ ( rows ) => change( 'local_opening_hours', rows ) }
				addLabel={ __( 'Add hours', 'openseo' ) }
			/>
			<RepeatableGroup
				label={ __( 'Phone numbers', 'openseo' ) }
				value={ values.local_phone_numbers }
				columns={ [
					{
						key: 'type',
						label: __( 'Type', 'openseo' ),
						control: 'select',
						options: PHONE_TYPE_OPTIONS,
					},
					{ key: 'number', label: __( 'Number', 'openseo' ), control: 'text' },
				] }
				emptyRow={ { type: '', number: '' } }
				onChange={ ( rows ) => change( 'local_phone_numbers', rows ) }
				addLabel={ __( 'Add phone', 'openseo' ) }
			/>
			<RepeatableGroup
				label={ __( 'Additional info', 'openseo' ) }
				value={ values.local_additional_info }
				columns={ [
					{
						key: 'type',
						label: __( 'Type', 'openseo' ),
						control: 'select',
						options: choices.additionalInfoTypes,
					},
					{ key: 'value', label: __( 'Value', 'openseo' ), control: 'text' },
				] }
				emptyRow={ { type: FIRST_INFO, value: '' } }
				onChange={ ( rows ) => change( 'local_additional_info', rows ) }
				addLabel={ __( 'Add info', 'openseo' ) }
			/>
		</>
	);
}
```

- [ ] **Step 2: Lint + build**

Run: `npm run lint:js` → no errors.
Run: `npm run build` → succeeds.

- [ ] **Step 3: Commit**

```bash
git add assets/src/admin/components/LocalBusinessFields.js
git commit -m "feat(admin): LocalBusinessFields (address/hours/phones/extra)"
```

---

## Task 10: `Titles.js` — montar LocalBusinessFields en SeoLocalPanel

**Files:**
- Modify: `assets/src/admin/views/Titles.js`

**Interfaces:**
- Consumes: `LocalBusinessFields` (Task 9).

- [ ] **Step 1: Add the import**

In `assets/src/admin/views/Titles.js`, add below the other component imports (e.g. after the `AdvancedRobotsField` import):

```jsx
import { LocalBusinessFields } from '../components/LocalBusinessFields';
```

- [ ] **Step 2: Add the phone field + the org-only section to `SeoLocalPanel`**

In `SeoLocalPanel`, the fragment currently ends with the Email `TextControl` then `</>`. Insert, after the Email `TextControl` and before the closing `</>`:

```jsx
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Phone', 'openseo' ) }
				value={ values.local_phone ?? '' }
				onChange={ ( v ) => change( 'local_phone', v ) }
			/>
			{ ( values.schema_site_type ?? 'Organization' ) === 'Organization' && (
				<LocalBusinessFields values={ values } change={ change } />
			) }
```

- [ ] **Step 3: Lint, test, build**

Run: `npm run lint:js` → no errors.
Run: `npm run test:js` → all pass.
Run: `npm run build` → succeeds.

- [ ] **Step 4: Commit**

```bash
git add assets/src/admin/views/Titles.js
git commit -m "feat(admin): mount LocalBusinessFields + phone in SEO Local"
```

---

## Task 11: Verificación final + .pot + smoke

**Files:** `languages/openseo.pot`.

- [ ] **Step 1: PHP gates**

Run: `composer check`
Expected: PHPCS clean, PHPStan (level 6) no errors, PHPUnit all green (incl. `LocalChoicesTest`, `LocalSeoSanitizerTest`, `LocalBusinessTest`, `OptionsTest`, `SitePiecesTest`).

- [ ] **Step 2: JS gates**

Run: `npm run lint:js` → no errors. `npm run lint:css` → no errors. `npm run test:js` → all pass (incl. `repeatable`). `npm run build` → succeeds.

- [ ] **Step 3: Regenerate the `.pot`**

```bash
npm run env:run -- cli wp i18n make-pot wp-content/plugins/openseo wp-content/plugins/openseo/languages/openseo.pot
```
(Destination MUST be the plugin-relative path — a bare `languages/openseo.pot` writes to the container WP root, not the plugin. Confirm: `git status --short languages/openseo.pot`.)

Verify a new string landed:
```bash
grep -cE "Business type|Opening hours|Local business" languages/openseo.pot   # expected: >= 1
```

- [ ] **Step 4: Manual smoke test (wp-env)**

In **SEO Local** (site type Organization): set Business type "Restaurant", fill address + a primary phone, add one opening-hours row and one additional phone, Save. View a page source → the `@graph` Organization node has `@type:"Restaurant"`, `telephone`, `address` (PostalAddress), `openingHoursSpecification`, `contactPoint`. Paste the URL into validator.schema.org and Google's Rich Results Test → 0 errors. (If wp-env/Docker unavailable, note it as a deferred follow-up — do not skip silently.)

- [ ] **Step 5: Commit the `.pot`**

```bash
git add languages/openseo.pot
git commit -m "chore(i18n): regenerate .pot for Local SEO 2b-i"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** `LocalChoices` enums (Task 1); `LocalSeoSanitizer` incl. M3 partial-merge + geo/rows (Task 2); Options defaults + delegation (Task 3); `LocalBusiness::build` incl. H1 telephone, H2 gating, M1 QuantitativeValue (Task 4); Organization @type + merge + Person telephone, incl. no-regression (Task 5); bootstrap `localChoices` (Task 6); `repeatable.js` (Task 7); `RepeatableGroup` + M5 index-key + SCSS (Task 8); `LocalBusinessFields` incl. M4 country ISO help (Task 9); SeoLocalPanel phone + org-only mount (Task 10); .pot + L3 validator smoke (Task 11). Every acceptance criterion has a task.
- **Placeholder scan:** no TBD/TODO; every code step shows the code and the command with expected output.
- **Type/símbolo consistency:** `LocalChoices::*_values()` (1) consumed by `LocalSeoSanitizer` (2), `LocalBusiness` (4), `Organization` (5); `LocalSeoSanitizer::sanitize($input,$current)` (2) called by `Options::sanitize` (3); `LocalBusiness::build(Options): array` (4) merged by `Organization::data` (5); `localChoices` produced by `Assets` (6) read by `LocalBusinessFields` (9); `addRow/removeRow/updateCell` (7) used by `RepeatableGroup` (8) used by `LocalBusinessFields` (9) mounted by `SeoLocalPanel` (10). The 9 `local_*` keys match across defaults (3), sanitizer (2), build (4), and UI (9/10).
- **Green-by-commit:** Task 3 (defaults) precedes the readers (4/5); Task 1 precedes 2/4/5/6; Task 2 precedes 3; Task 7 precedes 8; Task 6 (bootstrap) precedes the runtime use in 9; Task 9 precedes 10. Existing `SitePiecesTest`/`OptionsTest` stay green (empty defaults add nothing; partial-merge preserves keys). No symbol used before its task.
- **Design audit incorporated:** H1 (telephone for both pieces, Tasks 4/5), H2 (geo/hours/priceRange gated + test, Tasks 4/5), M3 (partial-merge contract + test, Tasks 2/3), M1 (QuantitativeValue, Task 4), M2 (Google contactType values, Task 1), M4 (country ISO help, Task 9), M5 (index-key conscious decision, Task 8), L1/L3/L4 (Person no LocalBusiness; validator smoke; foundingDate help — spec-level + Tasks 9/11).
