<?php
/**
 * Core options registry tests.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion\Tests;

use Optrion\CoreOptions;
use WP_UnitTestCase;

/**
 * Verifies behavior of the known core options registry.
 *
 * @coversDefaultClass \Optrion\CoreOptions
 */
class CoreOptionsTest extends WP_UnitTestCase {

	/**
	 * The canonical list covers the essential core options.
	 */
	public function test_list_contains_essential_core_names(): void {
		$essentials = array(
			'siteurl',
			'home',
			'blogname',
			'active_plugins',
			'template',
			'stylesheet',
			'cron',
			'rewrite_rules',
			'permalink_structure',
			'db_version',
		);

		foreach ( $essentials as $name ) {
			$this->assertTrue( CoreOptions::contains( $name ), "Expected '{$name}' in core list." );
		}
	}

	/**
	 * Unknown option names are not flagged as core.
	 */
	public function test_contains_returns_false_for_plugin_options(): void {
		$this->assertFalse( CoreOptions::contains( 'woocommerce_settings' ) );
		$this->assertFalse( CoreOptions::contains( 'my_custom_plugin_setting' ) );
		$this->assertFalse( CoreOptions::contains( '' ) );
	}

	/**
	 * The list is non-trivial (guards against accidental truncation).
	 */
	public function test_list_has_expected_size(): void {
		$this->assertGreaterThanOrEqual( 60, count( CoreOptions::all() ) );
	}

	/**
	 * The list is deduplicated.
	 */
	public function test_list_is_unique(): void {
		$list = CoreOptions::all();
		$this->assertSame( count( $list ), count( array_unique( $list ) ) );
	}

	/**
	 * Matching follows the DB collation: case-insensitive and tolerant of
	 * trailing whitespace, so a non-canonical spelling does not slip through.
	 */
	public function test_contains_matches_case_and_trailing_space(): void {
		$this->assertTrue( CoreOptions::contains( 'SITEURL' ) );
		$this->assertTrue( CoreOptions::contains( 'SiteUrl' ) );
		$this->assertTrue( CoreOptions::contains( 'siteurl ' ) );
		$this->assertTrue( CoreOptions::contains( 'BlogName  ' ) );
	}

	/**
	 * Custom entries injected via filter are honored.
	 */
	public function test_filter_can_extend_list(): void {
		$filter = static function ( array $names ): array {
			$names[] = 'my_mu_plugin_sentinel';
			return $names;
		};
		add_filter( 'optrion_core_options', $filter );

		try {
			$this->assertTrue( CoreOptions::contains( 'my_mu_plugin_sentinel' ) );
		} finally {
			remove_filter( 'optrion_core_options', $filter );
		}
	}
}
