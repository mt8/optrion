<?php
/**
 * Admin page registration and SPA mount point.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the top-level "Optrion" admin menu and enqueues the React app bundle.
 */
final class Admin_Page {

	/**
	 * Slug used for the admin page hook and menu entry.
	 */
	public const MENU_SLUG = 'optrion';

	/**
	 * DOM id that the React app mounts into.
	 */
	public const ROOT_ID = 'optrion-admin-root';

	/**
	 * Hook suffix produced by add_menu_page() for the Optrion screen.
	 */
	public const HOOK_SUFFIX = 'toplevel_page_' . self::MENU_SLUG;

	/**
	 * Registers admin hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_head', array( self::class, 'menu_icon_css' ) );
	}

	/**
	 * Adds the top-level Optrion menu with its branded icon.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Optrion', 'optrion' ),
			__( 'Optrion', 'optrion' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render' ),
			self::menu_icon(),
			80
		);
	}

	/**
	 * Keeps the branded menu icon fully saturated.
	 *
	 * WordPress dims menu icon images with opacity:0.6 by default; override it
	 * so the branded colors read at their intended intensity.
	 */
	public static function menu_icon_css(): void {
		echo '<style>'
			. '#adminmenu .toplevel_page_' . esc_attr( self::MENU_SLUG ) . ' .wp-menu-image img'
			. '{opacity:1;}'
			. '</style>';
	}

	/**
	 * Returns the admin menu icon URL, or a dashicon fallback.
	 *
	 * A plain URL is used instead of a base64 data URI so that wp.svgPainter
	 * (wp-admin/js/svg-painter.js) does not rewrite every `fill` attribute to
	 * the admin color scheme's base color. The painter only scans elements
	 * whose background-image starts with `data:image/svg+xml;base64,`.
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$icon_path = OPTRION_DIR . 'assets/optrion-menu-icon.svg';
		if ( is_readable( $icon_path ) ) {
			return OPTRION_URL . 'assets/optrion-menu-icon.svg';
		}
		return 'dashicons-admin-generic';
	}

	/**
	 * Renders the SPA mount point.
	 */
	public static function render(): void {
		echo '<div class="wrap">';
		echo '<h1 class="optrion-title">';
		echo '<img class="optrion-title__logo" src="' . esc_url( OPTRION_URL . 'assets/optrion-icon.svg' ) . '" alt="" />';
		echo '<span>' . esc_html__( 'Optrion', 'optrion' ) . '</span>';
		echo '</h1>';
		echo '<div id="' . esc_attr( self::ROOT_ID ) . '">';
		echo '<p>' . esc_html__( 'Loading Optrion…', 'optrion' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Enqueues the React bundle on the Optrion admin screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_file = OPTRION_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// Build has not been produced yet; surface a notice and bail.
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'Optrion admin UI build not found. Run `npm run build` in the plugin directory.', 'optrion' );
					echo '</p></div>';
				}
			);
			return;
		}

		$asset = include $asset_file;
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : OPTRION_VERSION;

		wp_enqueue_script(
			'optrion-admin',
			OPTRION_URL . 'build/index.js',
			$deps,
			$ver,
			true
		);
		wp_set_script_translations( 'optrion-admin', 'optrion', OPTRION_DIR . 'languages' );

		if ( file_exists( OPTRION_DIR . 'build/index.css' ) ) {
			wp_enqueue_style(
				'optrion-admin',
				OPTRION_URL . 'build/index.css',
				array(),
				$ver
			);
		}

		wp_localize_script(
			'optrion-admin',
			'optrionConfig',
			array(
				'restNamespace' => Rest_Controller::NAMESPACE_V1,
				'restRoot'      => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 . '/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'rootId'        => self::ROOT_ID,
			)
		);
	}
}
