<?php
/**
 * Plugin Name:       Optrion
 * Plugin URI:        https://github.com/mt8/optrion
 * Description:       Track, score, quarantine, and clean orphaned options in your WordPress database.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            mt8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       optrion
 * Domain Path:       /languages
 *
 * @package Optrion
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'OPTRION_VERSION', '0.1.0' );
define( 'OPTRION_FILE', __FILE__ );
define( 'OPTRION_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTRION_URL', plugin_dir_url( __FILE__ ) );

$optrion_autoload = OPTRION_DIR . 'vendor/autoload.php';
if ( is_readable( $optrion_autoload ) ) {
	require_once $optrion_autoload;
}

require_once OPTRION_DIR . 'includes/class-schema.php';
require_once OPTRION_DIR . 'includes/class-core-options.php';
require_once OPTRION_DIR . 'includes/class-tracker.php';
require_once OPTRION_DIR . 'includes/class-scorer.php';
require_once OPTRION_DIR . 'includes/class-quarantine.php';
require_once OPTRION_DIR . 'includes/class-exporter.php';
require_once OPTRION_DIR . 'includes/class-importer.php';
require_once OPTRION_DIR . 'includes/class-cleaner.php';
require_once OPTRION_DIR . 'includes/class-rest-controller.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OPTRION_DIR . 'includes/class-cli-command.php';
}
require_once OPTRION_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( \Optrion\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Optrion\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Optrion\Plugin::class, 'boot' ) );
