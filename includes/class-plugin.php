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
		// Translations are auto-loaded by WordPress via the Domain Path header
		// (core just-in-time loader covers wp-content/languages/plugins/ and
		// the plugin's own languages/ directory since WordPress 4.6).
		Schema::maybe_upgrade();
		Tracker::boot();
		// Wire pre_option_{name} filters for every currently active
		// quarantine so callers keep seeing the original value and every
		// access during the window is recorded on the manifest.
		Quarantine::register_active_filters();
		add_action( Quarantine::CRON_HOOK, array( Quarantine::class, 'process_expired' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
		add_action( 'admin_notices', array( self::class, 'render_active_notice' ) );
		Admin_Page::register();
	}

	/**
	 * Renders a persistent warning banner across every admin screen.
	 *
	 * The tracker instruments every `get_option()` call with a backtrace;
	 * that cost is acceptable during a short audit window but should not
	 * linger indefinitely. Users who do not read the readme still need a
	 * visible reminder to deactivate the plugin once they finish cleaning
	 * up, hence the non-dismissible notice.
	 */
	public static function render_active_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$plugin_file = plugin_basename( OPTRION_FILE );
		$deactivate  = wp_nonce_url(
			self_admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $plugin_file ) ),
			'deactivate-plugin_' . $plugin_file
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Optrion is active.', 'optrion' ),
			esc_html__( 'Optrion instruments every get_option() call to attribute reads to the right plugin or theme. This adds measurable overhead to admin page loads and is intended for short-term audits only — please deactivate the plugin once your current cleanup round is complete.', 'optrion' ),
			esc_url( $deactivate ),
			esc_html__( 'Deactivate Optrion', 'optrion' )
		);
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
