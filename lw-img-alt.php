<?php
/**
 * Plugin Name:       LW Image Alt
 * Plugin URI:        https://github.com/LWMike/lw-img-alt
 * Description:       Scan the Media Library for images missing alt text and bulk-update them via CSV or inline edit.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lead Wolf Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lw-img-alt
 * Domain Path:       /languages
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'LWIA_VERSION',     '0.1.0' );
define( 'LWIA_DB_VERSION',  '1' );
define( 'LWIA_PLUGIN_FILE', __FILE__ );
define( 'LWIA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'LWIA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Load the main plugin class.
require_once LWIA_PLUGIN_DIR . 'includes/class-plugin.php';

// Activation / deactivation hooks must be registered in the main plugin file.
register_activation_hook( __FILE__, array( 'LWIA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LWIA_Plugin', 'deactivate' ) );

// Boot the plugin after all plugins have loaded so dependencies (e.g. WP-CLI) are available.
add_action( 'plugins_loaded', array( 'LWIA_Plugin', 'get_instance' ) );
