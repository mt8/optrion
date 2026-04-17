<?php
/**
 * Cleaner module tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Cleaner;
use Optrion\Schema;
use WP_UnitTestCase;

/**
 * Exercises the delete workflow.
 *
 * Optrion no longer writes a server-side backup on delete; these tests pin
 * that invariant (no `wp-content/optrion-backups/` directory ever created)
 * alongside the actual delete behavior.
 *
 * @coversDefaultClass \Optrion\Cleaner
 */
class CleanerTest extends WP_UnitTestCase {

	/**
	 * Ensures the schema is in place before each test and wipes the legacy
	 * backup directory left behind by older releases so the isolation test
	 * below can prove that delete() never re-creates it.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();

		$legacy_dir = trailingslashit( WP_CONTENT_DIR ) . 'optrion-backups';
		if ( is_dir( $legacy_dir ) ) {
			foreach ( (array) glob( trailingslashit( $legacy_dir ) . '*' ) as $file ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $legacy_dir );
		}
	}

	/**
	 * Delete removes rows and reports the deleted count with no backup path.
	 */
	public function test_delete_removes_rows(): void {
		add_option( 'to_delete_a', 'alpha', '', 'no' );
		add_option( 'to_delete_b', 'beta', '', 'no' );

		$result = Cleaner::delete( array( 'to_delete_a', 'to_delete_b' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertArrayNotHasKey( 'backup_path', $result );

		$this->assertFalse( get_option( 'to_delete_a', false ) );
		$this->assertFalse( get_option( 'to_delete_b', false ) );
	}

	/**
	 * Core options are skipped and recorded as errors.
	 */
	public function test_delete_skips_core_options(): void {
		$result = Cleaner::delete( array( 'siteurl' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertArrayNotHasKey( 'backup_path', $result );
	}

	/**
	 * Tracking rows are cleared alongside the options row.
	 */
	public function test_delete_also_clears_tracking_row(): void {
		add_option( 'delete_with_tracking', 'x', '', 'no' );

		global $wpdb;
		$table = Schema::tracking_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'option_name'  => 'delete_with_tracking',
				'last_read_at' => '2026-01-01 00:00:00',
				'read_count'   => 5,
				'last_reader'  => 'x',
				'reader_type'  => 'plugin',
				'first_seen'   => '2026-01-01 00:00:00',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		Cleaner::delete( array( 'delete_with_tracking' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE option_name = %s", 'delete_with_tracking' )
		);
		// phpcs:enable
		$this->assertSame( 0, $count );
	}

	/**
	 * Deletion never creates the legacy wp-content/optrion-backups/ directory.
	 * The plugin promises not to persist option_value content on disk.
	 */
	public function test_delete_does_not_create_backup_directory(): void {
		add_option( 'secret_option', 'api-key-value', '', 'no' );
		Cleaner::delete( array( 'secret_option' ) );

		$this->assertDirectoryDoesNotExist( trailingslashit( WP_CONTENT_DIR ) . 'optrion-backups' );
	}
}
