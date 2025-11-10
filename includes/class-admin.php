<?php
class CHU_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Click Hive Utility',           // Page title
            'Click Hive',                   // Menu title
            'manage_options',                // Capability
            'click-hive-utility',           // Menu slug
            array($this, 'render_admin_page'), // Callback
            'dashicons-admin-tools',        // Icon
            80                              // Position
        );
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap chu-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="chu-container">
                <!-- Your utility features will go here -->
                <p>Welcome to Click Hive Utility!</p>
            </div>
        </div>
        <?php
    }
    
    public function enqueue_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'click-hive-utility') === false) {
            return;
        }
        
        wp_enqueue_style(
            'chu-admin-style',
            CHU_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            CHU_VERSION
        );
        
        wp_enqueue_script(
            'chu-admin-script',
            CHU_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            CHU_VERSION,
            true
        );
    }
}