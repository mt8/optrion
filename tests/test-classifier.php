<?php
/**
 * Classifier tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\Classifier;
use WP_UnitTestCase;

/**
 * Covers accessor inference, active-flag resolution, and display-name lookup.
 *
 * @coversDefaultClass \Optrion\Classifier
 */
class ClassifierTest extends WP_UnitTestCase {

	/**
	 * Minimal synthetic site context shared across tests.
	 *
	 * @var array{active_plugin_slugs:string[],installed_plugin_slugs:string[],active_theme_slugs:string[],installed_theme_slugs:string[],plugin_names:array<string,string>,theme_names:array<string,string>}
	 */
	private array $context;

	/**
	 * Sets up a small set of known plugins/themes.
	 */
	public function set_up(): void {
		parent::set_up();
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
	 * Core options on the curated registry map to the `core` accessor type.
	 */
	public function test_core_option_is_classified_as_core(): void {
		$accessor = Classifier::infer_accessor( 'siteurl', null, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_CORE, $accessor['type'] );
		$this->assertSame( 'wordpress', $accessor['slug'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
	}

	/**
	 * `widget_*` option names resolve to the `widget` type with the widget id as slug.
	 */
	public function test_widget_prefix_is_classified_as_widget(): void {
		$accessor = Classifier::infer_accessor( 'widget_recent-posts', null, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_WIDGET, $accessor['type'] );
		$this->assertSame( 'recent-posts', $accessor['slug'] );
	}

	/**
	 * Tracker backtrace data takes precedence over prefix matching.
	 *
	 * Essential for plugins whose slug differs from the option prefix
	 * (e.g. Yoast SEO uses `wpseo` options but lives in `wordpress-seo/`).
	 */
	public function test_accessor_inference_prefers_tracker_over_prefix(): void {
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 3,
			'last_reader'  => 'custom-mu',
			'reader_type'  => 'plugin',
		);
		$accessor = Classifier::infer_accessor( 'woocommerce_settings', $tracking, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_PLUGIN, $accessor['type'] );
		$this->assertSame( 'custom-mu', $accessor['slug'] );
	}

	/**
	 * Tracker data with a concrete plugin caller attributes the option when no
	 * prefix or registry rule would match.
	 */
	public function test_accessor_inference_uses_tracker_caller(): void {
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 3,
			'last_reader'  => 'my-analytics',
			'reader_type'  => 'plugin',
		);
		$accessor = Classifier::infer_accessor( 'opaque_blob_42', $tracking, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_PLUGIN, $accessor['type'] );
		$this->assertSame( 'my-analytics', $accessor['slug'] );
	}

	/**
	 * Tracker rows with reader_type=core do not promote the accessor to core:
	 * WordPress core reads every autoloaded option, so that signal carries no
	 * information about true ownership.
	 */
	public function test_accessor_inference_ignores_core_tracker_type(): void {
		$tracking = array(
			'last_read_at' => '2026-05-01 00:00:00',
			'read_count'   => 50,
			'last_reader'  => 'wordpress',
			'reader_type'  => 'core',
		);
		$accessor = Classifier::infer_accessor( 'opaque_blob_42', $tracking, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_UNKNOWN, $accessor['type'] );
	}

	/**
	 * Accessor inference falls back to prefix matching when no tracker data is present.
	 */
	public function test_accessor_inference_falls_back_to_prefix(): void {
		$accessor = Classifier::infer_accessor( 'woocommerce_settings', null, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_PLUGIN, $accessor['type'] );
		$this->assertSame( 'woocommerce', $accessor['slug'] );
	}

	/**
	 * When nothing matches, the accessor is `unknown` with an empty slug.
	 */
	public function test_accessor_inference_defaults_to_unknown(): void {
		$accessor = Classifier::infer_accessor( 'random_mystery_thing', null, $this->context );
		$this->assertSame( Classifier::ACCESSOR_TYPE_UNKNOWN, $accessor['type'] );
		$this->assertSame( '', $accessor['slug'] );
	}

	/**
	 * Prefix matching treats dash and underscore interchangeably.
	 */
	public function test_prefix_match_handles_dash_underscore(): void {
		$context                           = $this->context;
		$context['installed_plugin_slugs'] = array_merge( $context['installed_plugin_slugs'], array( 'my-cool-plugin' ) );
		$accessor                          = Classifier::infer_accessor( 'my_cool_plugin_settings', null, $context );
		$this->assertSame( 'my-cool-plugin', $accessor['slug'] );
	}

	/**
	 * Accessor_is_active returns true for an active plugin and false for an inactive one.
	 */
	public function test_accessor_is_active_for_plugin(): void {
		$this->assertTrue(
			Classifier::accessor_is_active(
				array(
					'type' => 'plugin',
					'slug' => 'woocommerce',
				),
				$this->context
			)
		);
		$this->assertFalse(
			Classifier::accessor_is_active(
				array(
					'type' => 'plugin',
					'slug' => 'old-plugin',
				),
				$this->context
			)
		);
	}

	/**
	 * Core and widget accessors are always considered active; unknown is not.
	 */
	public function test_accessor_is_active_for_core_widget_unknown(): void {
		$this->assertTrue(
			Classifier::accessor_is_active(
				array(
					'type' => 'core',
					'slug' => 'wordpress',
				),
				$this->context
			)
		);
		$this->assertTrue(
			Classifier::accessor_is_active(
				array(
					'type' => 'widget',
					'slug' => 'recent-posts',
				),
				$this->context
			)
		);
		$this->assertFalse(
			Classifier::accessor_is_active(
				array(
					'type' => 'unknown',
					'slug' => '',
				),
				$this->context
			)
		);
	}

	/**
	 * Resolve_accessor_name returns the human-readable name from plugin/theme metadata.
	 */
	public function test_resolve_accessor_name_from_metadata(): void {
		$this->assertSame(
			'WooCommerce',
			Classifier::resolve_accessor_name(
				array(
					'type' => 'plugin',
					'slug' => 'woocommerce',
				),
				$this->context
			)
		);
		$this->assertSame(
			'Twenty Twenty-Four',
			Classifier::resolve_accessor_name(
				array(
					'type' => 'theme',
					'slug' => 'twentytwentyfour',
				),
				$this->context
			)
		);
		$this->assertSame(
			'WordPress',
			Classifier::resolve_accessor_name(
				array(
					'type' => 'core',
					'slug' => 'wordpress',
				),
				$this->context
			)
		);
		$this->assertSame(
			'',
			Classifier::resolve_accessor_name(
				array(
					'type' => 'unknown',
					'slug' => '',
				),
				$this->context
			)
		);
	}

	/**
	 * Build_context gathered from a live WP install returns the expected shape.
	 */
	public function test_build_context_returns_expected_shape(): void {
		$ctx = Classifier::build_context();
		$this->assertArrayHasKey( 'active_plugin_slugs', $ctx );
		$this->assertArrayHasKey( 'installed_plugin_slugs', $ctx );
		$this->assertArrayHasKey( 'active_theme_slugs', $ctx );
		$this->assertArrayHasKey( 'installed_theme_slugs', $ctx );
		$this->assertIsArray( $ctx['active_plugin_slugs'] );
	}

	/**
	 * Is_autoloaded accepts both legacy and WP 6.6+ autoload column values.
	 */
	public function test_is_autoloaded_accepts_all_truthy_values(): void {
		$this->assertTrue( Classifier::is_autoloaded( 'yes' ) );
		$this->assertTrue( Classifier::is_autoloaded( 'on' ) );
		$this->assertTrue( Classifier::is_autoloaded( 'auto' ) );
		$this->assertTrue( Classifier::is_autoloaded( 'auto-on' ) );
		$this->assertFalse( Classifier::is_autoloaded( 'no' ) );
		$this->assertFalse( Classifier::is_autoloaded( 'off' ) );
		$this->assertFalse( Classifier::is_autoloaded( 'auto-off' ) );
	}
}
