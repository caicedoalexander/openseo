<?php
/**
 * Data access for the 404 logs table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

use OpenSEO\Lifecycle\Schema;

/**
 * Encapsulates the aggregated 404 log. record() is the only raw SQL in the
 * plugin (an ON DUPLICATE KEY upsert that $wpdb->insert cannot express); every
 * value is parameterized and url_hash is computed in PHP.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class LogRepository implements LogRepositoryInterface {

	/**
	 * Upsert a 404 hit, aggregating by URL.
	 *
	 * @param string $url        The requested URL path.
	 * @param string $referrer   HTTP Referer header value.
	 * @param string $user_agent HTTP User-Agent header value.
	 */
	public function record( string $url, string $referrer = '', string $user_agent = '' ): void {
		global $wpdb;

		$url        = $this->trim( $url, 2048 );
		$url_hash   = md5( $url );
		$referrer   = '' === $referrer ? null : $this->trim( $referrer, 255 );
		$user_agent = '' === $user_agent ? null : $this->trim( $user_agent, 255 );
		// UTC, so prune()'s gmdate() cutoff compares correctly on any timezone.
		$now   = current_time( 'mysql', true );
		$table = Schema::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (url, url_hash, hits, first_seen, last_seen, referrer, user_agent) VALUES (%s, %s, 1, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = VALUES(referrer), user_agent = VALUES(user_agent)",
				$url,
				$url_hash,
				$now,
				$now,
				$referrer,
				$user_agent
			)
		);
	}

	/**
	 * Paginated rows, newest activity first.
	 *
	 * @param int $limit  Maximum rows to return.
	 * @param int $offset Row offset for pagination.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit, int $offset ): array {
		global $wpdb;

		$table = Schema::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d OFFSET %d", $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Total logged URLs.
	 */
	public function count_all(): int {
		global $wpdb;

		$table = Schema::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete one logged URL.
	 *
	 * @param int $id Row ID to delete.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::logs_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Empty the log.
	 */
	public function clear(): void {
		global $wpdb;

		$table = Schema::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Delete rows whose last_seen is older than $days. Returns rows removed.
	 *
	 * @param int $days Retention window in days.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$table  = Schema::logs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
	}

	/**
	 * Sanitize + truncate a value to a column width.
	 *
	 * @param string $value  Raw input value.
	 * @param int    $length Maximum byte length.
	 */
	private function trim( string $value, int $length ): string {
		return substr( sanitize_text_field( $value ), 0, $length );
	}
}
