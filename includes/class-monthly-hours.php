<?php
/**
 * Monthly Hours
 *
 * Receives each month's hours from Click Hive's nightly Make scenario and shows them to the
 * client as a dashboard widget: the contract line items with their monthly budget, and the
 * hours logged against each one.
 *
 * Make sends the contract budgets (from the client's ClickUp folder fields) and the month's
 * hours grouped by ClickUp task tag. This class decides where the hours land: a tag that
 * matches a line item on the contract goes to that line, and every other tag goes to Available.
 * Keeping that rule here keeps the Make scenario simple.
 *
 * Make authenticates with a per-site key generated on Click Hive > Monthly Hours.
 *
 * Off by default. While off, the widget is hidden and the endpoint refuses every update.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CHU_Monthly_Hours {

    const OPT_DATA   = 'chu_monthly_hours';
    const OPT_KEY    = 'chu_monthly_hours_key';
    const OPT_ON     = 'chu_monthly_hours_enabled';
    const GROUP      = 'chu_monthly_hours';
    const SLUG       = 'chu-monthly-hours';
    const WIDGET_ID  = 'chu_monthly_hours_widget';
    const NS         = 'click-hive/v1';
    const ROUTE      = '/hours';
    const AVAILABLE  = 'Available';
    const MAX_MONTHS = 12;

    private $page_hook = '';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_init', array($this, 'register'));
        add_action('admin_post_chu_monthly_hours_key', array($this, 'regenerate_key'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));

        if (self::is_enabled()) {
            add_action('rest_api_init', array($this, 'routes'));
            add_action('wp_dashboard_setup', array($this, 'widget'));
        }
    }

    public static function is_enabled() {
        return '1' === get_option(self::OPT_ON, '0');
    }

    public function register() {
        register_setting(self::GROUP, self::OPT_ON, array(
            'type'              => 'string',
            'sanitize_callback' => function ($value) {
                return '1' === $value ? '1' : '0';
            },
            'default'           => '0',
        ));
    }

    /**
     * ClickUp task tags that count as billable activities. A time entry counts only when its task
     * carries exactly one of these (or "internal", which is never shown). Anything else, like
     * seo-plan-2026-11, is ignored when deciding.
     */
    public static function billing_tags() {
        return apply_filters('chu_monthly_hours_billing_tags', array('seo', 'ads management', 'consulting', 'web development', 'website changes'));
    }

    /**
     * Sum raw ClickUp time entries into hours per billing tag.
     *
     * @param array $entries List of array('d' => duration in ms, 't' => 'tag|tag|...').
     * @return array array('hours' => tag => hours, 'unsorted' => hours with no single billing tag).
     */
    public static function sum_entries($entries) {
        $billing  = array_map('strtolower', self::billing_tags());
        $known    = array_merge($billing, array('internal'));
        $hours    = array();
        $unsorted = 0.0;

        foreach (array_slice((array) $entries, 0, 5000) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ms = isset($entry['d']) && is_numeric($entry['d']) ? (float) $entry['d'] : 0;
            if ($ms <= 0) {
                continue; // Empty rows and timers still running.
            }
            $tags    = array_filter(array_map('trim', explode('|', strtolower(isset($entry['t']) ? (string) $entry['t'] : ''))), 'strlen');
            $matched = array_values(array_unique(array_intersect($tags, $known)));
            $h       = $ms / 3600000;

            if (1 !== count($matched)) {
                $unsorted += $h;
            } elseif ('internal' !== $matched[0]) {
                $hours[$matched[0]] = (isset($hours[$matched[0]]) ? $hours[$matched[0]] : 0) + $h;
            }
        }
        return array('hours' => $hours, 'unsorted' => round($unsorted, 2));
    }

    /**
     * Contract line items that never carry hours (shown with the budget only).
     */
    public static function budget_only_lines() {
        return apply_filters('chu_monthly_hours_budget_only_lines', array('Hosting'));
    }

    /* ---------------------------------------------------------------------------------------
     * Data
     * ------------------------------------------------------------------------------------- */

    public static function months() {
        $data = get_option(self::OPT_DATA, array());
        if (!is_array($data)) {
            return array();
        }
        krsort($data);
        return $data;
    }

    /**
     * Assign each tag's hours to a contract line.
     *
     * @param array $budgets      Ordered list of array('name' => string, 'budget' => float).
     * @param array $hours_by_tag Map of ClickUp task tag => hours.
     * @return array Ordered rows of array('name', 'budget' => float|null, 'hours' => float|null).
     */
    public static function allocate($budgets, $hours_by_tag) {
        $budget_only = array_map('strtolower', self::budget_only_lines());
        $rows        = array();
        $index       = array();

        foreach ($budgets as $line) {
            $key = strtolower($line['name']);
            if (isset($index[$key])) {
                continue;
            }
            $index[$key] = count($rows);
            $rows[]      = array(
                'name'   => $line['name'],
                'budget' => $line['budget'],
                'hours'  => in_array($key, $budget_only, true) ? null : 0.0,
            );
        }

        $available = strtolower(self::AVAILABLE);

        foreach ($hours_by_tag as $tag => $hours) {
            $key = strtolower(trim((string) $tag));
            if ('' === $key || $hours <= 0) {
                continue;
            }
            if ($key === $available || !isset($index[$key]) || in_array($key, $budget_only, true)) {
                $key = $available;
            }
            if (!isset($index[$key])) {
                // Hours fell outside the contract and there is no Available line: show one with no budget.
                $index[$key] = count($rows);
                $rows[]      = array('name' => self::AVAILABLE, 'budget' => null, 'hours' => 0.0);
            }
            $rows[$index[$key]]['hours'] += $hours;
        }

        // Available always reads last.
        if (isset($index[$available])) {
            $row = $rows[$index[$available]];
            unset($rows[$index[$available]]);
            $rows[] = $row;
        }

        foreach ($rows as &$row) {
            if (null !== $row['hours']) {
                $row['hours'] = round($row['hours'], 2);
            }
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * Validate and normalize a payload from Make. Returns array or WP_Error.
     */
    public static function clean_payload($p) {
        if (!is_array($p)) {
            return new WP_Error('chu_hours_body', 'Body must be a JSON object.', array('status' => 400));
        }

        $month = isset($p['month']) ? (string) $p['month'] : '';
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return new WP_Error('chu_hours_month', 'month must look like 2026-11.', array('status' => 400));
        }

        $budgets = array();
        $raw     = (isset($p['budgets']) && is_array($p['budgets'])) ? $p['budgets'] : array();
        // Accept either [{name, budget}, ...] or {"SEO": 300, ...}.
        foreach (array_slice($raw, 0, 20, true) as $k => $v) {
            if (is_array($v)) {
                $name   = isset($v['name']) ? $v['name'] : '';
                $amount = isset($v['budget']) ? $v['budget'] : null;
            } else {
                $name   = $k;
                $amount = $v;
            }
            $name = sanitize_text_field((string) $name);
            if ('' === $name || '' === $amount || null === $amount || !is_numeric($amount) || $amount < 0) {
                continue;
            }
            $budgets[] = array('name' => substr($name, 0, 60), 'budget' => round((float) $amount, 2));
        }

        $hours = array();
        $raw   = (isset($p['hours_by_tag']) && is_array($p['hours_by_tag'])) ? $p['hours_by_tag'] : array();
        // Accept either {"SEO": 3.5, ...} or [{tag, hours}, ...].
        foreach (array_slice($raw, 0, 50, true) as $k => $v) {
            if (is_array($v)) {
                $tag = isset($v['tag']) ? $v['tag'] : '';
                $h   = isset($v['hours']) ? $v['hours'] : 0;
            } else {
                $tag = $k;
                $h   = $v;
            }
            $tag = substr(sanitize_text_field((string) $tag), 0, 60);
            if ('' === $tag || !is_numeric($h) || $h < 0 || $h > 1000) {
                continue;
            }
            $hours[$tag] = (isset($hours[$tag]) ? $hours[$tag] : 0) + (float) $h;
        }

        // Raw ClickUp time entries from Make, summed here.
        $unsorted = 0.0;
        if (isset($p['entries']) && is_array($p['entries'])) {
            $summed   = self::sum_entries($p['entries']);
            $unsorted = $summed['unsorted'];
            foreach ($summed['hours'] as $tag => $h) {
                $hours[$tag] = (isset($hours[$tag]) ? $hours[$tag] : 0) + $h;
            }
        }

        return array(
            'month'        => $month,
            'budgets'      => $budgets,
            'hours_by_tag' => $hours,
            'unsorted'     => $unsorted,
            'received'     => time(),
        );
    }

    /* ---------------------------------------------------------------------------------------
     * REST endpoint for Make
     * ------------------------------------------------------------------------------------- */

    public function routes() {
        register_rest_route(self::NS, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array($this, 'receive'),
            'permission_callback' => array($this, 'authorized'),
        ));
    }

    public function authorized(WP_REST_Request $request) {
        $key  = (string) get_option(self::OPT_KEY, '');
        $sent = (string) $request->get_header('x_click_hive_key');
        if ('' === $key || '' === $sent || !hash_equals($key, $sent)) {
            return new WP_Error('chu_hours_key', 'Missing or wrong X-Click-Hive-Key header.', array('status' => 401));
        }
        return true;
    }

    public function receive(WP_REST_Request $request) {
        $clean = self::clean_payload($request->get_json_params());
        if (is_wp_error($clean)) {
            return $clean;
        }

        $data = self::months();
        $data[$clean['month']] = $clean;
        krsort($data);
        $data = array_slice($data, 0, self::MAX_MONTHS, true);
        update_option(self::OPT_DATA, $data, false);

        return rest_ensure_response(array(
            'ok'    => true,
            'month' => $clean['month'],
            'rows'     => self::allocate($clean['budgets'], $clean['hours_by_tag']),
            'unsorted' => $clean['unsorted'],
        ));
    }

    /* ---------------------------------------------------------------------------------------
     * Admin settings page (Click Hive > Monthly Hours)
     * ------------------------------------------------------------------------------------- */

    public function menu() {
        $this->page_hook = (string) add_submenu_page(
            'click-hive-utility',
            'Monthly Hours',
            'Monthly Hours',
            'manage_options',
            self::SLUG,
            array($this, 'page')
        );
    }

    public static function key() {
        $key = (string) get_option(self::OPT_KEY, '');
        if ('' === $key) {
            $key = wp_generate_password(40, false, false);
            update_option(self::OPT_KEY, $key, false);
        }
        return $key;
    }

    public function regenerate_key() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('chu_monthly_hours_key');
        delete_option(self::OPT_KEY);
        self::key();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&key-updated=1'));
        exit;
    }

    public function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $enabled = self::is_enabled();
        $months  = self::months();

        echo '<div class="wrap chu-hours-admin"><h1>Monthly Hours</h1>';
        if (isset($_GET['key-updated'])) {
            echo '<div class="notice notice-success"><p>New key created. Paste it into this client\'s Dashboard Key field in ClickUp, or the nightly sync will stop updating this site.</p></div>';
        }
        settings_errors();
        echo '<p>Click Hive\'s nightly Make scenario sends this site its hours from ClickUp. Clients see them in the Monthly Hours widget on Dashboard &gt; Home once the first update arrives.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<input type="hidden" name="' . esc_attr(self::OPT_ON) . '" value="0">';
        echo '<p><label><input type="checkbox" name="' . esc_attr(self::OPT_ON) . '" value="1"' . checked($enabled, true, false) . '> <strong>Enable Monthly Hours</strong> (the widget on Dashboard &gt; Home and the connection Make uses to send hours)</label></p>';
        submit_button('Save', 'primary', 'submit', false);
        echo '</form>';

        if (!$enabled) {
            echo '<p class="description">Off on this site. Nothing is shown to clients and updates from Make are refused. Turn it on and save to get this site\'s Dashboard Key.</p></div>';
            return;
        }
        $key = self::key();

        echo '<h2>Connection</h2><table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Endpoint</th><td><code>' . esc_html(rest_url(self::NS . self::ROUTE)) . '</code></td></tr>';
        echo '<tr><th scope="row">Dashboard Key</th><td><input type="text" class="regular-text code" readonly value="' . esc_attr($key) . '" onfocus="this.select()">';
        echo '<p class="description">Copy this into the Dashboard Key field on the client\'s folder in ClickUp.</p></td></tr>';
        echo '<tr><th scope="row">Last update</th><td>';
        if ($months) {
            $latest = reset($months);
            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $latest['received']));
        } else {
            echo 'Nothing received yet.';
        }
        echo '</td></tr></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="chu_monthly_hours_key">';
        wp_nonce_field('chu_monthly_hours_key');
        submit_button('Create a new key', 'secondary', 'submit', false, array('onclick' => "return confirm('The old key stops working right away. Continue?');"));
        echo '</form>';

        if ($months) {
            echo '<h2>Preview</h2>';
            $this->render();
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------------------------------
     * Client-facing widget
     * ------------------------------------------------------------------------------------- */

    public function widget() {
        if (current_user_can('read') && self::months()) {
            wp_add_dashboard_widget(self::WIDGET_ID, 'Monthly Hours', array($this, 'render'), null, null, 'normal', 'high');
        }
    }

    public function assets($hook) {
        if ('index.php' === $hook || $hook === $this->page_hook) {
            wp_enqueue_style('chu-monthly-hours', CHU_PLUGIN_URL . 'assets/css/monthly-hours.css', array(), CHU_VERSION);
        }
    }

    private static function month_label($month) {
        $ts = strtotime($month . '-01 12:00:00');
        return $ts ? wp_date('F Y', $ts) : $month;
    }

    private static function money($amount) {
        if (null === $amount) {
            return 'Not included';
        }
        $decimals = (floor($amount) == $amount) ? 0 : 2;
        return '$' . number_format_i18n($amount, $decimals);
    }

    private static function hours($hours) {
        if (null === $hours) {
            return 'n/a';
        }
        return rtrim(rtrim(number_format_i18n($hours, 2), '0'), '.,') ?: '0';
    }

    private static function table($month) {
        $rows = self::allocate($month['budgets'], $month['hours_by_tag']);
        if (!$rows) {
            return '<p>No contract items or hours for this month.</p>';
        }
        $html = '<table class="chu-hours-table"><thead><tr><th scope="col">Item</th><th scope="col">Monthly contract</th><th scope="col">Hours used</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><th scope="row">' . esc_html($row['name']) . '</th><td>' . esc_html(self::money($row['budget'])) . '</td><td>' . esc_html(self::hours($row['hours'])) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    public function render() {
        if (!current_user_can('read')) {
            return;
        }
        $months = self::months();
        if (!$months) {
            echo '<p>Hours will appear here after the first nightly update.</p>';
            return;
        }

        $current = reset($months);
        $key     = key($months);

        echo '<div class="chu-hours">';
        echo '<h3>' . esc_html(self::month_label($key)) . '</h3>';
        echo self::table($current); // Escaped inside table().
        echo '<p class="chu-hours-updated">Updated ' . esc_html(wp_date(get_option('date_format'), $current['received'])) . '. Hours from Click Hive\'s time tracking, refreshed nightly.</p>';

        $previous = array_slice($months, 1, null, true);
        if ($previous) {
            echo '<h3>Previous months</h3>';
            foreach ($previous as $m => $month) {
                echo '<details class="chu-hours-month"><summary>' . esc_html(self::month_label($m)) . '</summary>' . self::table($month) . '</details>';
            }
        }
        echo '</div>';
    }
}
