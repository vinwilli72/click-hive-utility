<?php
/**
 * Settings Class
 * Handles plugin settings and options
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Settings {
    
    private $option_name = 'chu_settings';
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'chu_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        // Admin Dashboard Settings Section
        add_settings_section(
            'chu_admin_section',
            'Admin Dashboard Styling',
            array($this, 'admin_section_callback'),
            'chu_admin_settings'
        );
        
        // Enable Admin Styling
        add_settings_field(
            'enable_admin_styling',
            'Enable Click Hive Admin Styling',
            array($this, 'render_checkbox_field'),
            'chu_admin_settings',
            'chu_admin_section',
            array(
                'field_id' => 'enable_admin_styling',
                'description' => 'Apply Click Hive branding to the WordPress admin area (colors, logo, menu organization)'
            )
        );
    }
    
    /**
     * Admin section description
     */
    public function admin_section_callback() {
        echo '<p>Customize the WordPress admin dashboard to match Click Hive branding.</p>';
        echo '<p><strong>When enabled, this will:</strong></p>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        echo '<li>Apply Click Hive color scheme (#231f20 dark, #f07021 orange)</li>';
        echo '<li>Add Click Hive logo to admin bar</li>';
        echo '<li>Move PRO theme menu to Settings</li>';
        echo '<li>Move Updates menu to Settings</li>';
        echo '<li>Apply Roboto font</li>';
        echo '<li>Update footer branding</li>';
        echo '</ul>';
    }
    
    /**
     * Render checkbox field
     */
    public function render_checkbox_field($args) {
        $options = get_option($this->option_name, array());
        $field_id = $args['field_id'];
        $checked = isset($options[$field_id]) && $options[$field_id] === '1';
        
        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr($this->option_name) . '[' . esc_attr($field_id) . ']" value="1" ' . checked($checked, true, false) . ' />';
        echo ' ' . esc_html($args['description']);
        echo '</label>';
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize checkbox
        if (isset($input['enable_admin_styling'])) {
            $sanitized['enable_admin_styling'] = '1';
        } else {
            $sanitized['enable_admin_styling'] = '0';
        }
        
        return $sanitized;
    }
    
    /**
     * Get option value
     */
    public function get_option($key, $default = '') {
        $options = get_option($this->option_name, array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
    
    /**
     * Update option value
     */
    public function update_option($key, $value) {
        $options = get_option($this->option_name, array());
        $options[$key] = $value;
        update_option($this->option_name, $options);
    }
}