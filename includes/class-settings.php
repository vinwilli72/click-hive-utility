<?php
class CHU_Settings {
    
    private $option_name = 'chu_settings';
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function register_settings() {
        register_setting('chu_settings_group', $this->option_name);
    }
    
    public function get_option($key, $default = '') {
        $options = get_option($this->option_name, array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
    
    public function update_option($key, $value) {
        $options = get_option($this->option_name, array());
        $options[$key] = $value;
        update_option($this->option_name, $options);
    }
}