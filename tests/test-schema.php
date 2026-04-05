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
	 * The upgrader runs install when the version option is missing.
	 */
	public function test_maybe_upgrade_installs_when_missing(): void {
		delete_option( Schema::VERSION_OPTION );
		$fired = $this->track_install_action();

		Schema::maybe_upgrade();

		$this->assertSame( 1, $fired(), 'install() should fire once when version is missing' );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * The upgrader runs install when the stored version is stale.
	 */
	public function test_maybe_upgrade_installs_when_stale(): void {
		update_option( Schema::VERSION_OPTION, '0.0.0-old' );
		$fired = $this->track_install_action();

		Schema::maybe_upgrade();

		$this->assertSame( 1, $fired(), 'install() should fire once when version is stale' );
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * The upgrader is a no-op when the stored version matches.
	 */
	public function test_maybe_upgrade_skips_when_current(): void {
		update_option( Schema::VERSION_OPTION, Schema::DB_VERSION );
		$fired = $this->track_install_action();

		Schema::maybe_upgrade();

		$this->assertSame( 0, $fired(), 'install() should not fire when version matches' );
	}

	/**
	 * Adds a temporary action listener and returns a closure yielding the call count.
	 */
	private function track_install_action(): callable {
		$count    = 0;
		$listener = static function () use ( &$count ): void {
			++$count;
		};
		add_action( 'optrion_schema_installed', $listener );
		return static function () use ( &$count ): int {
			return $count;
		};
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
