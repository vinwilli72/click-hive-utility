<?php
class CHU_Updater {
    
    private $plugin_file;
    private $github_username = 'vinwilli72';
    private $github_repo = 'click-hive-utility';
    private $github_token = ''; // Optional: for private repos
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
    }
    
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        $plugin_slug = plugin_basename($this->plugin_file);
        
        if ($remote_version && version_compare(CHU_VERSION, $remote_version, '<')) {
            $transient->response[$plugin_slug] = (object) array(
                'slug' => dirname($plugin_slug),
                'new_version' => $remote_version,
                'package' => $this->get_download_url($remote_version),
                'url' => "https://github.com/{$this->github_username}/{$this->github_repo}"
            );
        }
        
        return $transient;
    }
    
    private function get_remote_version() {
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
        
        $response = wp_remote_get($url, array(
            'headers' => $this->get_github_headers()
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (!empty($body->tag_name)) {
            return ltrim($body->tag_name, 'v');
        }
        
        return false;
    }
    
    private function get_download_url($version) {
        return "https://github.com/{$this->github_username}/{$this->github_repo}/archive/refs/tags/v{$version}.zip";
    }
    
    private function get_github_headers() {
        $headers = array('Accept' => 'application/vnd.github.v3+json');
        
        if (!empty($this->github_token)) {
            $headers['Authorization'] = "token {$this->github_token}";
        }
        
        return $headers;
    }
    
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') {
            return $false;
        }
        
        if ($response->slug !== dirname(plugin_basename($this->plugin_file))) {
            return $false;
        }
        
        // Fetch plugin info from GitHub
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
        
        $remote = wp_remote_get($url, array(
            'headers' => $this->get_github_headers()
        ));
        
        if (is_wp_error($remote)) {
            return $false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($remote));
        
        if (!empty($body)) {
            $response = (object) array(
                'name' => 'Click Hive Utility',
                'slug' => dirname(plugin_basename($this->plugin_file)),
                'version' => ltrim($body->tag_name, 'v'),
                'download_link' => $this->get_download_url(ltrim($body->tag_name, 'v')),
                'sections' => array(
                    'description' => $body->body,
                ),
            );
            
            return $response;
        }
        
        return $false;
    }
}