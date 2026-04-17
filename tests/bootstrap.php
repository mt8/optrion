<?php
/**
 * PHPUnit bootstrap for the Optrion test suite.
 *
 * Uses the WordPress test harness mounted inside the wp-env tests container
 * (/wordpress-phpunit/includes/).
 *
 * @package Optrion
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

$optrion_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $optrion_tests_dir ) {
	$optrion_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $optrion_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find WordPress test suite at {$optrion_tests_dir}.\n" );
	fwrite( STDERR, "Run tests via: npx wp-env run tests-cli --env-cwd=wp-content/plugins/optrion composer test\n" );
	exit( 1 );
}

require_once $optrion_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function optrion_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/optrion.php';
}
tests_add_filter( 'muplugins_loaded', 'optrion_manually_load_plugin' );

require $optrion_tests_dir . '/includes/bootstrap.php';

// phpcs:enable
