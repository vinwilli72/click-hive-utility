<?php
/**
 * Client Dashboard
 *
 * Merged from the standalone CHM Client Dashboard plugin (1.2.0). Shows a configurable
 * dashboard of quick links, tutorials, videos and Click Hive support, as a widget on
 * Dashboard > Home and as its own page at Dashboard > Client Dashboard.
 *
 * Uses the same option (chm_client_dashboard) and page slug (chm-client-dashboard) as the
 * standalone plugin, so existing sites keep their settings and Client Access checkmarks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Client_Dashboard {

    const OPTION      = 'chm_client_dashboard';
    const OPT_ENABLED = 'chu_client_dashboard_enabled';
    const GROUP       = 'chu_client_dashboard';
    const VIEW_SLUG   = 'chm-client-dashboard';
    const EDIT_SLUG   = 'chu-client-dashboard';

    private $edit_hook = '';

    public function __construct() {
        // The standalone plugin is still active: stay out of its way and ask for it to be removed.
        if (class_exists('CHM\\ClientDashboard\\Plugin')) {
            add_action('admin_notices', array($this, 'standalone_notice'));
            return;
        }

        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register'));

        if ($this->is_enabled()) {
            add_action('wp_dashboard_setup', array($this, 'widgets'));
            add_action('admin_enqueue_scripts', array($this, 'assets'));
        } else {
            add_action('admin_enqueue_scripts', array($this, 'editor_assets'));
        }
    }

    /**
     * Sites that had the standalone plugin start enabled. Everywhere else it starts off.
     */
    public function is_enabled() {
        $enabled = get_option(self::OPT_ENABLED, null);
        if (null === $enabled) {
            return false !== get_option(self::OPTION, false);
        }
        return '1' === $enabled;
    }

    public function standalone_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Click Hive Utility:</strong> the Client Dashboard is now built into Click Hive Utility. Deactivate and delete the separate <em>CHM Client Dashboard</em> plugin; your dashboard settings carry over automatically.</p></div>';
    }

    public static function defaults() {
        return array(
            'title' => 'Admin Section',
            'intro' => 'Direct links, video tutorials, and resources for managing your site.',
            'logo'  => '',
            'links' => array(
                array('label' => 'Edit Pages', 'url' => 'edit.php?post_type=page', 'cap' => 'edit_pages', 'icon' => 'admin-page'),
                array('label' => 'Blog Posts', 'url' => 'edit.php', 'cap' => 'edit_posts', 'icon' => 'admin-post'),
            ),
            'tutorials' => array(),
            'videos'    => array(),
        );
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    public function register() {
        register_setting(self::GROUP, self::OPT_ENABLED, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return '1' === $value ? '1' : '0';
            },
            'default'           => '0',
        ));
        register_setting(self::GROUP, self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize'),
            'default'           => self::defaults(),
        ));
    }

    public function sanitize($raw) {
        $raw = is_array($raw) ? $raw : array();
        $s   = self::defaults();

        foreach (array('title', 'intro') as $key) {
            $s[$key] = sanitize_text_field(isset($raw[$key]) ? $raw[$key] : '');
        }
        $s['logo'] = esc_url_raw(isset($raw['logo']) ? $raw['logo'] : '', array('http', 'https'));

        foreach (array('links', 'tutorials', 'videos') as $type) {
            $s[$type] = array();
            $rows     = (isset($raw[$type]) && is_array($raw[$type])) ? $raw[$type] : array();

            foreach (array_slice($rows, 0, 100) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ('links' === $type) {
                    $label   = sanitize_text_field(isset($row['label']) ? $row['label'] : '');
                    $url     = esc_url_raw(isset($row['url']) ? $row['url'] : '', array('http', 'https'));
                    $section = !empty($row['section']);
                    if ($label && ($section || $url)) {
                        $s[$type][] = array(
                            'label'   => $label,
                            'section' => $section ? 1 : 0,
                            'url'     => $section ? '' : $url,
                            'cap'     => $section ? '' : sanitize_key(isset($row['cap']) ? $row['cap'] : ''),
                            'icon'    => $section ? '' : sanitize_key(isset($row['icon']) ? $row['icon'] : 'admin-links'),
                        );
                    }
                } elseif ('tutorials' === $type) {
                    $title = sanitize_text_field(isset($row['title']) ? $row['title'] : '');
                    if ($title) {
                        $s[$type][] = array('title' => $title, 'body' => wp_kses_post(isset($row['body']) ? $row['body'] : ''));
                    }
                } else {
                    $url = esc_url_raw(isset($row['url']) ? $row['url'] : '', array('http', 'https'));
                    if (self::embed_url($url)) {
                        $s[$type][] = array('title' => sanitize_text_field(isset($row['title']) ? $row['title'] : ''), 'url' => $url);
                    } elseif ($url) {
                        add_settings_error(self::OPTION, 'video', 'Video skipped: use a Vimeo or YouTube video URL.');
                    }
                }
            }
        }
        return $s;
    }

    public static function embed_url($url) {
        $p = wp_parse_url($url);
        if (!is_array($p) || !in_array(strtolower(isset($p['scheme']) ? $p['scheme'] : ''), array('http', 'https'), true)) {
            return '';
        }
        $host = strtolower(isset($p['host']) ? $p['host'] : '');
        $path = isset($p['path']) ? $p['path'] : '';
        $q    = array();
        parse_str(isset($p['query']) ? $p['query'] : '', $q);

        if (in_array($host, array('vimeo.com', 'www.vimeo.com', 'player.vimeo.com'), true) && preg_match('~^/(?:video/)?([0-9]+)(?:/([a-zA-Z0-9]+))?/?$~', $path, $m)) {
            $hash = isset($q['h']) ? $q['h'] : (isset($m[2]) ? $m[2] : '');
            return 'https://player.vimeo.com/video/' . $m[1] . (is_string($hash) && preg_match('/^[a-zA-Z0-9]+$/', $hash) ? '?h=' . $hash : '');
        }

        $id = '';
        if ('youtu.be' === $host) {
            $id = trim($path, '/');
        } elseif (in_array($host, array('youtube.com', 'www.youtube.com', 'm.youtube.com'), true)) {
            if ('/watch' === $path) {
                $id = isset($q['v']) ? $q['v'] : '';
            } elseif (preg_match('~^/(?:embed|shorts)/([a-zA-Z0-9_-]+)$~', $path, $m)) {
                $id = $m[1];
            }
        }
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $id) ? 'https://www.youtube-nocookie.com/embed/' . $id : '';
    }

    public function menu() {
        if ($this->is_enabled()) {
            add_dashboard_page('Client Dashboard', 'Client Dashboard', 'read', self::VIEW_SLUG, array($this, 'page'));
        }
        $this->edit_hook = (string) add_submenu_page(
            'click-hive-utility',
            'Client Dashboard',
            'Client Dashboard',
            'manage_options',
            self::EDIT_SLUG,
            array($this, 'editor')
        );
    }

    public function assets($hook) {
        if (in_array($hook, array('index.php', 'dashboard_page_' . self::VIEW_SLUG), true) || $hook === $this->edit_hook) {
            wp_enqueue_style('chu-client-dashboard', CHU_PLUGIN_URL . 'assets/css/client-dashboard.css', array(), CHU_VERSION);
        }
        $this->editor_assets($hook);
    }

    public function editor_assets($hook) {
        if ($hook !== $this->edit_hook) {
            return;
        }
        wp_enqueue_style('chu-client-dashboard', CHU_PLUGIN_URL . 'assets/css/client-dashboard.css', array(), CHU_VERSION);
        wp_enqueue_media();
        wp_enqueue_script('chu-client-dashboard-editor', CHU_PLUGIN_URL . 'assets/js/client-dashboard-editor.js', array(), CHU_VERSION, true);
    }

    public function widgets() {
        if (current_user_can('read')) {
            wp_add_dashboard_widget('chm_client_dashboard_widget', 'Client Dashboard', array($this, 'render'), null, null, 'normal', 'high');
        }
    }

    public function page() {
        if (!current_user_can('read')) {
            return;
        }
        echo '<div class="wrap"><h1>Client Dashboard</h1>';
        $this->render();
        echo '</div>';
    }

    private static function link_url($url) {
        if (preg_match('~^https?://~i', $url) || 0 === strpos($url, '/')) {
            return $url;
        }
        return admin_url($url);
    }

    public function render() {
        if (!current_user_can('read')) {
            return;
        }
        $s = self::settings();

        echo '<div class="chm-dashboard">';
        if ($s['logo']) {
            echo '<img class="chm-logo" src="' . esc_url($s['logo']) . '" alt="' . esc_attr(get_bloginfo('name')) . '">';
        }
        echo '<div class="chm-heading"><h2>' . esc_html($s['title']) . '</h2>';
        $screen = get_current_screen();
        if ($screen && 'dashboard' === $screen->id) {
            echo '<a class="chm-full-dashboard" href="' . esc_url(admin_url('index.php?page=' . self::VIEW_SLUG)) . '">Open Full Dashboard <span class="dashicons dashicons-editor-expand" aria-hidden="true"></span></a>';
        }
        echo '</div><p>' . esc_html($s['intro']) . '</p><div class="chm-grid">';

        foreach ($s['links'] as $row) {
            if (!empty($row['section'])) {
                echo '<h2 class="chm-link-section">' . esc_html($row['label']) . '</h2>';
                continue;
            }
            if ($row['cap'] && !current_user_can($row['cap'])) {
                continue;
            }
            echo '<a class="chm-card" href="' . esc_url(self::link_url($row['url'])) . '"><span class="dashicons dashicons-' . esc_attr($row['icon']) . '" aria-hidden="true"></span><span>' . esc_html($row['label']) . '</span></a>';
        }
        echo '</div>';

        if ($s['tutorials']) {
            echo '<h2>Tutorials</h2><div class="chm-grid">';
            foreach ($s['tutorials'] as $row) {
                echo '<section class="chm-card chm-tutorial"><h3>' . esc_html($row['title']) . '</h3>' . wpautop(wp_kses_post($row['body'])) . '</section>';
            }
            echo '</div>';
        }

        foreach ($s['videos'] as $row) {
            $embed = self::embed_url($row['url']);
            if (!$embed) {
                continue;
            }
            echo '<section class="chm-video"><h3>' . esc_html($row['title']) . '</h3><iframe src="' . esc_url($embed) . '" title="' . esc_attr($row['title'] ? $row['title'] : 'Training video') . '" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></section>';
        }

        echo '<section class="chm-support">';
        echo '<img class="chm-support-logo" src="https://clickhivemarketing.com/wp-content/uploads/2026/02/clickhive-marketing-logo.png" alt="Click Hive Marketing" loading="lazy">';
        echo '<h2>Click Hive Marketing</h2><p>Need help with your website?</p>';
        echo '<a class="button button-primary" href="https://clickhivemarketing.com/client-page/" target="_blank" rel="noopener noreferrer">Get Support<span class="screen-reader-text"> (opens in a new tab)</span></a>';
        echo '</section>';

        if (current_user_can('manage_options')) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::EDIT_SLUG)) . '">Customize this dashboard</a></p>';
        }
        echo '</div>';
    }

    public function editor() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s       = self::settings();
        $enabled = $this->is_enabled();

        echo '<div class="wrap chm-editor"><h1>Client Dashboard</h1>';
        settings_errors();
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);

        echo '<input type="hidden" name="' . esc_attr(self::OPT_ENABLED) . '" value="0">';
        echo '<p><label><input type="checkbox" name="' . esc_attr(self::OPT_ENABLED) . '" value="1"' . checked($enabled, true, false) . '> <strong>Enable the Client Dashboard</strong> (widget on Dashboard &gt; Home and the Dashboard &gt; Client Dashboard page)</label></p>';
        if (!$enabled) {
            echo '<p class="description">Off on this site. You can set everything up below first, then turn it on and save.</p>';
        }

        echo '<p>The dashboard is visible to anyone who can log in to the admin. Only administrators can change these settings. A link\'s required capability hides it from users who lack it; the destination page still enforces its own access. If you use Client Access, Clients also need the destination page checked there.</p>';

        foreach (array('title' => 'Heading', 'intro' => 'Introduction', 'logo' => 'Client logo URL') as $key => $label) {
            echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" id="chm-' . esc_attr($key) . '" name="' . esc_attr(self::OPTION) . '[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '" type="text"></label>';
            if ('logo' === $key) {
                echo ' <button type="button" class="button" id="chm-logo-select">Choose image</button>';
            }
            echo '</p>';
        }

        echo '<p>Support uses the Click Hive Marketing logo and a Get Support link to clickhivemarketing.com/client-page/.</p>';
        echo '<p>Quick link URLs: use an admin path such as <code>edit.php?post_type=page</code>, a site path such as <code>/training/</code>, or a full HTTPS URL. Put links in the order you want them displayed. Capability examples: edit_pages, edit_posts, manage_woocommerce, manage_options. Leave blank for all dashboard users.</p>';
        echo '<p>To group links, add an item, enter a label and check Make Section? Its label becomes a full-width heading. URL, capability and icon are ignored for section items. Use Up/Down to position it before the links it groups.</p>';

        foreach (array('links' => array('label', 'section', 'url', 'cap', 'icon'), 'tutorials' => array('title', 'body'), 'videos' => array('title', 'url')) as $type => $fields) {
            echo '<h2>' . esc_html(ucfirst($type)) . '</h2>';
            if ('videos' === $type) {
                echo '<p>Use Vimeo or YouTube video URLs, including private Vimeo URLs with an h parameter. Videos load from those providers when the dashboard is viewed.</p>';
            }
            if ('tutorials' === $type) {
                echo '<p>Instructions support safe HTML such as paragraphs, lists, and links.</p>';
            }
            echo '<div data-chm-list="' . esc_attr($type) . '">';
            foreach ($s[$type] as $i => $row) {
                $this->row($type, $fields, $i, $row);
            }
            echo '</div><template id="chm-template-' . esc_attr($type) . '">';
            $this->row($type, $fields, '__INDEX__', array());
            echo '</template><p><button type="button" class="button chm-add" data-type="' . esc_attr($type) . '">Add ' . esc_html(rtrim($type, 's')) . '</button></p>';
        }

        submit_button();
        echo '</form></div>';
    }

    private function row($type, $fields, $i, $row) {
        $labels = array(
            'cap'     => 'Required capability',
            'icon'    => 'Dashicon name (e.g. admin-links)',
            'body'    => 'Instructions (HTML)',
            'url'     => 'URL',
            'label'   => 'Link label',
            'title'   => 'Title',
            'section' => 'Make Section?',
        );

        echo '<div class="chm-edit-row">';
        foreach ($fields as $field) {
            $name = self::OPTION . '[' . $type . '][' . $i . '][' . $field . ']';
            echo '<label>' . esc_html($labels[$field]);
            if ('section' === $field) {
                echo '<input class="chm-section-toggle" type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked(!empty($row[$field]), true, false) . '>';
            } elseif ('body' === $field) {
                echo '<textarea rows="5" name="' . esc_attr($name) . '">' . esc_textarea(isset($row[$field]) ? $row[$field] : '') . '</textarea>';
            } else {
                $value = isset($row[$field]) ? $row[$field] : ('icon' === $field ? 'admin-links' : '');
                echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
            echo '</label>';
        }
        echo '<div><button type="button" class="button chm-up" aria-label="Move item up">Up</button> <button type="button" class="button chm-down" aria-label="Move item down">Down</button> <button type="button" class="button chm-remove">Remove</button></div></div>';
    }
}
