<?php
/**
 * Schema installer for Optrion custom tables.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Manages creation and upgrade of the plugin's custom tables.
 *
 * See docs/DESIGN.md §3 for the schema contract.
 */
final class Schema {

	/**
	 * Database schema version.
	 *
	 * Bump this whenever a table definition changes.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option key that stores the installed DB version.
	 */
	public const VERSION_OPTION = 'optrion_db_version';

	/**
	 * Returns the tracking table name (with site prefix).
	 */
	public static function tracking_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'options_tracking';
	}

	/**
	 * Returns the quarantine table name (with site prefix).
	 */
	public static function quarantine_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'options_quarantine';
	}

	/**
	 * Runs dbDelta to create or update all custom tables.
	 *
	 * Safe to call repeatedly — dbDelta only issues DDL for differences.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tracking        = self::tracking_table();
		$quarantine      = self::quarantine_table();

		$tracking_sql = "CREATE TABLE {$tracking} (
			option_name varchar(191) NOT NULL,
			last_read_at datetime NULL DEFAULT NULL,
			read_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_reader varchar(255) NOT NULL DEFAULT '',
			reader_type varchar(20) NOT NULL DEFAULT 'unknown',
			first_seen datetime NOT NULL,
			PRIMARY KEY  (option_name),
			KEY last_read_at (last_read_at),
			KEY reader_type (reader_type),
			KEY read_count (read_count)
		) {$charset_collate};";

		$quarantine_sql = "CREATE TABLE {$quarantine} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			original_name varchar(191) NOT NULL,
			original_autoload varchar(20) NOT NULL DEFAULT 'yes',
			quarantined_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			quarantined_by bigint(20) unsigned NOT NULL DEFAULT 0,
			score_at_quarantine int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			restored_at datetime NULL DEFAULT NULL,
			deleted_at datetime NULL DEFAULT NULL,
			notes text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY original_name (original_name),
			KEY status (status),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $tracking_sql );
		dbDelta( $quarantine_sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Checks the stored DB version and reinstalls the schema if it is out of date.
	 *
	 * Intended to run on plugin load so that schema drift after a plugin update
	 * is healed without requiring the user to deactivate/reactivate.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		self::install();
	}
}
