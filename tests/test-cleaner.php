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
 * Exercises delete + backup + retention.
 *
 * @coversDefaultClass \Optrion\Cleaner
 */
class CleanerTest extends WP_UnitTestCase {

	/**
	 * Ensures schema exists and the backup directory starts empty.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$dir = Cleaner::backup_dir();
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( trailingslashit( $dir ) . 'optrion-backup-*.json' ) as $file ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/**
	 * Deletes option rows and returns a backup path.
	 */
	public function test_delete_removes_rows_and_returns_backup_path(): void {
		add_option( 'to_delete_a', 'alpha', '', 'no' );
		add_option( 'to_delete_b', 'beta', '', 'no' );

		$result = Cleaner::delete( array( 'to_delete_a', 'to_delete_b' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertNotNull( $result['backup_path'] );
		$this->assertFileExists( $result['backup_path'] );

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
		// No backup when nothing deletable remains.
		$this->assertNull( $result['backup_path'] );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE option_name = %s", 'delete_with_tracking' )
		);
		// phpcs:enable
		$this->assertSame( 0, $count );
	}

	/**
	 * Only the most recent BACKUP_RETENTION files are retained.
	 */
	public function test_prune_old_backups_retains_last_n(): void {
		$dir = Cleaner::backup_dir();
		wp_mkdir_p( $dir );
		// Create 5 stale backup files with ascending timestamps in their names.
		$names = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$path = trailingslashit( $dir ) . sprintf( 'optrion-backup-2026010%d-000000.json', $i );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, '{}' );
			$names[] = $path;
		}

		Cleaner::prune_old_backups();

		$remaining = (array) glob( trailingslashit( $dir ) . 'optrion-backup-*.json' );
		$this->assertCount( Cleaner::BACKUP_RETENTION, $remaining );
		// The oldest two should be gone, newest three should remain.
		$this->assertFileDoesNotExist( $names[0] );
		$this->assertFileDoesNotExist( $names[1] );
		$this->assertFileExists( $names[2] );
		$this->assertFileExists( $names[3] );
		$this->assertFileExists( $names[4] );
	}

	/**
	 * Write_backup writes a JSON file containing the exported envelope.
	 */
	public function test_write_backup_emits_valid_json(): void {
		add_option( 'backup_me', 'value', '', 'no' );
		$path = Cleaner::write_backup( array( 'backup_me' ) );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		$decoded  = json_decode( $contents, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'options', $decoded );
		$this->assertCount( 1, $decoded['options'] );
		$this->assertSame( 'backup_me', $decoded['options'][0]['option_name'] );
	}
}
