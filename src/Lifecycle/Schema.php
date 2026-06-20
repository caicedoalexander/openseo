<?php
/**
 * Custom table schema and migrations.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

/**
 * Creates and upgrades OpenSEO's custom tables via dbDelta().
 */
final class Schema {

	/**
	 * Schema version. Bump when a table definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Option key holding the installed schema version.
	 */
	public const VERSION_OPTION = 'openseo_db_version';

	/**
	 * Fully-qualified redirects table name.
	 */
	public static function redirects_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'openseo_redirects';
	}

	/**
	 * Fully-qualified 404 logs table name.
	 */
	public static function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'openseo_404_logs';
	}

	/**
	 * Installed schema version, or '' when never installed.
	 */
	public static function current_version(): string {
		return (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Create or upgrade the tables. Idempotent (dbDelta diff).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$redirects       = self::redirects_table();
		$logs            = self::logs_table();

		// dbDelta is whitespace- and format-sensitive: two spaces after
		// PRIMARY KEY, every index named, lowercase types, one field per line.
		$redirects_sql = "CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL auto_increment,
  source_path varchar(255) NOT NULL default '',
  target varchar(2048) NOT NULL default '',
  status_code smallint(5) unsigned NOT NULL default 301,
  is_regex tinyint(1) NOT NULL default 0,
  enabled tinyint(1) NOT NULL default 1,
  hits bigint(20) unsigned NOT NULL default 0,
  last_accessed datetime default NULL,
  created_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY source_path (source_path(191)),
  KEY is_regex (is_regex)
) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL auto_increment,
  url text NOT NULL,
  url_hash char(32) NOT NULL default '',
  hits bigint(20) unsigned NOT NULL default 0,
  first_seen datetime NOT NULL default '0000-00-00 00:00:00',
  last_seen datetime NOT NULL default '0000-00-00 00:00:00',
  referrer varchar(255) default NULL,
  user_agent varchar(255) default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash)
) {$charset_collate};";

		dbDelta( $redirects_sql );
		dbDelta( $logs_sql );

		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
