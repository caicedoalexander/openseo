<?php
/**
 * Validates and normalizes a redirect rule before persistence.
 *
 * Extracts the logic that lived in RedirectsPage::handle_save so both the REST
 * create and update paths share one tested unit. Returns clean data or a
 * WP_Error (status 400) the controller serializes.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use WP_Error;

/**
 * Pure-ish rule validator (WP-coupled via esc_url_raw/Normalizer/Regex/lookup,
 * but unit-testable by mocking the RedirectLookup interface).
 */
final class RuleValidator {

	private const STATUSES = array( 301, 302, 307, 410 );

	/**
	 * Construct the validator with a lookup contract for the anti-loop check.
	 *
	 * @param RedirectLookup $lookup Read contract for the anti-loop check.
	 */
	public function __construct( private readonly RedirectLookup $lookup ) {}

	/**
	 * Validate raw rule input.
	 *
	 * @param array<string, mixed> $input source_path, target, status_code, is_regex (+ enabled on edit).
	 * @param int                  $id     0 when creating; the row id when editing (excluded from the anti-loop).
	 * @return array{source_path:string,target:string,status_code:int,is_regex:bool,enabled:bool}|WP_Error
	 */
	public function validate( array $input, int $id = 0 ): array|WP_Error {
		$is_regex = ! empty( $input['is_regex'] );
		$source   = isset( $input['source_path'] ) ? sanitize_text_field( (string) $input['source_path'] ) : '';
		$raw      = isset( $input['target'] ) ? (string) $input['target'] : '';
		$target   = esc_url_raw( $raw, array( 'http', 'https' ) );
		$status   = isset( $input['status_code'] ) ? absint( $input['status_code'] ) : 301;

		// Accept a genuine root-relative path that esc_url_raw rejects (no scheme smuggling).
		if ( '' === $target && '' !== $raw
			&& str_starts_with( $raw, '/' ) && ! str_contains( $raw, '://' ) ) {
			$target = sanitize_text_field( $raw );
		}

		if ( $is_regex ) {
			if ( ! Regex::is_valid( $source ) ) {
				return new WP_Error( 'openseo_invalid_regex', __( 'The regex pattern is invalid.', 'openseo' ), array( 'status' => 400 ) );
			}
		} else {
			$source = ( new Normalizer() )->normalize( $source );
		}

		if ( '' === $source || ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'openseo_invalid', __( 'Check the source, target, and type.', 'openseo' ), array( 'status' => 400 ) );
		}
		if ( 410 !== $status && '' === $target ) {
			return new WP_Error( 'openseo_invalid', __( 'A target is required for this redirect type.', 'openseo' ), array( 'status' => 400 ) );
		}

		// Reject a direct 2-rule cycle (exact rules only; regex patterns are not normalized).
		if ( ! $is_regex && $this->creates_cycle( $id, $source, $target ) ) {
			return new WP_Error( 'openseo_cycle', __( 'This would create a redirect loop with an existing rule.', 'openseo' ), array( 'status' => 400 ) );
		}

		return array(
			'source_path' => $source,
			'target'      => 410 === $status ? '' : $target,
			'status_code' => $status,
			'is_regex'    => $is_regex,
			'enabled'     => array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : true,
		);
	}

	/**
	 * Whether saving source → target closes a direct loop with an existing
	 * active rule target → source. Only internal root-relative targets can cycle.
	 *
	 * @param int    $id     Row id being saved (0 for new), excluded from the lookup.
	 * @param string $source Normalized source path being saved.
	 * @param string $target Target being saved.
	 */
	private function creates_cycle( int $id, string $source, string $target ): bool {
		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) ) {
			return false;
		}

		$normalizer = new Normalizer();
		$back       = $this->lookup->find_active_by_source( $normalizer->normalize( $target ) );

		if ( null === $back || $back->id === $id ) {
			return false;
		}

		return $normalizer->normalize( $back->target ) === $source;
	}
}
