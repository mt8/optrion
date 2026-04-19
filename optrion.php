<?php
/**
 * Plugin Name:       Optrion
 * Plugin URI:        https://github.com/mt8/optrion
 * Description:       Track which plugin or theme accesses each wp_options row, then quarantine or clean orphans.
 * Version:           1.0.2
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            mt8biz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       optrion
 * Domain Path:       /languages
 *
 * @package Optrion
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'OPTRION_VERSION', '1.0.2' );
define( 'OPTRION_FILE', __FILE__ );
define( 'OPTRION_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTRION_URL', plugin_dir_url( __FILE__ ) );

require_once OPTRION_DIR . 'includes/class-schema.php';
require_once OPTRION_DIR . 'includes/class-core-options.php';
require_once OPTRION_DIR . 'includes/class-tracker.php';
require_once OPTRION_DIR . 'includes/class-classifier.php';
require_once OPTRION_DIR . 'includes/class-quarantine.php';
require_once OPTRION_DIR . 'includes/class-exporter.php';
require_once OPTRION_DIR . 'includes/class-importer.php';
require_once OPTRION_DIR . 'includes/class-cleaner.php';
require_once OPTRION_DIR . 'includes/class-rest-controller.php';
require_once OPTRION_DIR . 'includes/class-admin-page.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OPTRION_DIR . 'includes/class-cli-command.php';
}
require_once OPTRION_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( \Optrion\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Optrion\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Optrion\Plugin::class, 'boot' ) );
