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
		Tracker::boot();
		add_action( Quarantine::CRON_HOOK, array( Quarantine::class, 'process_expired' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
	}

	/**
	 * Activation hook callback.
	 *
	 * Creates custom tables and schedules cron.
	 */
	public static function activate(): void {
		Schema::install();
		Quarantine::schedule_cron();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * Unschedules cron. Does not drop tables.
	 */
	public static function deactivate(): void {
		Quarantine::unschedule_cron();
	}
}
