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
