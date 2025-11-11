<?php
/**
 * Admin Styling Class
 * Handles all WordPress admin area customizations
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Admin_Styling {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('chu_settings', array());
        
        // Only apply styling if enabled in settings
        if ($this->is_styling_enabled()) {
            $this->init_hooks();
        }
    }
    
    private function is_styling_enabled() {
        return isset($this->settings['enable_admin_styling']) && $this->settings['enable_admin_styling'] === '1';
    }
    
    private function init_hooks() {
        // Remove WordPress logo from admin bar
        add_action('admin_bar_menu', array($this, 'remove_wp_logo'), 999);
        
        // Remove clutter from admin bar
        add_action('admin_bar_menu', array($this, 'remove_admin_bar_items'), 999);
        
        // Move PRO menu to Settings
        add_action('admin_menu', array($this, 'reorganize_menus'), 999);
        
        // Enqueue custom styles and fonts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_branding_assets'));
        
        // Replace admin footer text
        add_filter('admin_footer_text', array($this, 'custom_footer_text'));
        add_filter('update_footer', array($this, 'remove_version_footer'), 999);
        
        // Add custom logo to admin bar
        add_action('admin_bar_menu', array($this, 'add_custom_logo'), 1);
        add_action('wp_before_admin_bar_render', array($this, 'add_logo_styles'));
    }
    
    /**
     * Remove WordPress logo from admin bar
     */
    public function remove_wp_logo($wp_admin_bar) {
        $wp_admin_bar->remove_node('wp-logo');
    }
    
    /**
     * Remove clutter from admin bar
     */
    public function remove_admin_bar_items($wp_admin_bar) {
        // Remove Comments
        $wp_admin_bar->remove_node('comments');
        
        // Remove New Content (+ menu)
        $wp_admin_bar->remove_node('new-content');
        
        // Optional: Remove other items (uncomment to enable)
        // $wp_admin_bar->remove_node('updates');  // Updates indicator
        // $wp_admin_bar->remove_node('search');   // Search box
    }
    
    /**
     * Add Click Hive logo to admin bar
     */
    public function add_custom_logo($wp_admin_bar) {
        $logo_url = CHU_PLUGIN_URL . 'assets/images/click-hive-logo.png';
        
        $wp_admin_bar->add_node(array(
            'id'    => 'chu-logo',
            'title' => '<img src="' . esc_url($logo_url) . '" alt="Click Hive" style="height: 20px; vertical-align: middle; margin-top: 6px;">',
            'href'  => 'https://clickhivemarketing.com',
            'meta'  => array(
                'target' => '_blank',
                'class'  => 'chu-admin-logo'
            )
        ));
    }
    
    /**
     * Add inline styles for logo
     */
    public function add_logo_styles() {
        echo '<style>
            #wpadminbar #wp-admin-bar-chu-logo .ab-item {
                padding: 0 10px;
                height: 32px;
            }
            #wpadminbar #wp-admin-bar-chu-logo img {
                max-height: 20px;
                width: auto;
            }
        </style>';
    }
    
    /**
     * Reorganize admin menus
     */
    public function reorganize_menus() {
        global $menu, $submenu;
        
        // Move Updates from Dashboard to Settings
        if (isset($submenu['index.php'])) {
            foreach ($submenu['index.php'] as $key => $item) {
                if ($item[2] === 'update-core.php') {
                    // Add to Settings menu
                    add_submenu_page(
                        'options-general.php',
                        $item[0],
                        $item[0],
                        $item[1],
                        $item[2]
                    );
                    // Remove from Dashboard
                    unset($submenu['index.php'][$key]);
                }
            }
        }
        
        // Move PRO theme menu to Settings
        // PRO theme uses 'x-addons-home' as the menu slug
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && $item[2] === 'x-addons-home') {
                // Get the menu title and capability
                $menu_title = $item[0];
                $capability = $item[1];
                
                // Add to Settings submenu
                add_submenu_page(
                    'options-general.php',
                    'PRO Theme',
                    'PRO',
                    $capability,
                    'x-addons-home'
                );
                
                // Remove from main menu
                unset($menu[$key]);
            }
        }
        
        // If PRO has submenus, move those too
        if (isset($submenu['x-addons-home'])) {
            $pro_submenus = $submenu['x-addons-home'];
            foreach ($pro_submenus as $submenu_item) {
                if ($submenu_item[2] !== 'x-addons-home') {
                    add_submenu_page(
                        'options-general.php',
                        $submenu_item[0],
                        $submenu_item[0],
                        $submenu_item[1],
                        $submenu_item[2]
                    );
                }
            }
            unset($submenu['x-addons-home']);
        }
    }
    
    /**
     * Enqueue branding styles and fonts
     */
    public function enqueue_branding_assets() {
        // Google Fonts - Roboto
        wp_enqueue_style(
            'chu-roboto-font',
            'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
            array(),
            null
        );
        
        // Custom branding styles
        wp_enqueue_style(
            'chu-admin-branding',
            CHU_PLUGIN_URL . 'assets/css/admin-branding.css',
            array(),
            CHU_VERSION
        );
    }
    
    /**
     * Replace admin footer text
     */
    public function custom_footer_text($text) {
        return 'Thanks for choosing <a href="https://clickhivemarketing.com" target="_blank">Click Hive Marketing</a>';
    }
    
    /**
     * Remove WordPress version from footer
     */
    public function remove_version_footer($text) {
        return '';
    }
}