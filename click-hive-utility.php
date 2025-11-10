<?php
/**
 * Plugin Name: Click Hive Utility
 * Plugin URI: https://clickhivemarketing.com/click-hive-utility
 * Description: Enhances WordPress admin dashboard with utility improvements
 * Version: 1.0.1
 * Author: Click Hive Marketing
 * Author URI: https://clickhivemarketing.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: click-hive-utility
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CHU_VERSION', '1.0.1');
define('CHU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHU_PLUGIN_FILE', __FILE__);

// Include required files
require_once CHU_PLUGIN_DIR . 'includes/class-admin.php';
require_once CHU_PLUGIN_DIR . 'includes/class-updater.php';
require_once CHU_PLUGIN_DIR . 'includes/class-settings.php';

// Initialize the plugin
function chu_init() {
    $admin = new CHU_Admin();
    $settings = new CHU_Settings();
    
    // Initialize updater
    $updater = new CHU_Updater(CHU_PLUGIN_FILE);
}
add_action('plugins_loaded', 'chu_init');

// Activation hook
register_activation_hook(__FILE__, 'chu_activate');
function chu_activate() {
    // Set default options
    add_option('chu_version', CHU_VERSION);
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'chu_deactivate');
function chu_deactivate() {
    flush_rewrite_rules();
}