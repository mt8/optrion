<?php
/**
 * Tracker module tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Schema;
use Optrion\Tracker;
use WP_UnitTestCase;

/**
 * Covers buffering, flushing, and caller classification.
 *
 * @coversDefaultClass \Optrion\Tracker
 */
class TrackerTest extends WP_UnitTestCase {

	/**
	 * Ensures request-local state does not bleed across tests.
	 */
	public function set_up(): void {
		parent::set_up();
		Tracker::reset_for_test();
		Schema::install();
		delete_transient( Tracker::ENABLE_TRANSIENT );
		delete_option( Tracker::SAMPLING_OPTION );
	}

	/**
	 * Reader residing under WP_PLUGIN_DIR is classified as a plugin.
	 */
	public function test_classify_trace_identifies_plugin_caller(): void {
		$trace  = array(
			array( 'file' => WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-cart.php' ),
		);
		$result = Tracker::classify_trace( $trace );
		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'woocommerce', $result['slug'] );
	}

	/**
	 * Reader residing under the theme root is classified as a theme.
	 */
	public function test_classify_trace_identifies_theme_caller(): void {
		$trace  = array(
			array( 'file' => get_theme_root() . '/twentytwentyfour/functions.php' ),
		);
		$result = Tracker::classify_trace( $trace );
		$this->assertSame( 'theme', $result['type'] );
		$this->assertSame( 'twentytwentyfour', $result['slug'] );
	}

	/**
	 * Frames inside the plugin itself are skipped when classifying.
	 */
	public function test_classify_trace_skips_optrion_frames(): void {
		$trace  = array(
			array( 'file' => OPTRION_DIR . 'includes/class-tracker.php' ),
			array( 'file' => WP_PLUGIN_DIR . '/jetpack/jetpack.php' ),
		);
		$result = Tracker::classify_trace( $trace );
		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'jetpack', $result['slug'] );
	}

	/**
	 * A trace with no recognized frames is attributed to core.
	 */
	public function test_classify_trace_defaults_to_core(): void {
		$trace  = array(
			array( 'file' => ABSPATH . 'wp-includes/option.php' ),
		);
		$result = Tracker::classify_trace( $trace );
		$this->assertSame( 'core', $result['type'] );
	}

	/**
	 * The tracker should skip when the activation transient is missing.
	 */
	public function test_should_track_returns_false_without_transient(): void {
		delete_transient( Tracker::ENABLE_TRANSIENT );
		$this->assertFalse( Tracker::should_track_this_request() );
	}

	/**
	 * Transient plus sampling=100 enables tracking.
	 */
	public function test_should_track_returns_true_when_enabled(): void {
		set_transient( Tracker::ENABLE_TRANSIENT, 1, 60 );
		update_option( Tracker::SAMPLING_OPTION, 100 );
		$this->assertTrue( Tracker::should_track_this_request() );
	}

	/**
	 * Sampling rate of 0 disables tracking entirely.
	 */
	public function test_sampling_zero_disables_tracking(): void {
		set_transient( Tracker::ENABLE_TRANSIENT, 1, 60 );
		update_option( Tracker::SAMPLING_OPTION, 0 );
		$this->assertFalse( Tracker::should_track_this_request() );
	}

	/**
	 * Repeated records for the same option name accumulate in a single row.
	 */
	public function test_buffer_dedupes_reads_per_option(): void {
		$reader = array(
			'type' => 'plugin',
			'slug' => 'demo',
		);
		Tracker::buffer_record( 'siteurl', $reader, '2026-04-05 00:00:00' );
		Tracker::buffer_record( 'siteurl', $reader, '2026-04-05 00:00:01' );
		Tracker::buffer_record( 'home', $reader, '2026-04-05 00:00:02' );

		$snapshot = Tracker::buffer_snapshot();
		$this->assertCount( 2, $snapshot );
		$this->assertSame( 2, $snapshot['siteurl']['count'] );
		$this->assertSame( '2026-04-05 00:00:01', $snapshot['siteurl']['last'] );
		$this->assertSame( 1, $snapshot['home']['count'] );
	}

	/**
	 * Flushing writes buffered reads into the tracking table as an upsert.
	 */
	public function test_flush_upserts_into_tracking_table(): void {
		global $wpdb;
		$reader = array(
			'type' => 'plugin',
			'slug' => 'demo-plugin',
		);
		Tracker::buffer_record( 'foo_option', $reader, '2026-04-05 00:00:00' );
		Tracker::buffer_record( 'foo_option', $reader, '2026-04-05 00:00:01' );
		Tracker::flush();

		$table = Schema::tracking_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE option_name = %s", 'foo_option' ),
			ARRAY_A
		);
		// phpcs:enable
		$this->assertNotNull( $row );
		$this->assertSame( '2', (string) $row['read_count'] );
		$this->assertSame( 'demo-plugin', $row['last_reader'] );
		$this->assertSame( 'plugin', $row['reader_type'] );

		// A second flush for the same option should accumulate count.
		Tracker::buffer_record( 'foo_option', $reader, '2026-04-05 00:00:05' );
		Tracker::flush();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT read_count FROM {$table} WHERE option_name = %s", 'foo_option' )
		);
		// phpcs:enable
		$this->assertSame( 3, $total );
	}

	/**
	 * Recording alloptions buffers every key in the array.
	 */
	public function test_record_alloptions_buffers_each_key(): void {
		$input  = array(
			'siteurl' => 'https://example.com',
			'home'    => 'https://example.com',
		);
		$result = Tracker::record_alloptions( $input );
		$this->assertSame( $input, $result );
		$snapshot = Tracker::buffer_snapshot();
		$this->assertArrayHasKey( 'siteurl', $snapshot );
		$this->assertArrayHasKey( 'home', $snapshot );
	}
}
