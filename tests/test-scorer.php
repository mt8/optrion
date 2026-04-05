<?php
/**
 * Scorer tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use DateTimeImmutable;
use Optrion\Scorer;
use WP_UnitTestCase;

/**
 * Covers the five scoring axes, owner inference, and label banding.
 *
 * @coversDefaultClass \Optrion\Scorer
 */
class ScorerTest extends WP_UnitTestCase {

	/**
	 * Fixed "now" used by every test for deterministic freshness math.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Minimal synthetic site context.
	 *
	 * @var array{active_plugin_slugs:string[],installed_plugin_slugs:string[],active_theme_slugs:string[],installed_theme_slugs:string[]}
	 */
	private array $context;

	/**
	 * Sets a fixed clock and a small set of known plugins/themes.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->now     = new DateTimeImmutable( '2026-06-01 12:00:00' );
		$this->context = array(
			'active_plugin_slugs'    => array( 'woocommerce' ),
			'installed_plugin_slugs' => array( 'woocommerce', 'old-plugin' ),
			'active_theme_slugs'     => array( 'twentytwentyfour' ),
			'installed_theme_slugs'  => array( 'twentytwentyfour', 'oldtheme' ),
			'plugin_names'           => array(
				'woocommerce' => 'WooCommerce',
				'old-plugin'  => 'Old Plugin',
			),
			'theme_names'            => array(
				'twentytwentyfour' => 'Twenty Twenty-Four',
				'oldtheme'         => 'Old Theme',
			),
		);
	}

	/**
	 * Label banding matches the design doc thresholds.
	 */
	public function test_label_for_bands(): void {
		$this->assertSame( Scorer::LABEL_SAFE, Scorer::label_for( 0 ) );
		$this->assertSame( Scorer::LABEL_SAFE, Scorer::label_for( 19 ) );
		$this->assertSame( Scorer::LABEL_REVIEW, Scorer::label_for( 20 ) );
		$this->assertSame( Scorer::LABEL_REVIEW, Scorer::label_for( 49 ) );
		$this->assertSame( Scorer::LABEL_LIKELY_UNUSED, Scorer::label_for( 50 ) );
		$this->assertSame( Scorer::LABEL_LIKELY_UNUSED, Scorer::label_for( 79 ) );
		$this->assertSame( Scorer::LABEL_ALMOST_UNUSED, Scorer::label_for( 80 ) );
		$this->assertSame( Scorer::LABEL_ALMOST_UNUSED, Scorer::label_for( 100 ) );
	}

	/**
	 * A core option with recent reads and small size scores zero.
	 */
	public function test_core_option_scores_zero(): void {
		$option   = array(
			'option_name' => 'siteurl',
			'size_bytes'  => 20,
			'autoload'    => 'yes',
		);
		$tracking = array(
			'last_read_at' => '2026-06-01 10:00:00',
			'read_count'   => 10,
			'last_reader'  => 'wordpress',
			'reader_type'  => 'core',
		);
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( Scorer::OWNER_TYPE_CORE, $result['owner']['type'] );
		$this->assertSame( Scorer::LABEL_SAFE, $result['label'] );
	}

	/**
	 * An option whose plugin owner is inactive accrues the 40-point owner penalty.
	 */
	public function test_inactive_plugin_owner_scores_40_on_owner_axis(): void {
		$option   = array(
			'option_name' => 'old_plugin_settings',
			'size_bytes'  => 100,
			'autoload'    => 'no',
		);
		$tracking = null; // Never tracked.
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( 40, $result['breakdown']['owner'] );
		$this->assertSame( Scorer::OWNER_TYPE_PLUGIN, $result['owner']['type'] );
		$this->assertSame( 'old-plugin', $result['owner']['slug'] );
	}

	/**
	 * Freshness scoring: no record = 25.
	 */
	public function test_freshness_missing_record_is_25(): void {
		$option = array(
			'option_name' => 'foo_bar',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$result = Scorer::score( $option, null, $this->context, $this->now );
		$this->assertSame( 25, $result['breakdown']['freshness'] );
	}

	/**
	 * Freshness scoring: <90 days is 0.
	 */
	public function test_freshness_recent_is_zero(): void {
		$option   = array(
			'option_name' => 'foo_bar',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$tracking = array(
			'last_read_at' => '2026-04-01 00:00:00', // ~60 days before 2026-06-01
			'read_count'   => 1,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( 0, $result['breakdown']['freshness'] );
	}

	/**
	 * Freshness scoring scales at 5 pts per extra 30 days after 90, capped at 25.
	 */
	public function test_freshness_scales_with_age(): void {
		$option = array(
			'option_name' => 'foo_bar',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);

		// Exactly 90 days old → 5 points.
		$tracking_90 = array(
			'last_read_at' => '2026-03-03 12:00:00',
			'read_count'   => 1,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$this->assertSame( 5, Scorer::score( $option, $tracking_90, $this->context, $this->now )['breakdown']['freshness'] );

		// 120 days old → 10.
		$tracking_120 = array(
			'last_read_at' => '2026-02-01 12:00:00',
			'read_count'   => 1,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$this->assertSame( 10, Scorer::score( $option, $tracking_120, $this->context, $this->now )['breakdown']['freshness'] );

		// 2 years old → capped at 25.
		$tracking_old = array(
			'last_read_at' => '2024-01-01 00:00:00',
			'read_count'   => 1,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$this->assertSame( 25, Scorer::score( $option, $tracking_old, $this->context, $this->now )['breakdown']['freshness'] );
	}

	/**
	 * Transient prefix adds 10 points.
	 */
	public function test_transient_prefix_adds_10(): void {
		$option = array(
			'option_name' => '_transient_some_cache',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$result = Scorer::score( $option, null, $this->context, $this->now );
		$this->assertSame( 10, $result['breakdown']['transient'] );

		$option['option_name'] = '_site_transient_x';
		$result                = Scorer::score( $option, null, $this->context, $this->now );
		$this->assertSame( 10, $result['breakdown']['transient'] );
	}

	/**
	 * Autoload + zero reads = 15 point waste penalty.
	 */
	public function test_autoload_waste_fires_only_when_autoloaded_and_unread(): void {
		$option          = array(
			'option_name' => 'something',
			'size_bytes'  => 10,
			'autoload'    => 'yes',
		);
		$tracking_unread = array(
			'last_read_at' => null,
			'read_count'   => 0,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$result          = Scorer::score( $option, $tracking_unread, $this->context, $this->now );
		$this->assertSame( 15, $result['breakdown']['autoload_waste'] );

		$tracking_read = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 3,
			'last_reader'  => '',
			'reader_type'  => 'unknown',
		);
		$result        = Scorer::score( $option, $tracking_read, $this->context, $this->now );
		$this->assertSame( 0, $result['breakdown']['autoload_waste'] );

		$option['autoload'] = 'no';
		$result             = Scorer::score( $option, $tracking_unread, $this->context, $this->now );
		$this->assertSame( 0, $result['breakdown']['autoload_waste'] );
	}

	/**
	 * Size axis: >100KB=10, >10KB=5, else 0.
	 */
	public function test_size_axis_thresholds(): void {
		$base = array(
			'option_name' => 'x',
			'autoload'    => 'no',
		);
		$this->assertSame( 0, Scorer::score( array_merge( $base, array( 'size_bytes' => 5000 ) ), null, $this->context, $this->now )['breakdown']['size'] );
		$this->assertSame( 5, Scorer::score( array_merge( $base, array( 'size_bytes' => 20 * 1024 ) ), null, $this->context, $this->now )['breakdown']['size'] );
		$this->assertSame( 10, Scorer::score( array_merge( $base, array( 'size_bytes' => 200 * 1024 ) ), null, $this->context, $this->now )['breakdown']['size'] );
	}

	/**
	 * Totals accumulate to the design maximum of 100.
	 */
	public function test_total_accumulates_to_100(): void {
		$option = array(
			'option_name' => '_transient_old_plugin_huge_blob',
			'size_bytes'  => 500 * 1024,
			'autoload'    => 'yes',
		);
		// Use tracker-sourced owner so the transient prefix does not block attribution.
		$tracking = array(
			'last_read_at' => null,
			'read_count'   => 0,
			'last_reader'  => 'old-plugin',
			'reader_type'  => 'plugin',
		);
		// Owner(inactive plugin)=40 + freshness(no record)=25 + transient=10 + autoload_waste=15 + size(>100KB)=10 = 100.
		$result = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertLessThanOrEqual( 100, $result['total'] );
		$this->assertSame( 100, $result['total'] );
	}

	/**
	 * Owner inference prefers a plugin-slug prefix match over tracker data,
	 * because "who read it last" is a noisier signal than the option name
	 * itself. For options whose name does not match any installed plugin,
	 * tracker data with a concrete plugin caller is still used — see
	 * {@see self::test_owner_inference_falls_back_to_tracker_caller()}.
	 */
	public function test_owner_inference_prefers_prefix_over_tracker(): void {
		$option   = array(
			'option_name' => 'woocommerce_settings',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 3,
			'last_reader'  => 'custom-mu',
			'reader_type'  => 'plugin',
		);
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( Scorer::OWNER_TYPE_PLUGIN, $result['owner']['type'] );
		$this->assertSame( 'woocommerce', $result['owner']['slug'] );
	}

	/**
	 * When no prefix / registry rule matches, a concrete plugin/theme caller
	 * in the tracker record is used to attribute ownership.
	 */
	public function test_owner_inference_falls_back_to_tracker_caller(): void {
		$option   = array(
			'option_name' => 'opaque_blob_42',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 3,
			'last_reader'  => 'my-analytics',
			'reader_type'  => 'plugin',
		);
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( Scorer::OWNER_TYPE_PLUGIN, $result['owner']['type'] );
		$this->assertSame( 'my-analytics', $result['owner']['slug'] );
	}

	/**
	 * Tracker records with reader_type=core provide no information about
	 * ownership (WordPress core reads every autoloaded option). Such rows
	 * must not promote an option to owner=core.
	 */
	public function test_owner_inference_ignores_core_tracker_type(): void {
		$option   = array(
			'option_name' => 'opaque_blob_42',
			'size_bytes'  => 10,
			'autoload'    => 'yes',
		);
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 50,
			'last_reader'  => 'wordpress',
			'reader_type'  => 'core',
		);
		$result   = Scorer::score( $option, $tracking, $this->context, $this->now );
		$this->assertSame( Scorer::OWNER_TYPE_UNKNOWN, $result['owner']['type'] );
	}

	/**
	 * Resolve_owner_name returns the human-readable name from plugin/theme metadata.
	 */
	public function test_resolve_owner_name_from_metadata(): void {
		$this->assertSame(
			'WooCommerce',
			Scorer::resolve_owner_name(
				array(
					'type' => 'plugin',
					'slug' => 'woocommerce',
				),
				$this->context
			)
		);
		$this->assertSame(
			'Twenty Twenty-Four',
			Scorer::resolve_owner_name(
				array(
					'type' => 'theme',
					'slug' => 'twentytwentyfour',
				),
				$this->context
			)
		);
		$this->assertSame(
			'WordPress',
			Scorer::resolve_owner_name(
				array(
					'type' => 'core',
					'slug' => 'wordpress',
				),
				$this->context
			)
		);
		$this->assertSame(
			'',
			Scorer::resolve_owner_name(
				array(
					'type' => 'unknown',
					'slug' => '',
				),
				$this->context
			)
		);
	}

	/**
	 * Owner inference falls back to prefix matching when tracker is unknown.
	 */
	public function test_owner_inference_uses_prefix_match(): void {
		$option = array(
			'option_name' => 'woocommerce_settings',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$result = Scorer::score( $option, null, $this->context, $this->now );
		$this->assertSame( Scorer::OWNER_TYPE_PLUGIN, $result['owner']['type'] );
		$this->assertSame( 'woocommerce', $result['owner']['slug'] );
	}

	/**
	 * Unknown owner gets 20 on the owner axis.
	 */
	public function test_owner_inference_defaults_to_unknown(): void {
		$option = array(
			'option_name' => 'random_mystery_thing',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$result = Scorer::score( $option, null, $this->context, $this->now );
		$this->assertSame( Scorer::OWNER_TYPE_UNKNOWN, $result['owner']['type'] );
		$this->assertSame( 20, $result['breakdown']['owner'] );
	}

	/**
	 * Prefix matching treats dash/underscore interchangeably.
	 */
	public function test_prefix_match_handles_dash_underscore(): void {
		$context                           = $this->context;
		$context['installed_plugin_slugs'] = array_merge( $context['installed_plugin_slugs'], array( 'my-cool-plugin' ) );
		$option                            = array(
			'option_name' => 'my_cool_plugin_settings',
			'size_bytes'  => 10,
			'autoload'    => 'no',
		);
		$result                            = Scorer::score( $option, null, $context, $this->now );
		$this->assertSame( 'my-cool-plugin', $result['owner']['slug'] );
	}

	/**
	 * Context gathered from a live WP install returns the expected shape.
	 */
	public function test_build_context_returns_expected_shape(): void {
		$ctx = Scorer::build_context();
		$this->assertArrayHasKey( 'active_plugin_slugs', $ctx );
		$this->assertArrayHasKey( 'installed_plugin_slugs', $ctx );
		$this->assertArrayHasKey( 'active_theme_slugs', $ctx );
		$this->assertArrayHasKey( 'installed_theme_slugs', $ctx );
		$this->assertIsArray( $ctx['active_plugin_slugs'] );
	}
}
