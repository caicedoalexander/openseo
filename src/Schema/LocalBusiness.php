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
	 * Build a PostalAddress node from the stored address sub-fields.
	 *
	 * @param Options $options Settings accessor.
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
	 * Build a GeoCoordinates node from the stored "lat,lng" string.
	 *
	 * @param Options $options Settings accessor.
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
	 * Build the OpeningHoursSpecification nodes from the stored rows.
	 *
	 * @param Options $options Settings accessor.
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
	 * Build ContactPoint nodes from the stored phone-numbers rows.
	 *
	 * @param Options $options Settings accessor.
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
	 * Build the additional Organization props from the stored key-value rows.
	 *
	 * @param Options $options Settings accessor.
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
