<?php
/**
 * Client Access
 *
 * Adds a Client role and limits it to the admin pages checked on
 * Click Hive > Client Access. A top-level item can be allowed as a whole
 * section, or narrowed to individual subpages. Any page in the admin menu
 * that isn't checked is hidden and blocked, including by direct URL.
 *
 * Checked pages that require a capability the Client role lacks (often
 * manage_options) are opened by granting that capability only while the
 * Client is on that page. Settings and other unchecked pages stay closed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Client_Menu {

    // Option names match the original standalone plugin so existing site settings carry over.
    const OPT_ENABLED      = 'chu_client_menu_enabled';
    const OPT_ALLOWED      = 'ch_client_menu_allowed';   // 1.0.x text list, read only to migrate old sites
    const OPT_ITEMS        = 'chu_client_access_items';  // 1.1.0+ checklist
    const OPT_CAPS         = 'ch_client_menu_caps';
    const OPT_ROLE_VERSION = 'ch_cm_role_version';
    const ROLE_VERSION     = '1.1';
    const ROLE             = 'client';
    const PAGE_SLUG        = 'chu-client-access';

    // Pages Clients can always reach, so they can manage their own login.
    private $always_open = array('profile.php');

    private $debug          = array();
    private $in_menu_build  = false;
    private $request_caps   = null;
    private $items          = null;
    private $plan           = null;

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

        add_filter('user_has_cap', array($this, 'grant_caps'), 10, 4);
        add_action('admin_menu', array($this, 'start_menu_build'), PHP_INT_MIN);
        add_action('admin_menu', array($this, 'filter_menu'), PHP_INT_MAX);
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

    /**
     * A Client is a user with the Client role whose own capabilities don't include manage_options.
     * Reads the unfiltered capability list, so the temporary page access granted below
     * never makes a Client look like an admin.
     */
    private function is_client($user = null) {
        $user = $user ? $user : wp_get_current_user();
        return $user instanceof WP_User
            && $user->exists()
            && in_array(self::ROLE, (array) $user->roles, true)
            && empty($user->allcaps['manage_options']);
    }

    /**
     * Whether the Client role itself has a capability, ignoring any temporary grant.
     */
    private function role_has($cap) {
        if ('' === (string) $cap) {
            return true;
        }
        $role = get_role(self::ROLE);
        return $role && $role->has_cap($cap);
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
     * Checked pages
     * ------------------------------------------------------------- */

    private function split($raw) {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /**
     * 1.0.x stored a text list of top-level slugs, paths, or admin URLs.
     */
    private function legacy_allowed() {
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
     * Checked items: each is array('parent' => '' for top-level, 'slug' => ..., 'cap' => required capability).
     */
    private function get_items() {
        if (null !== $this->items) {
            return $this->items;
        }

        $saved = get_option(self::OPT_ITEMS, null);
        if (is_array($saved) && isset($saved['items']) && is_array($saved['items'])) {
            $this->items = $saved['items'];
            return $this->items;
        }

        // Not saved since upgrading: treat each old entry as a whole section with no temporary access.
        $this->items = array();
        foreach ($this->legacy_allowed() as $slug) {
            $this->items[] = array('parent' => '', 'slug' => $slug, 'cap' => '');
        }
        return $this->items;
    }

    /**
     * Top-level slug => array('checked' => bool, 'cap' => string, 'subs' => array(slug => cap)).
     * A top with checked subpages shows only those subpages. A checked top with none shows the whole section.
     */
    private function get_plan() {
        if (null !== $this->plan) {
            return $this->plan;
        }

        $plan = array();
        foreach ($this->get_items() as $item) {
            $top = '' === $item['parent'] ? $item['slug'] : $item['parent'];
            if (!isset($plan[$top])) {
                $plan[$top] = array('checked' => false, 'cap' => '', 'subs' => array());
            }
            if ('' === $item['parent']) {
                $plan[$top]['checked'] = true;
                $plan[$top]['cap']     = $item['cap'];
            } else {
                $plan[$top]['subs'][$item['slug']] = $item['cap'];
            }
        }

        $this->plan = $plan;
        return $plan;
    }

    /**
     * The pages a Client may open by being checked, each with the capability it asks for.
     */
    private function checked_pages() {
        $pages = array();
        foreach ($this->get_plan() as $top => $p) {
            if (!empty($p['subs'])) {
                foreach ($p['subs'] as $slug => $cap) {
                    $pages[] = array('slug' => $slug, 'parent' => $top, 'cap' => $cap);
                }
            } elseif ($p['checked']) {
                $pages[] = array('slug' => $top, 'parent' => '', 'cap' => $p['cap']);
            }
        }
        return $pages;
    }

    /* ---------------------------------------------------------------
     * Matching the current request to a menu slug
     * ------------------------------------------------------------- */

    /**
     * Returns false if the slug doesn't describe the current admin request, otherwise a score where
     * higher is more specific (edit.php?post_type=page beats edit.php for a Pages request).
     */
    private function request_matches($slug) {
        global $pagenow;

        $slug = html_entity_decode((string) $slug);
        $page = (isset($_GET['page']) && is_string($_GET['page'])) ? wp_unslash($_GET['page']) : null;

        if (false === strpos($slug, '.php')) {
            return (null !== $page && $page === $slug) ? 100 : false;
        }

        $parts = explode('?', $slug, 2);
        if ($parts[0] !== $pagenow) {
            return false;
        }

        $query = array();
        if (isset($parts[1])) {
            wp_parse_str($parts[1], $query);
        }

        // A plugin page request (?page=) is never matched by a plain core screen slug.
        if (null !== $page && !isset($query['page'])) {
            return false;
        }

        foreach ($query as $key => $value) {
            if (!isset($_GET[$key]) || !is_string($_GET[$key]) || (string) wp_unslash($_GET[$key]) !== (string) $value) {
                return false;
            }
        }

        return count($query);
    }

    /**
     * The most specific slug in the list that matches this request, or null.
     */
    private function best_match($slugs) {
        $best  = null;
        $score = -1;
        foreach ($slugs as $slug) {
            $s = $this->request_matches($slug);
            if (false !== $s && $s > $score) {
                $best  = $slug;
                $score = $s;
            }
        }
        return $best;
    }

    /* ---------------------------------------------------------------
     * Temporary page access
     * ------------------------------------------------------------- */

    /**
     * Capabilities the Client role lacks on the pages they're allowed, for registering the menu.
     */
    private function all_elevated_caps() {
        $caps = array();
        foreach ($this->checked_pages() as $page) {
            if (!$this->role_has($page['cap'])) {
                $caps[$page['cap']] = true;
            }
        }
        return array_keys($caps);
    }

    /**
     * Capabilities to grant on the current request: only the cap of the checked page being viewed.
     */
    private function current_request_caps() {
        if (null !== $this->request_caps) {
            return $this->request_caps;
        }

        $this->request_caps = array();
        if (wp_doing_ajax()) {
            return $this->request_caps;
        }

        $pages = $this->checked_pages();
        $match = $this->best_match(wp_list_pluck($pages, 'slug'));
        if (null === $match) {
            return $this->request_caps;
        }

        foreach ($pages as $page) {
            if ($page['slug'] === $match && !$this->role_has($page['cap'])) {
                $this->request_caps[] = $page['cap'];
            }
        }
        return $this->request_caps;
    }

    public function grant_caps($allcaps, $caps, $args, $user) {
        if (!is_admin() || !($user instanceof WP_User) || $user->ID !== get_current_user_id() || !$this->is_client($user)) {
            return $allcaps;
        }

        $grant = $this->in_menu_build ? $this->all_elevated_caps() : $this->current_request_caps();
        foreach ($grant as $cap) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }

    /**
     * WordPress skips registering a subpage the user lacks the capability for, so checked
     * admin-only subpages need the capability while plugins build the menu.
     */
    public function start_menu_build() {
        if ($this->is_client() && $this->get_items()) {
            $this->in_menu_build = true;
        }
    }

    /* ---------------------------------------------------------------
     * Menu filtering and blocking
     * ------------------------------------------------------------- */

    public function filter_menu() {
        $this->in_menu_build = false;

        if (!$this->is_client()) {
            return;
        }

        global $menu, $submenu;
        $plan = $this->get_plan();

        if ($this->is_debugging()) {
            $this->debug['before']  = $this->snapshot();
            $this->debug['allowed'] = array();
            foreach ($this->checked_pages() as $p) {
                $this->debug['allowed'][] = ('' === $p['parent'] ? '' : $p['parent'] . ' > ') . $p['slug'] . ' | ' . $p['cap'];
            }
            $this->debug['removed'] = array();
        }

        if (empty($plan)) {
            return;
        }

        $all  = array();   // every page slug in the menu
        $kept = array();   // slug => URL for pages the Client may open

        foreach ((array) $menu as $i => $item) {
            if (empty($item[2])) {
                continue;
            }
            $raw_top = $item[2];
            $top     = html_entity_decode($raw_top);
            $subs    = isset($submenu[$raw_top]) ? (array) $submenu[$raw_top] : array();
            $is_sep  = isset($item[4]) && false !== strpos($item[4], 'wp-menu-separator');

            if (!$is_sep) {
                $all[$top] = true;
            }
            foreach ($subs as $sub) {
                $all[html_entity_decode($sub[2])] = true;
            }

            if (!isset($plan[$top])) {
                remove_menu_page($raw_top);
                $this->log_removed($top);
                continue;
            }

            $p        = $plan[$top];
            $explicit = !empty($p['subs']);

            foreach ($subs as $idx => $sub) {
                $slug = html_entity_decode($sub[2]);
                $cap  = isset($sub[1]) ? $sub[1] : '';

                if ($explicit) {
                    $keep   = array_key_exists($slug, $p['subs']);
                    $stored = $keep ? $p['subs'][$slug] : null;
                } else {
                    // Whole section: the section's own page plus subpages the role can already open.
                    $keep   = ($slug === $top) || $this->role_has($cap);
                    $stored = ($slug === $top) ? $p['cap'] : null;
                }

                if (!$keep) {
                    unset($submenu[$raw_top][$idx]);
                    $this->log_removed($top . ' > ' . $slug);
                    continue;
                }

                // Open an admin-only page only if it still asks for the capability it had when checked.
                if (!$this->role_has($cap) && $stored === $cap) {
                    $submenu[$raw_top][$idx][1] = 'read';
                }
                $kept[$slug] = $this->page_url($slug, $top);
            }

            if (!$explicit) {
                $kept[$top] = $this->page_url($top, '');
            }

            // Keep the top-level entry visible. With subpages checked, WordPress links it to the first allowed one.
            $top_cap = isset($item[1]) ? $item[1] : '';
            if (!$this->role_has($top_cap) && ($explicit || $p['cap'] === $top_cap)) {
                $menu[$i][1] = 'read';
            }
        }

        foreach ($this->always_open as $slug) {
            unset($all[$slug]);
        }

        $match = $this->best_match(array_keys($all));
        if (null !== $match && !isset($kept[$match])) {
            $this->deny($kept);
        }
    }

    private function page_url($slug, $parent) {
        if (false !== strpos($slug, '.php')) {
            return admin_url($slug);
        }
        if ('' !== $parent && false !== strpos($parent, '.php')) {
            return admin_url(add_query_arg('page', $slug, $parent));
        }
        return admin_url('admin.php?page=' . $slug);
    }

    /**
     * Send a Client away from a page they aren't allowed: to the Dashboard if allowed, else their first page.
     */
    private function deny($kept) {
        $target = isset($kept['index.php']) ? $kept['index.php'] : reset($kept);
        if (!$target) {
            wp_die(esc_html__('You do not have access to this page.'), 403);
        }
        wp_safe_redirect($target);
        exit;
    }

    private function log_removed($label) {
        if ($this->is_debugging()) {
            $this->debug['removed'][] = $label;
        }
    }

    /* ---------------------------------------------------------------
     * Debug view: add ?chcm_debug=1 to any admin URL while logged in as a Client
     * ------------------------------------------------------------- */

    private function is_debugging() {
        return isset($_GET['chcm_debug']) && $this->is_client();
    }

    private function snapshot() {
        global $menu, $submenu;
        $out = array();
        foreach ((array) $menu as $pos => $item) {
            if (empty($item[2])) {
                continue;
            }
            $out[] = $pos . ' | ' . $item[2] . ' | ' . (isset($item[1]) ? $item[1] : '');
            if (!empty($submenu[$item[2]])) {
                foreach ($submenu[$item[2]] as $sub) {
                    $out[] = '      > ' . $sub[2] . ' | ' . (isset($sub[1]) ? $sub[1] : '');
                }
            }
        }
        return $out;
    }

    /**
     * admin_menu callbacks registered after this plugin's filter at the same priority, with file and line.
     * Items they add can escape filtering.
     */
    private function late_callbacks() {
        global $wp_filter;
        $out = array();
        if (empty($wp_filter['admin_menu']) || empty($wp_filter['admin_menu']->callbacks[PHP_INT_MAX])) {
            return array('(none)');
        }

        foreach ($wp_filter['admin_menu']->callbacks[PHP_INT_MAX] as $cb) {
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
            $out[] = $name . ' | ' . $file . ':' . $line;
        }

        return $out ? $out : array('(none)');
    }

    public function render_debug() {
        if (!$this->is_debugging()) {
            return;
        }
        $d    = $this->debug;
        $none = array('(filter did not run)');
        $req  = $this->current_request_caps();

        $sections = array(
            'User roles'                                 => (array) wp_get_current_user()->roles,
            'Checked pages (parent > page | capability)' => isset($d['allowed']) ? ($d['allowed'] ? $d['allowed'] : array('(none, menu not limited)')) : $none,
            'Temporary access on this page'              => $req ? $req : array('(none)'),
            '1. Menu before filtering'                   => isset($d['before']) ? $d['before'] : $none,
            '2. Removed'                                 => !empty($d['removed']) ? $d['removed'] : array('(nothing)'),
            '3. Final menu WordPress is about to render' => $this->snapshot(),
            'Other code that adds menus after this plugin (callback | file:line)' => $this->late_callbacks(),
        );

        echo '<div class="notice notice-info"><p><strong>Client Access debug</strong></p>';
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
        register_setting('chu_client_menu', self::OPT_ITEMS, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_items'),
            'default'           => array('v' => 2, 'items' => array()),
        ));
        register_setting('chu_client_menu', self::OPT_CAPS, array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ));
    }

    /**
     * Checkbox values arrive as JSON {"p": parent, "s": slug, "c": capability}.
     * WordPress can run this twice on the first save, so an already-clean value passes through.
     */
    public function sanitize_items($value) {
        if (is_array($value) && isset($value['v'], $value['items']) && is_array($value['items'])) {
            $value = $value['items'];
            $clean = true;
        } else {
            $clean = false;
        }

        $items = array();
        $seen  = array();
        foreach ((array) $value as $raw) {
            $d = $clean ? array('p' => $raw['parent'], 's' => $raw['slug'], 'c' => $raw['cap']) : json_decode((string) $raw, true);
            if (!is_array($d) || empty($d['s'])) {
                continue;
            }
            $parent = trim(wp_strip_all_tags(html_entity_decode((string) (isset($d['p']) ? $d['p'] : ''))));
            $slug   = trim(wp_strip_all_tags(html_entity_decode((string) $d['s'])));
            $cap    = sanitize_key(isset($d['c']) ? $d['c'] : '');
            $key    = $parent . '|' . $slug;
            if ('' === $slug || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[]    = array('parent' => $parent, 'slug' => $slug, 'cap' => $cap);
        }

        return array('v' => 2, 'items' => $items);
    }

    private function menu_title($raw) {
        return trim(wp_strip_all_tags(preg_replace('/<span.*<\/span>/s', '', (string) $raw)));
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $menu, $submenu;
        $enabled = $this->is_enabled();
        $role    = get_role(self::ROLE);
        $plan    = $this->get_plan();
        $extra   = $this->split(get_option(self::OPT_CAPS, ''));
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
                                    Create the Client role, limit Clients to the pages checked below, and send them to the dashboard after login
                                </label>
                                <?php if (!$enabled) : ?>
                                    <p class="description">The Client role is created when you turn this on and save.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Extra capabilities</th>
                            <td>
                                <textarea name="<?php echo esc_attr(self::OPT_CAPS); ?>" rows="4" class="large-text code"><?php echo esc_textarea(get_option(self::OPT_CAPS, '')); ?></textarea>
                                <p class="description">The Client role starts with Editor capabilities plus Gravity Forms entries. Add capabilities here only when a Client needs them everywhere. For a single admin-only page, check it below instead.</p>
                                <?php if (in_array('manage_options', array_map('sanitize_key', $extra), true)) : ?>
                                    <p class="description" style="color:#b32d2e"><strong>manage_options is listed here.</strong> Users with it are treated as admins, so none of the limits below apply to them. Remove it and check the admin-only pages below instead.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2>Pages Clients can open</h2>
                <p>Check a top-level item to give Clients that whole section, or check individual subpages to give them only those. Anything unchecked is hidden and blocked, even by direct link. Leave everything unchecked to show Clients everything their role allows.</p>
                <p>Pages marked <strong>admin-only</strong> require a capability the Client role doesn't have. When checked, Clients get that capability on that page alone. If a button on such a page fails for a Client, it likely runs as a separate background request; let Click Hive know which one.</p>

                <input type="hidden" name="<?php echo esc_attr(self::OPT_ITEMS); ?>[]" value="">
                <table class="widefat striped chu-access-table" style="max-width:1000px">
                    <thead>
                        <tr>
                            <th style="width:40px"></th>
                            <th>Page</th>
                            <th>Slug</th>
                            <th>Required capability</th>
                            <th>Client role</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ((array) $menu as $item) {
                        if (empty($item[0]) || empty($item[2])) {
                            continue;
                        }
                        $top     = html_entity_decode($item[2]);
                        $top_cap = isset($item[1]) ? $item[1] : '';
                        $checked = isset($plan[$top]) && $plan[$top]['checked'];
                        $this->render_row($this->menu_title($item[0]), '', $top, $top_cap, $checked, $role);

                        if (!empty($submenu[$item[2]])) {
                            foreach ($submenu[$item[2]] as $sub) {
                                $slug    = html_entity_decode($sub[2]);
                                $sub_cap = isset($sub[1]) ? $sub[1] : '';
                                $checked = isset($plan[$top]) && array_key_exists($slug, $plan[$top]['subs']);
                                $this->render_row($this->menu_title($sub[0]), $top, $slug, $sub_cap, $checked, $role);
                            }
                        }
                    }
                    ?>
                    </tbody>
                </table>

                <?php submit_button('Save Client Access'); ?>
            </form>

            <p>To check what a Client sees, log in as one and add <code>?chcm_debug=1</code> to any admin URL.</p>
        </div>
        <script>
        (function () {
            var table = document.querySelector('.chu-access-table');
            if (!table) { return; }
            table.addEventListener('change', function (e) {
                var box = e.target;
                if (!box.matches('input[type="checkbox"]')) { return; }
                var parent = box.getAttribute('data-parent');
                var self   = box.getAttribute('data-slug');
                if (parent) {
                    // Checking a subpage keeps its section in the menu.
                    if (box.checked) {
                        var top = table.querySelector('input[data-top="1"][data-slug="' + CSS.escape(parent) + '"]');
                        if (top) { top.checked = true; }
                    }
                } else if (!box.checked) {
                    table.querySelectorAll('input[data-parent="' + CSS.escape(self) + '"]').forEach(function (sub) { sub.checked = false; });
                }
            });
        })();
        </script>
        <?php
    }

    private function render_row($title, $parent, $slug, $cap, $checked, $role) {
        $value = wp_json_encode(array('p' => $parent, 's' => $slug, 'c' => $cap));
        if (!$role) {
            $status = '<em>Turn on Client Access first</em>';
        } elseif ($this->role_has($cap)) {
            $status = 'Has access';
        } else {
            $status = '<strong style="color:#b32d2e">Admin-only</strong>';
        }
        $indent = '' === $parent ? '' : ' style="padding-left:28px"';
        printf(
            '<tr><td><input type="checkbox" name="%1$s[]" value="%2$s" data-slug="%3$s" data-parent="%4$s" data-top="%5$s" %6$s></td><td%7$s>%8$s</td><td><code>%9$s</code></td><td><code>%10$s</code></td><td>%11$s</td></tr>',
            esc_attr(self::OPT_ITEMS),
            esc_attr($value),
            esc_attr($slug),
            esc_attr($parent),
            '' === $parent ? '1' : '0',
            checked($checked, true, false),
            $indent,
            '' === $parent ? '<strong>' . esc_html($title) . '</strong>' : esc_html($title),
            esc_html($slug),
            esc_html($cap),
            $status
        );
    }
}
