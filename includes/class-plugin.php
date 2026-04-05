<?php
/**
 * Optrion main plugin bootstrap class.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Boot the plugin.
	 *
	 * Registers hooks and loads modules.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'optrion', false, dirname( plugin_basename( OPTRION_FILE ) ) . '/languages' );
		Schema::maybe_upgrade();
	}

	/**
	 * Activation hook callback.
	 *
	 * Creates custom tables and schedules cron.
	 */
	public static function activate(): void {
		Schema::install();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * Unschedules cron. Does not drop tables.
	 */
	public static function deactivate(): void {
		// Implemented in a later task.
	}
}
