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
			$type                         = sanitize_text_field( wp_unslash( (string) $input['local_business_type'] ) );
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
	private static function sanitize_address( mixed $input, mixed $current ): array {
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
	 * Sanitize opening-hours rows, dropping invalid day/time values.
	 *
	 * @param mixed $rows Raw opening-hours rows.
	 * @return array<int, array{day:string,opens:string,closes:string}>
	 */
	private static function sanitize_hours( mixed $rows ): array {
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
	 * Return $time if it matches HH:MM, empty string otherwise.
	 *
	 * @param string $time Raw HH:MM.
	 */
	private static function time_or_empty( string $time ): string {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	/**
	 * Sanitize phone-number rows, dropping entries with empty number.
	 *
	 * @param mixed $rows Raw phone-number rows.
	 * @return array<int, array{type:string,number:string}>
	 */
	private static function sanitize_phone_numbers( mixed $rows ): array {
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
	 * Sanitize additional-info rows, dropping unknown types or empty values.
	 *
	 * @param mixed $rows Raw additional-info rows.
	 * @return array<int, array{type:string,value:string}>
	 */
	private static function sanitize_additional_info( mixed $rows ): array {
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
