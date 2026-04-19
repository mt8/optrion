<?php
/**
 * Export / Import module tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Exporter;
use Optrion\Importer;
use Optrion\Quarantine;
use Optrion\Schema;
use WP_UnitTestCase;

/**
 * Round-trips export → import, and covers dry-run and overwrite semantics.
 *
 * @coversDefaultClass \Optrion\Exporter
 */
class ExporterImporterTest extends WP_UnitTestCase {

	/**
	 * Schema is required for tracking lookups.
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/**
	 * The export payload includes the canonical envelope fields.
	 */
	public function test_export_envelope_fields(): void {
		add_option( 'opt_a', 'alpha', '', 'no' );

		$export = Exporter::build_export( array( 'opt_a' ) );
		$this->assertSame( Exporter::FORMAT_VERSION, $export['version'] );
		$this->assertNotEmpty( $export['exported_at'] );
		$this->assertSame( home_url(), $export['site_url'] );
		$this->assertSame( get_bloginfo( 'version' ), $export['wp_version'] );
		$this->assertCount( 1, $export['options'] );
	}

	/**
	 * Each exported option carries name/value/autoload/tracking and no score object.
	 */
	public function test_export_option_shape(): void {
		add_option( 'opt_b', 'beta', '', 'no' );
		$export = Exporter::build_export( array( 'opt_b' ) );
		$entry  = $export['options'][0];
		$this->assertSame( 'opt_b', $entry['option_name'] );
		$this->assertSame( 'beta', $entry['option_value'] );
		$this->assertArrayHasKey( 'autoload', $entry );
		$this->assertArrayHasKey( 'tracking', $entry );
		$this->assertArrayNotHasKey( 'score', $entry );
	}

	/**
	 * Legacy 1.0.0 export payloads (with a score object) import cleanly —
	 * the importer ignores fields it does not care about.
	 */
	public function test_import_accepts_legacy_score_payload(): void {
		$json   = (string) wp_json_encode(
			array(
				'version' => '1.0.0',
				'options' => array(
					array(
						'option_name'  => 'legacy_opt',
						'option_value' => 'carried',
						'autoload'     => 'no',
						'score'        => array(
							'total'     => 42,
							'label'     => 'review',
							'breakdown' => array(),
						),
					),
				),
			)
		);
		$result = Importer::import( $json, false );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 'carried', get_option( 'legacy_opt' ) );
	}

	/**
	 * Non-existent option names are silently skipped.
	 */
	public function test_export_skips_unknown_names(): void {
		$export = Exporter::build_export( array( 'never_existed_xyz' ) );
		$this->assertCount( 0, $export['options'] );
	}

	/**
	 * Suggested filename is deterministic for a given site URL and current clock.
	 */
	public function test_suggested_filename_shape(): void {
		$name = Exporter::suggested_filename( 'https://example.com/blog' );
		$this->assertStringContainsString( 'example.com', $name );
		$this->assertStringStartsWith( 'optrion-export-', $name );
		$this->assertStringEndsWith( '.json', $name );
	}

	/**
	 * Dry-run counts inserts vs overwrites without touching the DB.
	 */
	public function test_dry_run_counts(): void {
		add_option( 'existing_opt', 'x', '', 'no' );
		$json   = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'existing_opt',
						'option_value' => 'new',
						'autoload'     => 'no',
					),
					array(
						'option_name'  => 'brand_new_opt',
						'option_value' => 'y',
						'autoload'     => 'no',
					),
				),
			)
		);
		$result = Importer::dry_run( $json );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['add'] );
		$this->assertSame( 1, $result['overwrite'] );

		// Dry-run does not mutate the DB.
		$this->assertSame( 'x', get_option( 'existing_opt' ) );
		$this->assertFalse( get_option( 'brand_new_opt', false ) );
	}

	/**
	 * Import inserts missing rows and skips existing ones by default.
	 */
	public function test_import_inserts_and_skips_existing(): void {
		add_option( 'keep_me', 'original', '', 'no' );
		$json   = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'keep_me',
						'option_value' => 'incoming',
						'autoload'     => 'no',
					),
					array(
						'option_name'  => 'fresh_opt',
						'option_value' => 'hello',
						'autoload'     => 'no',
					),
				),
			)
		);
		$result = Importer::import( $json, false );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'original', get_option( 'keep_me' ) );
		$this->assertSame( 'hello', get_option( 'fresh_opt' ) );
	}

	/**
	 * With overwrite=true, existing rows are replaced.
	 */
	public function test_import_overwrites_when_flag_set(): void {
		add_option( 'overwriteable', 'before', '', 'no' );
		$json   = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'overwriteable',
						'option_value' => 'after',
						'autoload'     => 'no',
					),
				),
			)
		);
		$result = Importer::import( $json, true );
		$this->assertSame( 1, $result['overwritten'] );
		$this->assertSame( 'after', get_option( 'overwriteable' ) );
	}

	/**
	 * Malformed JSON surfaces a WP_Error.
	 */
	public function test_invalid_json_returns_error(): void {
		$result = Importer::dry_run( 'not json' );
		$this->assertWPError( $result );
	}

	/**
	 * Missing options list surfaces a WP_Error.
	 */
	public function test_invalid_payload_returns_error(): void {
		$result = Importer::import( (string) wp_json_encode( array( 'version' => '1.0.0' ) ), false );
		$this->assertWPError( $result );
	}

	/**
	 * Core options are protected from import in both modes and on both flags.
	 */
	public function test_import_skips_core_options(): void {
		update_option( 'blogname', 'legitimate-site' );
		$json = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'blogname',
						'option_value' => 'attacker-site',
						'autoload'     => 'yes',
					),
				),
			)
		);

		$preview = Importer::dry_run( $json );
		$this->assertSame( 1, $preview['skip'] );
		$this->assertSame( 0, $preview['add'] );
		$this->assertSame( 0, $preview['overwrite'] );
		$this->assertNotEmpty( $preview['errors'] );

		$result = Importer::import( $json, true );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['overwritten'] );
		$this->assertSame( 'legitimate-site', get_option( 'blogname' ) );
	}

	/**
	 * Optrion's own internal namespace (`optrion_*`) is not importable.
	 */
	public function test_import_skips_optrion_internal_namespace(): void {
		$json = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'optrion_db_version',
						'option_value' => '999',
						'autoload'     => 'no',
					),
				),
			)
		);

		$result = Importer::import( $json, true );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['added'] );
		$this->assertSame( 0, $result['overwritten'] );
	}

	/**
	 * The quarantine rename namespace is owned by the manifest table and is
	 * not importable.
	 */
	public function test_import_skips_quarantine_rename_namespace(): void {
		$name = Quarantine::RENAME_PREFIX . 'something';
		$json = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => $name,
						'option_value' => 'injected',
						'autoload'     => 'no',
					),
				),
			)
		);

		$result = Importer::import( $json, false );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['added'] );
		$this->assertFalse( get_option( $name, false ) );
	}

	/**
	 * A mixed payload imports the third-party row and skips the protected ones.
	 */
	public function test_import_mixed_payload_imports_only_safe_entries(): void {
		$json = (string) wp_json_encode(
			array(
				'options' => array(
					array(
						'option_name'  => 'third_party_opt',
						'option_value' => 'kept',
						'autoload'     => 'no',
					),
					array(
						'option_name'  => 'active_plugins',
						'option_value' => 'a:0:{}',
						'autoload'     => 'yes',
					),
					array(
						'option_name'  => 'optrion_sampling_rate',
						'option_value' => '1',
						'autoload'     => 'no',
					),
					array(
						'option_name'  => Quarantine::RENAME_PREFIX . 'foo',
						'option_value' => 'x',
						'autoload'     => 'no',
					),
				),
			)
		);

		$result = Importer::import( $json, true );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 3, $result['skipped'] );
		$this->assertSame( 'kept', get_option( 'third_party_opt' ) );
	}

	/**
	 * Round-trip: export, delete, import restores identical values.
	 */
	public function test_round_trip_export_import(): void {
		add_option( 'round_trip', array( 'a', 'b', 'c' ), '', 'no' );
		$json = Exporter::to_json( array( 'round_trip' ) );
		delete_option( 'round_trip' );

		$this->assertFalse( get_option( 'round_trip', false ) );
		$result = Importer::import( $json, false );
		$this->assertSame( 1, $result['added'] );

		// Verify the raw row exists in wp_options.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'round_trip' )
		);
		// phpcs:enable
		$this->assertNotNull( $raw, 'Row should exist in wp_options after import.' );
		$this->assertSame( array( 'a', 'b', 'c' ), maybe_unserialize( $raw ) );

		// Clear object cache fully and then query via get_option.
		wp_cache_flush();
		$this->assertSame( array( 'a', 'b', 'c' ), get_option( 'round_trip' ) );
	}
}
