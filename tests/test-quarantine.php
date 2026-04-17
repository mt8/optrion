<?php
/**
 * Quarantine module tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Quarantine;
use Optrion\Schema;
use WP_UnitTestCase;

/**
 * Exercises the rename / restore / delete / expiry flows.
 *
 * @coversDefaultClass \Optrion\Quarantine
 */
class QuarantineTest extends WP_UnitTestCase {

	/**
	 * Ensures the schema is in place and the manifest is empty for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
		global $wpdb;
		// phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::quarantine_table() );
	}

	/**
	 * Eligibility rejects core options, empty names, already-prefixed names, and oversized names.
	 */
	public function test_is_quarantinable_rules(): void {
		$this->assertFalse( Quarantine::is_quarantinable( '' ) );
		$this->assertFalse( Quarantine::is_quarantinable( 'siteurl' ) );
		$this->assertFalse( Quarantine::is_quarantinable( Quarantine::RENAME_PREFIX . 'foo' ) );
		$this->assertFalse( Quarantine::is_quarantinable( str_repeat( 'x', Quarantine::MAX_ORIGINAL_LENGTH + 1 ) ) );
		$this->assertTrue( Quarantine::is_quarantinable( 'my_custom_plugin_setting' ) );
	}

	/**
	 * Quarantining renames the row and inserts a manifest entry.
	 */
	public function test_quarantine_renames_and_records_manifest(): void {
		add_option( 'orphan_plugin_setting', 'hello', '', 'yes' );
		$id = Quarantine::quarantine( 'orphan_plugin_setting' );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$original = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'orphan_plugin_setting' )
		);
		$renamed  = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Quarantine::RENAME_PREFIX . 'orphan_plugin_setting' )
		);
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Quarantine::RENAME_PREFIX . 'orphan_plugin_setting' )
		);
		// phpcs:enable
		$this->assertNull( $original, 'Original row should be renamed away.' );
		$this->assertSame( 'hello', $renamed );
		$this->assertSame( 'no', $autoload );

		$manifest = Quarantine::get_manifest( $id );
		$this->assertNotNull( $manifest );
		$this->assertSame( 'orphan_plugin_setting', $manifest['original_name'] );
		$this->assertSame( Quarantine::STATUS_ACTIVE, $manifest['status'] );
		$this->assertArrayNotHasKey( 'score_at_quarantine', $manifest );
	}

	/**
	 * Core options cannot be quarantined.
	 */
	public function test_quarantine_rejects_core_options(): void {
		$result = Quarantine::quarantine( 'siteurl' );
		$this->assertWPError( $result );
		$this->assertSame( 'optrion_not_quarantinable', $result->get_error_code() );
	}

	/**
	 * Missing options surface a dedicated error.
	 */
	public function test_quarantine_rejects_missing_option(): void {
		$result = Quarantine::quarantine( 'nonexistent_option_xyz' );
		$this->assertWPError( $result );
		$this->assertSame( 'optrion_option_missing', $result->get_error_code() );
	}

	/**
	 * Restore renames the row back and flips manifest status.
	 */
	public function test_restore_reverses_rename(): void {
		add_option( 'some_plugin_thing', 'payload', '', 'yes' );
		$id = Quarantine::quarantine( 'some_plugin_thing' );
		$this->assertIsInt( $id );

		$result = Quarantine::restore( $id );
		$this->assertTrue( $result );

		$this->assertSame( 'payload', get_option( 'some_plugin_thing' ) );
		$manifest = Quarantine::get_manifest( $id );
		$this->assertSame( Quarantine::STATUS_RESTORED, $manifest['status'] );
		$this->assertNotEmpty( $manifest['restored_at'] );
	}

	/**
	 * Restore preserves the original autoload value.
	 */
	public function test_restore_preserves_original_autoload(): void {
		add_option( 'auto_thing', 'val', '', 'yes' );
		global $wpdb;
		// Capture whatever autoload value WP normalized 'yes' into (WP 6.6+ uses 'on').
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'auto_thing' )
		);
		// phpcs:enable

		$id = Quarantine::quarantine( 'auto_thing' );
		Quarantine::restore( (int) $id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'auto_thing' )
		);
		// phpcs:enable
		$this->assertSame( $before, $after );
	}

	/**
	 * Permanent delete drops the row and flips manifest status.
	 */
	public function test_delete_permanently_removes_row(): void {
		add_option( 'tempy', 'x', '', 'no' );
		$id = Quarantine::quarantine( 'tempy' );
		$this->assertIsInt( $id );

		$result = Quarantine::delete_permanently( $id );
		$this->assertTrue( $result );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", Quarantine::RENAME_PREFIX . 'tempy' )
		);
		$this->assertNull( $found );
		$manifest = Quarantine::get_manifest( $id );
		$this->assertSame( Quarantine::STATUS_DELETED, $manifest['status'] );
		$this->assertNotEmpty( $manifest['deleted_at'] );
	}

	/**
	 * Restore and permanent-delete refuse to act on non-active entries.
	 */
	public function test_restore_fails_if_not_active(): void {
		add_option( 'repeatable', 'a', '', 'no' );
		$id = Quarantine::quarantine( 'repeatable' );
		Quarantine::restore( (int) $id );

		$second = Quarantine::restore( (int) $id );
		$this->assertWPError( $second );
		$this->assertSame( 'optrion_not_active', $second->get_error_code() );
	}

	/**
	 * Active count reflects the number of STATUS_ACTIVE entries.
	 */
	public function test_active_count_tracks_status(): void {
		add_option( 'q_one', '1', '', 'no' );
		add_option( 'q_two', '2', '', 'no' );
		$this->assertSame( 0, Quarantine::active_count() );
		Quarantine::quarantine( 'q_one' );
		$this->assertSame( 1, Quarantine::active_count() );
		$id2 = Quarantine::quarantine( 'q_two' );
		$this->assertSame( 2, Quarantine::active_count() );
		Quarantine::restore( (int) $id2 );
		$this->assertSame( 1, Quarantine::active_count() );
	}

	/**
	 * Expiry sweep restores entries whose expires_at is in the past.
	 */
	public function test_process_expired_restores_by_default(): void {
		add_option( 'expiring_a', 'alpha', '', 'no' );
		$id = Quarantine::quarantine( 'expiring_a' );
		$this->assertIsInt( $id );

		// Force the manifest row to look overdue.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::quarantine_table(),
			array( 'expires_at' => '2020-01-01 00:00:00' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		update_option( Quarantine::EXPIRY_ACTION_OPTION, 'restore' );
		$summary = Quarantine::process_expired();
		$this->assertSame( 1, $summary['restored'] );
		$this->assertSame( 'alpha', get_option( 'expiring_a' ) );
	}

	/**
	 * Expiry sweep can auto-delete when configured.
	 */
	public function test_process_expired_deletes_when_configured(): void {
		add_option( 'expiring_b', 'beta', '', 'no' );
		$id = Quarantine::quarantine( 'expiring_b' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::quarantine_table(),
			array( 'expires_at' => '2020-01-01 00:00:00' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		update_option( Quarantine::EXPIRY_ACTION_OPTION, 'delete' );
		$summary = Quarantine::process_expired();
		$this->assertSame( 1, $summary['deleted'] );

		$manifest = Quarantine::get_manifest( (int) $id );
		$this->assertSame( Quarantine::STATUS_DELETED, $manifest['status'] );
	}

	/**
	 * Expiry sweep is a no-op when expiry action is "keep".
	 */
	public function test_process_expired_keeps_when_configured(): void {
		update_option( Quarantine::EXPIRY_ACTION_OPTION, 'keep' );
		$summary = Quarantine::process_expired();
		$this->assertSame( 0, $summary['processed'] );
	}

	/**
	 * Configured expiry days are clamped to 1-30.
	 */
	public function test_configured_expiry_days_is_clamped(): void {
		update_option( Quarantine::EXPIRY_DAYS_OPTION, 0 );
		$this->assertSame( 1, Quarantine::configured_expiry_days() );
		update_option( Quarantine::EXPIRY_DAYS_OPTION, 100 );
		$this->assertSame( 30, Quarantine::configured_expiry_days() );
		update_option( Quarantine::EXPIRY_DAYS_OPTION, 14 );
		$this->assertSame( 14, Quarantine::configured_expiry_days() );
	}
}
