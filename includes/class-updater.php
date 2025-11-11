<?php
/**
 * Updater Class
 * Handles plugin updates from GitHub
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Updater {
    
    private $plugin_file;
    private $github_username = 'vinwilli72';
    private $github_repo = 'click-hive-utility';
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_folder'), 10, 4);
    }
    
    /**
     * Fix the folder name after download
     * GitHub names folders as "repo-name-version" but WordPress needs "repo-name"
     */
    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;
        
        // Only run for our plugin
        if (!isset($hook_extra['plugin'])) {
            return $source;
        }
        
        if ($hook_extra['plugin'] !== plugin_basename($this->plugin_file)) {
            return $source;
        }
        
        // Get the correct plugin folder name (should be: click-hive-utility)
        $plugin_slug = dirname(plugin_basename($this->plugin_file));
        
        // Clean up source path
        $source = rtrim($source, '/\\');
        $source_name = basename($source);
        
        // If already correct, return it
        if ($source_name === $plugin_slug) {
            return trailingslashit($source);
        }
        
        // Build correct destination path
        $new_source = dirname($source) . '/' . $plugin_slug;
        
        // If destination exists, delete it first
        if ($wp_filesystem->exists($new_source)) {
            $wp_filesystem->delete($new_source, true, 'd');
        }
        
        // Move/rename the folder
        $moved = $wp_filesystem->move($source, $new_source, true);
        
        if ($moved) {
            return trailingslashit($new_source);
        }
        
        // Log error for debugging
        error_log('CHU Updater Error: Could not rename ' . $source . ' to ' . $new_source);
        
        // Return original source to avoid breaking update
        return trailingslashit($source);
    }
    
    /**
     * Check for updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get plugin slug
        $plugin_slug = plugin_basename($this->plugin_file);
        
        // If our plugin isn't in the checked list, bail
        if (!isset($transient->checked[$plugin_slug])) {
            return $transient;
        }
        
        $remote_version = $this->get_remote_version();
        
        // Compare versions
        if ($remote_version && version_compare($transient->checked[$plugin_slug], $remote_version, '<')) {
            
            $obj = new stdClass();
            $obj->slug = dirname($plugin_slug);
            $obj->plugin = $plugin_slug;
            $obj->new_version = $remote_version;
            $obj->url = "https://github.com/{$this->github_username}/{$this->github_repo}";
            $obj->package = $this->get_download_url($remote_version);
            $obj->icons = array();
            $obj->banners = array();
            $obj->banners_rtl = array();
            $obj->tested = get_bloginfo('version');
            $obj->requires_php = '7.0';
            $obj->compatibility = new stdClass();
            
            $transient->response[$plugin_slug] = $obj;
        } else {
            // Explicitly set no update if we're current
            $obj = new stdClass();
            $obj->slug = dirname($plugin_slug);
            $obj->plugin = $plugin_slug;
            $obj->new_version = $remote_version ? $remote_version : CHU_VERSION;
            $obj->url = "https://github.com/{$this->github_username}/{$this->github_repo}";
            $obj->package = '';
            
            $transient->no_update[$plugin_slug] = $obj;
        }
        
        return $transient;
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        $transient_key = 'chu_github_version';
        $cached_version = get_transient($transient_key);
        
        if ($cached_version !== false) {
            return $cached_version;
        }
        
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (!empty($body->tag_name)) {
            $version = ltrim($body->tag_name, 'v.');
            // Cache for 6 hours
            set_transient($transient_key, $version, 6 * HOUR_IN_SECONDS);
            return $version;
        }
        
        return false;
    }
    
    /**
     * Get download URL for specific version
     */
    private function get_download_url($version) {
        return "https://github.com/{$this->github_username}/{$this->github_repo}/archive/refs/tags/v{$version}.zip";
    }
    
    /**
     * Plugin information for update details
     */
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') {
            return $false;
        }
        
        if (!isset($response->slug) || $response->slug !== dirname(plugin_basename($this->plugin_file))) {
            return $false;
        }
        
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
        
        $remote = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json'
            )
        ));
        
        if (is_wp_error($remote)) {
            return $false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($remote));
        
        if (!empty($body)) {
            $version = ltrim($body->tag_name, 'v');
            
            $obj = new stdClass();
            $obj->name = 'Click Hive Utility';
            $obj->slug = dirname(plugin_basename($this->plugin_file));
            $obj->version = $version;
            $obj->author = '<a href="https://clickhivemarketing.com">Click Hive Marketing</a>';
            $obj->homepage = "https://github.com/{$this->github_username}/{$this->github_repo}";
            $obj->download_link = $this->get_download_url($version);
            $obj->requires = '5.0';
            $obj->tested = get_bloginfo('version');
            $obj->last_updated = $body->published_at;
            
            $obj->sections = array(
                'description' => !empty($body->body) ? $this->parse_markdown($body->body) : 'Click Hive Utility Plugin',
                'changelog' => !empty($body->body) ? $this->parse_markdown($body->body) : 'See release notes on GitHub'
            );
            
            return $obj;
        }
        
        return $false;
    }
    
    /**
     * Simple markdown to HTML parser
     */
    private function parse_markdown($text) {
        // Convert markdown to basic HTML
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        $text = preg_replace('/^\- (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);
        $text = nl2br($text);
        return $text;
    }
}