<?php
/**
 * Client Menu Class
 * Adds a Client role, limits its admin sidebar to an allowlist,
 * and sends Client users to the dashboard after login.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Client_Menu {

    // Option names match the original standalone plugin so existing site settings carry over.
    const OPT_ENABLED      = 'chu_client_menu_enabled';
    const OPT_ALLOWED      = 'ch_client_menu_allowed';
    const OPT_CAPS         = 'ch_client_menu_caps';
    const OPT_ROLE_VERSION = 'ch_cm_role_version';
    const ROLE_VERSION     = '1.1';
    const ROLE             = 'client';
    const PAGE_SLUG        = 'chu-client-access';

    private $debug = array();

    public function __construct() {
        // Settings page and registration run whether or not the feature is on.
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        if (!$this->is_enabled()) {
            return;
        }

        add_action('init', array($this, 'maybe_build_role'));
        add_action('add_option_' . self::OPT_CAPS, array($this, 'build_role'));
        add_action('update_option_' . self::OPT_CAPS, array($this, 'build_role'));
        add_action('update_option_' . self::OPT_ENABLED, array($this, 'build_role'));

        add_action('admin_menu', array($this, 'filter_menu'), 9999);
        add_action('admin_menu', array($this, 'snapshot_after_all'), PHP_INT_MAX);
        add_action('admin_notices', array($this, 'render_debug'));

        add_filter('login_redirect', array($this, 'login_redirect'), 9999, 3);
        add_filter('woocommerce_login_redirect', array($this, 'woocommerce_login_redirect'), 9999, 2);
    }

    /**
     * Enabled flag. Sites that already used the standalone plugin start enabled.
     */
    private function is_enabled() {
        $enabled = get_option(self::OPT_ENABLED, null);
        if (null === $enabled) {
            return false !== get_option(self::OPT_ALLOWED, false);
        }
        return '1' === $enabled;
    }

    /* ---------------------------------------------------------------
     * Role
     * ------------------------------------------------------------- */

    public function maybe_build_role() {
        if (get_option(self::OPT_ROLE_VERSION) === self::ROLE_VERSION && get_role(self::ROLE)) {
            return;
        }
        $this->build_role();
        update_option(self::OPT_ROLE_VERSION, self::ROLE_VERSION);
    }

    /**
     * Client role = Editor capabilities + Gravity Forms entries + extra capabilities from settings.
     */
    public function build_role() {
        $editor = get_role('editor');
        $caps   = $editor ? $editor->capabilities : array('read' => true);

        $caps['gravityforms_view_entries']   = true;
        $caps['gravityforms_export_entries'] = true;

        foreach ($this->split(get_option(self::OPT_CAPS, '')) as $cap) {
            $caps[sanitize_key($cap)] = true;
        }

        remove_role(self::ROLE);
        add_role(self::ROLE, 'Client', $caps);
    }

    private function is_client($user = null) {
        $user = $user ? $user : wp_get_current_user();
        return $user instanceof WP_User
            && in_array(self::ROLE, (array) $user->roles, true)
            && !user_can($user, 'manage_options');
    }

    /* ---------------------------------------------------------------
     * Login redirect
     * ------------------------------------------------------------- */

    /**
     * Send Clients to the dashboard unless they followed a link to a specific admin page.
     */
    public function login_redirect($redirect_to, $requested, $user) {
        if (!$this->is_client($user)) {
            return $redirect_to;
        }
        if ($requested && 0 === strpos($requested, admin_url()) && untrailingslashit($requested) !== untrailingslashit(admin_url())) {
            return $requested;
        }
        return admin_url('index.php');
    }

    /**
     * WooCommerce's My Account login uses its own redirect.
     */
    public function woocommerce_login_redirect($redirect, $user) {
        return $this->is_client($user) ? admin_url('index.php') : $redirect;
    }

    /* ---------------------------------------------------------------
     * Menu filtering
     * ------------------------------------------------------------- */

    private function split($raw) {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /**
     * Accepts slugs, wp-admin paths, or full admin URLs.
     */
    private function get_allowed() {
        $slugs = array();
        foreach ($this->split(get_option(self::OPT_ALLOWED, '')) as $part) {
            $pos = strpos($part, 'wp-admin/');
            if (false !== $pos) {
                $part = substr($part, $pos + 9);
            }
            if (0 === strpos($part, 'admin.php?page=')) {
                $part = substr($part, 15);
            }
            $part = html_entity_decode($part);
            if ('' !== $part) {
                $slugs[] = $part;
            }
        }
        return array_unique($slugs);
    }

    /**
     * Remove every top-level item not on the allowlist. An empty list leaves the menu untouched.
     */
    public function filter_menu() {
        if (!$this->is_client()) {
            return;
        }

        global $menu;
        $allowed = $this->get_allowed();

        if ($this->is_debugging()) {
            $this->debug['before']  = $this->snapshot();
            $this->debug['allowed'] = $allowed;
            $this->debug['removed'] = array();
        }

        if (empty($allowed)) {
            return;
        }

        foreach ((array) $menu as $item) {
            if (isset($item[2]) && !in_array(html_entity_decode($item[2]), $allowed, true)) {
                remove_menu_page($item[2]);
                if ($this->is_debugging()) {
                    $this->debug['removed'][] = $item[2];
                }
            }
        }
    }

    /* ---------------------------------------------------------------
     * Debug view: add ?chcm_debug=1 to any admin URL while logged in as a Client
     * ------------------------------------------------------------- */

    private function is_debugging() {
        return isset($_GET['chcm_debug']) && $this->is_client();
    }

    private function snapshot() {
        global $menu;
        $out = array();
        foreach ((array) $menu as $pos => $item) {
            if (!empty($item[2])) {
                $out[] = $pos . ' | ' . $item[2] . ' | ' . (isset($item[1]) ? $item[1] : '');
            }
        }
        return $out;
    }

    public function snapshot_after_all() {
        if ($this->is_debugging()) {
            $this->debug['after_all_plugins'] = $this->snapshot();
        }
    }

    /**
     * Every admin_menu callback at priority 9999 or later that isn't from this file, with file and line.
     */
    private function late_callbacks() {
        global $wp_filter;
        $out = array();
        if (empty($wp_filter['admin_menu'])) {
            return array('(none)');
        }

        foreach ($wp_filter['admin_menu']->callbacks as $priority => $callbacks) {
            if ($priority < 9999) {
                continue;
            }
            foreach ($callbacks as $cb) {
                $fn = $cb['function'];
                try {
                    if (is_array($fn)) {
                        $class = is_object($fn[0]) ? get_class($fn[0]) : $fn[0];
                        $ref   = new ReflectionMethod($class, $fn[1]);
                        $name  = $class . '::' . $fn[1];
                    } elseif (is_string($fn) && false !== strpos($fn, '::')) {
                        $ref  = new ReflectionMethod($fn);
                        $name = $fn;
                    } else {
                        $ref  = new ReflectionFunction($fn);
                        $name = $fn instanceof Closure ? 'closure' : $fn;
                    }
                    $file = $ref->getFileName();
                    $line = $ref->getStartLine();
                } catch (Exception $e) {
                    $name = 'unknown';
                    $file = '';
                    $line = '';
                }
                if ($file === __FILE__) {
                    continue;
                }
                $file  = str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path((string) $file));
                $out[] = $priority . ' | ' . $name . ' | ' . $file . ':' . $line;
            }
        }

        return $out ? $out : array('(none)');
    }

    public function render_debug() {
        if (!$this->is_debugging()) {
            return;
        }
        $d    = $this->debug;
        $none = array('(filter did not run)');

        $sections = array(
            'User roles'                                  => (array) wp_get_current_user()->roles,
            'Your allowlist'                              => isset($d['allowed']) ? $d['allowed'] : $none,
            '1. Menu when this plugin runs'               => isset($d['before']) ? $d['before'] : $none,
            '2. Removed by this plugin'                   => !empty($d['removed']) ? $d['removed'] : array('(nothing)'),
            '3. Menu after all plugins finish'            => isset($d['after_all_plugins']) ? $d['after_all_plugins'] : array(),
            '4. Final menu WordPress is about to render'  => $this->snapshot(),
            'Other code that runs on admin_menu after this plugin (priority | callback | file:line)' => $this->late_callbacks(),
        );

        echo '<div class="notice notice-info"><p><strong>Client Menu debug</strong></p>';
        foreach ($sections as $label => $lines) {
            echo '<p><strong>' . esc_html($label) . '</strong></p><pre style="white-space:pre-wrap">' . esc_html(implode("\n", $lines)) . '</pre>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Settings page (Click Hive > Client Access)
     * ------------------------------------------------------------- */

    public function add_settings_page() {
        add_submenu_page(
            'click-hive-utility',
            'Client Access',
            'Client Access',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('chu_client_menu', self::OPT_ENABLED, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return '1' === $value ? '1' : '0';
            },
            'default'           => '0',
        ));
        register_setting('chu_client_menu', self::OPT_ALLOWED, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ));
        register_setting('chu_client_menu', self::OPT_CAPS, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ));
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $menu;
        $enabled = $this->is_enabled();
        $allowed = $this->get_allowed();
        $role    = get_role(self::ROLE);
        ?>
        <div class="wrap chu-admin">
            <h1>Client Access</h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('chu_client_menu'); ?>
                <input type="hidden" name="<?php echo esc_attr(self::OPT_ENABLED); ?>" value="0">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Enable Client Access</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLED); ?>" value="1" <?php checked($enabled); ?>>
                                    Create the Client role, limit its sidebar to the list below, and send Clients to the dashboard after login
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Menu items Clients see</th>
                            <td>
                                <textarea name="<?php echo esc_attr(self::OPT_ALLOWED); ?>" rows="10" class="large-text code"><?php echo esc_textarea(get_option(self::OPT_ALLOWED, '')); ?></textarea>
                                <p class="description">One per line or separated by commas. Paste a slug, a wp-admin path, or a full admin URL. Leave empty to show Clients everything their role allows. Include <code>index.php</code> to keep the Dashboard in their sidebar.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Extra capabilities</th>
                            <td>
                                <textarea name="<?php echo esc_attr(self::OPT_CAPS); ?>" rows="6" class="large-text code"><?php echo esc_textarea(get_option(self::OPT_CAPS, '')); ?></textarea>
                                <p class="description">The Client role starts with Editor capabilities plus Gravity Forms entries. Add any capability a menu item needs, using the names in the Required capability column below.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('Save Client Access'); ?>
            </form>

            <h2>Menu items on this site</h2>
            <p>An item appears for Clients only when it is in your list and the Client role has its required capability. If something still won't show, log in as a Client and add <code>?chcm_debug=1</code> to any admin URL.</p>
            <table class="widefat striped" style="max-width:960px">
                <thead><tr><th>Menu item</th><th>Slug</th><th>Required capability</th><th>In your list</th><th>Client role has capability</th></tr></thead>
                <tbody>
                <?php
                foreach ((array) $menu as $item) {
                    if (empty($item[0]) || empty($item[2])) {
                        continue;
                    }
                    $title   = trim(wp_strip_all_tags(preg_replace('/<span.*<\/span>/s', '', $item[0])));
                    $slug    = html_entity_decode($item[2]);
                    $cap     = isset($item[1]) ? $item[1] : '';
                    $listed  = empty($allowed) || in_array($slug, $allowed, true);
                    $has_cap = $role && $cap && $role->has_cap($cap);
                    printf(
                        '<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                        esc_html($title),
                        esc_html($slug),
                        esc_html($cap),
                        $listed ? 'Yes' : '',
                        $has_cap ? 'Yes' : ($role ? '<strong style="color:#b32d2e">No</strong>' : '(role not created)')
                    );
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
