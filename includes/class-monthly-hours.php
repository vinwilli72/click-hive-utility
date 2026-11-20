<?php
/**
 * Monthly Hours
 *
 * Receives each month's hours from Click Hive's nightly Make scenario and shows them to the
 * client for the current month only (time doesn't roll over): hourly items as hours included,
 * used and remaining; fixed items (Hosting, Phone System) as their price; Available as a dollar
 * budget; and any overage past it.
 *
 * Make sends the contract budgets (from the client's ClickUp folder fields), an optional short
 * note for each contract item, and the month's hours grouped by ClickUp task tag. This class decides where the hours land
 * (see summarize()) and holds the hourly rates (rates()), which keeps the Make scenario simple.
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

    /** True while drawing the preview on Click Hive > Monthly Hours, which also lists past months. */
    private $showing_admin_preview = false;

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_init', array($this, 'register'));
        add_action('admin_post_chu_monthly_hours_key', array($this, 'regenerate_key'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));

        if (self::is_enabled()) {
            add_action('rest_api_init', array($this, 'routes'));
            add_action('wp_dashboard_setup', array($this, 'widget'));
            add_action('chu_client_dashboard_after_links', array($this, 'client_dashboard_section'));
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
        return apply_filters('chu_monthly_hours_budget_only_lines', array('Hosting', 'Phone System'));
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
     * Hourly rates by ClickUp billing tag. A contract item whose name matches a tag (SEO, Ads
     * Management, Consulting) is shown in hours at that rate, and hours that land in Available
     * are turned into dollars at their tag's rate.
     */
    public static function rates() {
        return apply_filters('chu_monthly_hours_rates', array(
            'seo'             => 75,
            'ads management'  => 75,
            'consulting'      => 150,
            'web development' => 125,
            'website changes' => 45,
        ));
    }

    /**
     * Turn a month's budgets and hours into the rows clients see.
     *
     * - Fixed items (Hosting, Phone System) show their price only.
     * - Items with an hourly rate show hours included (price / rate), used and remaining. Hours
     *   past what's included spill into Available at that item's rate.
     * - Every other billing tag's hours count toward Available, in dollars at that tag's rate.
     * - Available shows its dollar budget, used and remaining. Whatever goes past it, or all of
     *   it when the contract has no Available budget, is the month's overage.
     *
     * @param array $budgets      Ordered list of array('name' => string, 'budget' => float, 'note' => string).
     * @param array $hours_by_tag Map of ClickUp task tag => hours.
     * @return array array('rows' => list of rows, 'overage' => dollars). Each row has 'name', 'type'
     *               (fixed|hours|dollars), 'note', 'included', 'used' and 'remaining' (null where it
     *               doesn't apply). Hours rows count hours; fixed and dollars rows count dollars.
     */
    public static function summarize($budgets, $hours_by_tag) {
        $fixed     = array_map('strtolower', self::budget_only_lines());
        $rates     = array_change_key_case(self::rates(), CASE_LOWER);
        $available = strtolower(self::AVAILABLE);

        $hours = array();
        foreach ((array) $hours_by_tag as $tag => $h) {
            $key = strtolower(trim((string) $tag));
            if ('' !== $key && $h > 0) {
                $hours[$key] = (isset($hours[$key]) ? $hours[$key] : 0) + (float) $h;
            }
        }

        $rows       = array();
        $seen       = array();
        $avail_line = null;
        $spill      = 0.0; // Dollars that go to Available.

        foreach ((array) $budgets as $line) {
            $key = strtolower($line['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $note       = isset($line['note']) ? (string) $line['note'] : '';

            if ($key === $available) {
                $avail_line = array('budget' => $line['budget'], 'note' => $note);
                continue;
            }
            if (in_array($key, $fixed, true)) {
                $rows[] = array('name' => $line['name'], 'type' => 'fixed', 'note' => $note, 'included' => $line['budget'], 'used' => null, 'remaining' => null);
                continue;
            }
            if (isset($rates[$key]) && $rates[$key] > 0) {
                $rate     = (float) $rates[$key];
                $included = round($line['budget'] / $rate, 2);
                $used     = isset($hours[$key]) ? $hours[$key] : 0.0;
                unset($hours[$key]);
                $spill   += max(0, $used - $included) * $rate;
                $rows[]   = array('name' => $line['name'], 'type' => 'hours', 'note' => $note, 'included' => $included, 'used' => round($used, 2), 'remaining' => round(max(0, $included - $used), 2));
                continue;
            }
            // A contract item with no rate and no fixed price: show the price and any hours on its tag.
            $used = isset($hours[$key]) ? round($hours[$key], 2) : null;
            unset($hours[$key]);
            $rows[] = array('name' => $line['name'], 'type' => 'fixed', 'note' => $note, 'included' => $line['budget'], 'used' => $used, 'remaining' => null);
        }

        // Everything left (development, website changes, tags with no contract item) is Available work.
        foreach ($hours as $key => $h) {
            if (isset($rates[$key])) {
                $spill += $h * (float) $rates[$key];
            }
        }
        $spill = round($spill, 2);

        $overage = $spill;
        if (null !== $avail_line) {
            $budget  = (float) $avail_line['budget'];
            $overage = round(max(0, $spill - $budget), 2);
            $rows[]  = array('name' => self::AVAILABLE, 'type' => 'dollars', 'note' => $avail_line['note'], 'included' => $budget, 'used' => $spill, 'remaining' => round(max(0, $budget - $spill), 2));
        }

        return array('rows' => $rows, 'overage' => $overage);
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

        // Optional notes per contract item: {"SEO": "Google Business Profile management", ...}.
        $notes = array();
        if (isset($p['notes']) && is_array($p['notes'])) {
            foreach (array_slice($p['notes'], 0, 20, true) as $k => $v) {
                if (is_scalar($v)) {
                    $notes[strtolower(sanitize_text_field((string) $k))] = self::clean_note($v);
                }
            }
        }

        $budgets = array();
        $raw     = (isset($p['budgets']) && is_array($p['budgets'])) ? $p['budgets'] : array();
        // Accept either [{name, budget, note}, ...] or {"SEO": 300, ...}.
        foreach (array_slice($raw, 0, 20, true) as $k => $v) {
            $note = '';
            if (is_array($v)) {
                $name   = isset($v['name']) ? $v['name'] : '';
                $amount = isset($v['budget']) ? $v['budget'] : null;
                $note   = isset($v['note']) && is_scalar($v['note']) ? self::clean_note($v['note']) : '';
            } else {
                $name   = $k;
                $amount = $v;
            }
            $name = sanitize_text_field((string) $name);
            if ('' === $name || '' === $amount || null === $amount || !is_numeric($amount) || $amount < 0) {
                continue;
            }
            if ('' === $note && isset($notes[strtolower($name)])) {
                $note = $notes[strtolower($name)];
            }
            $budgets[] = array('name' => substr($name, 0, 60), 'budget' => round((float) $amount, 2), 'note' => $note);
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

    /**
     * A contract item's note: plain text, one line, at most 300 characters.
     */
    private static function clean_note($note) {
        $note = trim(preg_replace('/\s+/', ' ', sanitize_text_field((string) $note)));
        return function_exists('mb_substr') ? mb_substr($note, 0, 300) : substr($note, 0, 300);
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
            'summary'  => self::summarize($clean['budgets'], $clean['hours_by_tag']),
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
            $this->showing_admin_preview = true;
            $this->render();
            $this->showing_admin_preview = false;
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

    /**
     * Show the hours on the full Client Dashboard page, where Clients land after login.
     * Dashboard > Home already has its own Monthly Hours widget, so the Home version of the
     * Client Dashboard leaves it out.
     */
    public function client_dashboard_section($full_page) {
        if (!$full_page || !current_user_can('read') || !self::months()) {
            return;
        }
        echo '<h2>Monthly Hours</h2><section class="chm-card chu-hours-card">';
        $this->render();
        echo '</section>';
    }

    public function assets($hook) {
        if ('index.php' === $hook || 'dashboard_page_chm-client-dashboard' === $hook || $hook === $this->page_hook) {
            wp_enqueue_style('chu-monthly-hours', CHU_PLUGIN_URL . 'assets/css/monthly-hours.css', array(), CHU_VERSION);
        }
    }

    private static function month_label($month) {
        $ts = strtotime($month . '-01 12:00:00');
        return $ts ? wp_date('F Y', $ts) : $month;
    }

    private static function money($amount) {
        $decimals = (floor($amount) == $amount) ? 0 : 2;
        return '$' . number_format_i18n($amount, $decimals);
    }

    private static function hours($hours) {
        $n = rtrim(rtrim(number_format_i18n($hours, 2), '0'), '.,') ?: '0';
        return $n . ' ' . (1.0 === (float) $hours ? 'hr' : 'hrs');
    }

    private static function table($month) {
        $summary = self::summarize($month['budgets'], $month['hours_by_tag']);
        if (!$summary['rows'] && $summary['overage'] <= 0) {
            return '<p>No contract items or hours for this month.</p>';
        }
        $dash = '<span aria-label="Not applicable">&mdash;</span>';
        $html = '<table class="chu-hours-table"><thead><tr><th scope="col">Item</th><th scope="col">Included</th><th scope="col">Used</th><th scope="col">Remaining</th></tr></thead><tbody>';
        foreach ($summary['rows'] as $row) {
            $note = '' !== $row['note'] ? '<span class="chu-hours-note">' . esc_html($row['note']) . '</span>' : '';
            if ('hours' === $row['type']) {
                $cells = array(self::hours($row['included']), self::hours($row['used']), self::hours($row['remaining']));
                $cells = array_map('esc_html', $cells);
            } elseif ('dollars' === $row['type']) {
                $cells = array_map('esc_html', array(self::money($row['included']), self::money($row['used']), self::money($row['remaining'])));
            } else {
                $cells = array(esc_html(self::money($row['included'])) . ' <span class="chu-hours-fixed">(fixed)</span>', null === $row['used'] ? $dash : esc_html(self::hours($row['used'])), $dash);
            }
            $html .= '<tr class="chu-hours-' . esc_attr($row['type']) . '"><th scope="row">' . esc_html($row['name']) . $note . '</th><td>' . implode('</td><td>', $cells) . '</td></tr>';
        }
        if ($summary['overage'] > 0) {
            $html .= '<tr class="chu-hours-overage"><th scope="row">Overage<span class="chu-hours-note">Work beyond this month\'s contract, billed separately.</span></th><td>' . $dash . '</td><td>' . esc_html(self::money($summary['overage'])) . '</td><td>' . $dash . '</td></tr>';
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
        echo '<p class="chu-hours-updated">Hours and budgets reset on the 1st of each month and don\'t roll over. Updated ' . esc_html(wp_date(get_option('date_format'), $current['received'])) . ' from Click Hive\'s time tracking.</p>';

        // Past months are for Click Hive only: clients see the current month, since time doesn't roll over.
        $previous = $this->showing_admin_preview ? array_slice($months, 1, null, true) : array();
        if ($previous) {
            echo '<h3>Previous months (Click Hive only)</h3>';
            foreach ($previous as $m => $month) {
                echo '<details class="chu-hours-month"><summary>' . esc_html(self::month_label($m)) . '</summary>' . self::table($month) . '</details>';
            }
        }
        echo '</div>';
    }
}
