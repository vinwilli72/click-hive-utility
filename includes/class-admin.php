<?php
/**
 * Admin Class
 * Handles admin menu and pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            'Click Hive Utility',
            'Click Hive',
            'manage_options',
            'click-hive-utility',
            array($this, 'render_dashboard_page'),
            'dashicons-admin-tools',
            80
        );
        
        // Dashboard submenu (same as main page)
        add_submenu_page(
            'click-hive-utility',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'click-hive-utility',
            array($this, 'render_dashboard_page')
        );
        
        // Admin Dashboard Settings
        add_submenu_page(
            'click-hive-utility',
            'Admin Dashboard',
            'Admin Dashboard',
            'manage_options',
            'chu-admin-dashboard',
            array($this, 'render_admin_settings_page')
        );
        
        // Shortcodes (placeholder for future)
        add_submenu_page(
            'click-hive-utility',
            'Shortcodes',
            'Shortcodes',
            'manage_options',
            'chu-shortcodes',
            array($this, 'render_shortcodes_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'click-hive-utility') === false && strpos($hook, 'chu-') === false) {
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
    
    /**
     * Render main dashboard page
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap chu-admin">
            <h1>Click Hive Utility</h1>
            
            <div class="chu-dashboard-container">
                <div class="chu-welcome-panel">
                    <h2>Welcome to Click Hive Utility</h2>
                    <p>This plugin provides essential utilities and customizations for Click Hive Marketing websites.</p>
                    
                    <div class="chu-feature-grid">
                        <div class="chu-feature-box">
                            <span class="dashicons dashicons-admin-appearance"></span>
                            <h3>Admin Dashboard</h3>
                            <p>Customize the WordPress admin area with Click Hive branding.</p>
                            <a href="<?php echo admin_url('admin.php?page=chu-admin-dashboard'); ?>" class="button button-primary">Configure</a>
                        </div>
                        
                        <div class="chu-feature-box">
                            <span class="dashicons dashicons-editor-code"></span>
                            <h3>Shortcodes</h3>
                            <p>Access reusable shortcodes for consistent functionality across sites.</p>
                            <a href="<?php echo admin_url('admin.php?page=chu-shortcodes'); ?>" class="button">View Shortcodes</a>
                        </div>
                    </div>
                </div>
                
                <div class="chu-info-boxes">
                    <div class="chu-info-box">
                        <h3>Plugin Version</h3>
                        <p><strong><?php echo CHU_VERSION; ?></strong></p>
                    </div>
                    
                    <div class="chu-info-box">
                        <h3>Need Help?</h3>
                        <p><a href="https://clickhivemarketing.com" target="_blank">Visit Click Hive Marketing</a></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render admin settings page
     */
    public function render_admin_settings_page() {
        // Save settings if form submitted
        if (isset($_POST['chu_save_settings']) && check_admin_referer('chu_admin_settings')) {
            $options = array();
            $options['enable_admin_styling'] = isset($_POST['chu_settings']['enable_admin_styling']) ? '1' : '0';
            update_option('chu_settings', $options);
            
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }
        
        $settings = get_option('chu_settings', array());
        $styling_enabled = isset($settings['enable_admin_styling']) && $settings['enable_admin_styling'] === '1';
        ?>
        <div class="wrap chu-admin">
            <h1>Admin Dashboard Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('chu_admin_settings'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Enable Click Hive Admin Styling</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="chu_settings[enable_admin_styling]" 
                                           value="1" 
                                           <?php checked($styling_enabled, true); ?> />
                                    Apply Click Hive branding to the WordPress admin area
                                </label>
                                
                                <p class="description">
                                    <strong>When enabled, this will:</strong><br>
                                    • Apply Click Hive color scheme (#231f20 dark, #f07021 orange)<br>
                                    • Add Click Hive logo to admin bar<br>
                                    • Move PRO theme menu to Settings<br>
                                    • Move Updates menu to Settings<br>
                                    • Apply Roboto font throughout admin<br>
                                    • Update footer branding
                                </p>
                                
                                <?php if ($styling_enabled): ?>
                                    <p class="description" style="color: #46b450; font-weight: 500;">
                                        ✓ Admin styling is currently active
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" 
                           name="chu_save_settings" 
                           class="button button-primary" 
                           value="Save Settings">
                </p>
            </form>
            
            <div class="chu-preview-box">
                <h2>Color Scheme Preview</h2>
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="text-align: center;">
                        <div style="width: 100px; height: 100px; background: #231f20; border-radius: 8px;"></div>
                        <p><strong>#231f20</strong><br>Dark Gray</p>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 100px; height: 100px; background: #f07021; border-radius: 8px;"></div>
                        <p><strong>#f07021</strong><br>Click Hive Orange</p>
                    </div>
                    <div style="text-align: center;">
                        <div style="width: 100px; height: 100px; background: rgb(245, 245, 245); border: 1px solid #ddd; border-radius: 8px;"></div>
                        <p><strong>rgb(245, 245, 245)</strong><br>Light Gray</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render shortcodes page (placeholder)
     */
    public function render_shortcodes_page() {
        ?>
        <div class="wrap chu-admin">
            <h1>Shortcodes</h1>
            
            <div class="chu-container">
                <p>Shortcodes functionality coming in the next version.</p>
                <p>This section will provide reusable shortcodes for consistent functionality across all Click Hive websites.</p>
            </div>
        </div>
        <?php
    }
}