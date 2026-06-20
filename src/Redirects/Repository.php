<?php
/**
 * Data access for the redirects table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use OpenSEO\Lifecycle\Schema;

/**
 * Encapsulates every SQL statement touching {prefix}openseo_redirects. The
 * table name comes from $wpdb->prefix (never user input); all user-supplied
 * values are parameterized with $wpdb->prepare; static queries with no bound
 * values use literal strings (the table name comes from $wpdb->prefix, never
 * user input).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class Repository {

	/**
	 * Build the active ruleset (enabled rules only).
	 */
	public function find_active_ruleset(): Ruleset {
		global $wpdb;

		$table = Schema::redirects_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source_path, target, status_code, is_regex, enabled FROM {$table} WHERE enabled = 1", ARRAY_A );

		$ruleset = new Ruleset();
		foreach ( (array) $rows as $row ) {
			$ruleset->add( $this->to_redirect( $row ) );
		}

		return $ruleset;
	}

	/**
	 * Find one active exact rule by source path (degraded-path lookup).
	 *
	 * @param string $path Normalized source path to look up.
	 */
	public function find_active_by_source( string $path ): ?Redirect {
		global $wpdb;

		$table = Schema::redirects_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id, source_path, target, status_code, is_regex, enabled FROM {$table} WHERE source_path = %s AND is_regex = 0 AND enabled = 1 LIMIT 1", $path ),
			ARRAY_A
		);

		return null === $row ? null : $this->to_redirect( $row );
	}

	/**
	 * Fetch a raw row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::redirects_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row;
	}

	/**
	 * Paginated rows for the list table, optionally filtered by source/target.
	 *
	 * @param int    $limit  Maximum rows to return.
	 * @param int    $offset Rows to skip.
	 * @param string $search Optional search string applied to source_path and target.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit, int $offset, string $search = '' ): array {
		global $wpdb;

		$table = Schema::redirects_table();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path LIKE %s OR target LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, $like, $limit, $offset );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count rows for pagination.
	 *
	 * @param string $search Optional search string applied to source_path and target.
	 */
	public function count_all( string $search = '' ): int {
		global $wpdb;

		$table = Schema::redirects_table();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_path LIKE %s OR target LIKE %s", $like, $like );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT COUNT(*) FROM {$table}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Count enabled rules (used to decide cache degradation).
	 */
	public function count_active(): int {
		global $wpdb;

		$table = Schema::redirects_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" );
	}

	/**
	 * Insert a rule. Returns the new id or 0 on failure.
	 *
	 * @param array<string, mixed> $data Keys: source_path, target, status_code, is_regex, enabled.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			Schema::redirects_table(),
			array(
				'source_path' => (string) $data['source_path'],
				'target'      => (string) ( $data['target'] ?? '' ),
				'status_code' => (int) $data['status_code'],
				'is_regex'    => ! empty( $data['is_regex'] ) ? 1 : 0,
				'enabled'     => ! empty( $data['enabled'] ) ? 1 : 0,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update mutable fields of a rule.
	 *
	 * @param int                  $id   Row id of the rule to update.
	 * @param array<string, mixed> $data Subset of source_path/target/status_code/is_regex/enabled.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$fields  = array();
		$formats = array();
		$map     = array(
			'source_path' => '%s',
			'target'      => '%s',
			'status_code' => '%d',
			'is_regex'    => '%d',
			'enabled'     => '%d',
		);

		foreach ( $map as $key => $format ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( '%d' === $format ) {
				$fields[ $key ] = in_array( $key, array( 'is_regex', 'enabled' ), true )
					? ( ! empty( $data[ $key ] ) ? 1 : 0 )
					: (int) $data[ $key ];
			} else {
				$fields[ $key ] = (string) $data[ $key ];
			}
			$formats[] = $format;
		}

		if ( array() === $fields ) {
			return false;
		}

		return false !== $wpdb->update( Schema::redirects_table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Enable or disable a rule.
	 *
	 * @param int  $id      Row id of the rule.
	 * @param bool $enabled Whether the rule should be active.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, array( 'enabled' => $enabled ) );
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Row id of the rule to delete.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::redirects_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Increment the hit counter and stamp last_accessed.
	 *
	 * @param int $id Row id of the rule.
	 */
	public function record_hit( int $id ): void {
		global $wpdb;

		$table = Schema::redirects_table();
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d", current_time( 'mysql', true ), $id )
		);
	}

	/**
	 * Whether an exact rule already exists for a source path.
	 *
	 * @param string $path Source path to check.
	 */
	public function exists_for_source( string $path ): bool {
		global $wpdb;

		$table = Schema::redirects_table();
		$found = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} WHERE source_path = %s AND is_regex = 0 LIMIT 1", $path )
		);

		return null !== $found;
	}

	/**
	 * Map a raw row to a Redirect DTO.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 */
	private function to_redirect( array $row ): Redirect {
		return new Redirect(
			(int) $row['id'],
			(string) $row['source_path'],
			(string) $row['target'],
			(int) $row['status_code'],
			(bool) (int) $row['is_regex'],
			(bool) (int) $row['enabled'],
		);
	}
}
