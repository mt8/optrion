<?php
/**
 * Schema installer tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Schema;
use WP_UnitTestCase;

/**
 * Verifies that Schema::install() creates the expected custom tables.
 *
 * @coversDefaultClass \Optrion\Schema
 */
class SchemaTest extends WP_UnitTestCase {

	/**
	 * Ensures a clean slate by dropping the plugin tables before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::tracking_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::quarantine_table() ); // phpcs:ignore WordPress.DB
		delete_option( Schema::VERSION_OPTION );
	}

	/**
	 * The installer creates both custom tables.
	 */
	public function test_install_creates_tables(): void {
		Schema::install();

		$this->assertTrue( $this->table_exists( Schema::tracking_table() ) );
		$this->assertTrue( $this->table_exists( Schema::quarantine_table() ) );
	}

	/**
	 * The installer records the DB version.
	 */
	public function test_install_stores_db_version(): void {
		Schema::install();
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * The installer is idempotent and can be re-run safely.
	 */
	public function test_install_is_idempotent(): void {
		Schema::install();
		Schema::install();
		$this->assertTrue( $this->table_exists( Schema::tracking_table() ) );
		$this->assertTrue( $this->table_exists( Schema::quarantine_table() ) );
	}

	/**
	 * The upgrader installs tables when the version option is missing.
	 */
	public function test_maybe_upgrade_installs_when_missing(): void {
		$this->assertFalse( $this->table_exists( Schema::tracking_table() ) );
		Schema::maybe_upgrade();
		$this->assertTrue( $this->table_exists( Schema::tracking_table() ) );
		$this->assertTrue( $this->table_exists( Schema::quarantine_table() ) );
	}

	/**
	 * The upgrader is a no-op when the stored version matches.
	 */
	public function test_maybe_upgrade_skips_when_current(): void {
		Schema::install();
		// Drop a table to detect whether maybe_upgrade reinstalled it.
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::tracking_table() ); // phpcs:ignore WordPress.DB
		$this->assertFalse( $this->table_exists( Schema::tracking_table() ) );

		Schema::maybe_upgrade();
		$this->assertFalse( $this->table_exists( Schema::tracking_table() ) );
	}

	/**
	 * Checks whether a table exists via SHOW TABLES.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		return $found === $table;
	}
}
