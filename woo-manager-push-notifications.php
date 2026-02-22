<?php
/**
 * Plugin Name: Woo Manager Push Notifications
 * Description: Sends push notifications to Woo Manager App via central backend when a new order is received.
 * Version: 1.1.0
 * Author: Dimosthenis Nikolis
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Auto-updates via GitHub ─────────────────────────────────
require __DIR__ . '/vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$wooManagerUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Dimosthenis/woo-manager-push-notifications/',
    __FILE__,
    'woo-manager-push-notifications'
);
$wooManagerUpdateChecker->setBranch('main');
$wooManagerUpdateChecker->getVcsApi()->enableReleaseAssets();
$wooManagerUpdateChecker->addResultFilter(function ($pluginInfo) {
    $icon_url = plugin_dir_url(__FILE__) . 'assets/icon.png';
    $pluginInfo->icons = [
        '1x'      => $icon_url,
        'default' => $icon_url,
    ];
    return $pluginInfo;
});

// ── Cloud Function endpoint ─────────────────────────────────
define('WOO_MANAGER_API_URL', 'https://us-central1-woomanager-8557f.cloudfunctions.net');

// ── Admin Settings Page ─────────────────────────────────────

add_action('admin_menu', 'woo_manager_add_settings_page');
add_action('admin_init', 'woo_manager_register_settings');

function woo_manager_add_settings_page()
{
    add_options_page(
        'Woo Manager',
        'Woo Manager',
        'manage_options',
        'woo-manager',
        'woo_manager_settings_page_html'
    );
}

function woo_manager_register_settings()
{
    register_setting('woo_manager_settings', 'woo_manager_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
}

function woo_manager_get_system_checks()
{
    $checks = [];

    // WooCommerce active
    $checks[] = [
        'label' => 'WooCommerce',
        'passed' => class_exists('WooCommerce'),
        'message' => class_exists('WooCommerce') ? 'Active' : 'Not detected',
        'help_url' => 'https://wordpress.org/plugins/woocommerce/',
    ];

    // PHP 7.4+
    $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
    $checks[] = [
        'label' => 'PHP Version',
        'passed' => $php_ok,
        'message' => 'PHP ' . PHP_VERSION,
        'help_url' => 'https://www.php.net/supported-versions.php',
    ];

    // SSL / HTTPS
    $checks[] = [
        'label' => 'SSL / HTTPS',
        'passed' => is_ssl(),
        'message' => is_ssl() ? 'Enabled' : 'Not detected',
        'help_url' => 'https://developer.wordpress.org/advanced-administration/security/https/',
    ];

    // REST API accessible
    $rest_url = get_rest_url();
    $rest_ok = !empty($rest_url);
    $checks[] = [
        'label' => 'REST API',
        'passed' => $rest_ok,
        'message' => $rest_ok ? 'Available' : 'Unavailable',
        'help_url' => 'https://developer.wordpress.org/rest-api/',
    ];

    // API key configured
    $has_key = !empty(get_option('woo_manager_api_key', ''));
    $checks[] = [
        'label' => 'API Key',
        'passed' => $has_key,
        'message' => $has_key ? 'Configured' : 'Not set',
        'help_url' => 'https://Dimosthenis.github.io/woomanager/getting-started/api-keys/',
    ];

    return $checks;
}

function woo_manager_settings_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $api_key = get_option('woo_manager_api_key', '');
    $checks = woo_manager_get_system_checks();
    $all_passed = !in_array(false, array_column($checks, 'passed'), true);
    $status_html = '';

    if (!empty($api_key)) {
        $response = wp_remote_post(WOO_MANAGER_API_URL . '/status', [
            'body' => wp_json_encode(['apiKey' => $api_key]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $code = wp_remote_retrieve_response_code($response);

            if ($code === 200 && isset($body['active'])) {
                if ($body['active']) {
                    $days = intval($body['daysRemaining']);
                    $status_html = '<span style="color: #00a32a; font-weight: 600;">&#9679; Active</span>'
                        . ' &mdash; Notifications active (' . $days . ' days remaining)';
                } else {
                    $status_html = '<span style="color: #d63638; font-weight: 600;">&#9679; Expired</span>'
                        . ' &mdash; Subscription expired. Open the Woo Manager app to renew.';
                }
            } elseif ($code === 404) {
                $status_html = '<span style="color: #dba617; font-weight: 600;">&#9679; Not found</span>'
                    . ' &mdash; API key not recognized. Check that the key is correct.';
            } else {
                $status_html = '<span style="color: #888;">Could not check status.</span>';
            }
        } else {
            $status_html = '<span style="color: #888;">Could not reach notification server.</span>';
        }
    }

    ?>
    <style>
        .wm-wrap { max-width: 700px; }
        .wm-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .wm-card h2 {
            margin: 0 0 16px;
            padding: 0;
            font-size: 15px;
            font-weight: 600;
            color: #1d2327;
        }
        .wm-checks {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .wm-checks li {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f1;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        .wm-checks li:last-child { border-bottom: none; }
        .wm-check-icon {
            flex-shrink: 0;
            width: 20px;
            text-align: center;
            font-size: 15px;
        }
        .wm-check-pass .wm-check-icon { color: #00a32a; }
        .wm-check-fail .wm-check-icon { color: #d63638; }
        .wm-check-label { font-weight: 500; min-width: 120px; }
        .wm-check-message { color: #50575e; }
        .wm-check-help { margin-left: auto; }
        .wm-check-help a { text-decoration: none; font-size: 12px; }
        .wm-status-bar {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 500;
        }
        .wm-status-bar-pass {
            background: #edfaef;
            border: 1px solid #00a32a;
            color: #00a32a;
        }
        .wm-status-bar-fail {
            background: #fcf0f1;
            border: 1px solid #d63638;
            color: #d63638;
        }
        .wm-field-row { margin-bottom: 16px; }
        .wm-field-row label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .wm-field-row .description { margin-top: 8px; }
        .wm-links {
            display: flex;
            gap: 24px;
            font-size: 13px;
        }
        .wm-links a { text-decoration: none; }
        .wm-links a:hover { text-decoration: underline; }
    </style>

    <div class="wrap wm-wrap">
        <h1>Woo Manager</h1>

        <!-- Card A: System Status -->
        <div class="wm-card">
            <h2>System Status</h2>
            <ul class="wm-checks">
                <?php foreach ($checks as $check): ?>
                    <li class="<?php echo $check['passed'] ? 'wm-check-pass' : 'wm-check-fail'; ?>">
                        <span class="wm-check-icon"><?php echo $check['passed'] ? '&#10003;' : '&#10007;'; ?></span>
                        <span class="wm-check-label"><?php echo esc_html($check['label']); ?></span>
                        <span class="wm-check-message"><?php echo esc_html($check['message']); ?></span>
                        <?php if (!$check['passed']): ?>
                            <span class="wm-check-help">
                                <a href="<?php echo esc_url($check['help_url']); ?>" target="_blank" rel="noopener">Fix &rarr;</a>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($all_passed): ?>
                <div class="wm-status-bar wm-status-bar-pass">&#10003; All checks passed</div>
            <?php else: ?>
                <div class="wm-status-bar wm-status-bar-fail">&#10007; Some checks need attention</div>
            <?php endif; ?>
        </div>

        <!-- Card B: Push Notifications -->
        <div class="wm-card">
            <h2>Push Notifications</h2>
            <form method="post" action="options.php">
                <?php settings_fields('woo_manager_settings'); ?>
                <div class="wm-field-row">
                    <label for="woo_manager_api_key">API Key</label>
                    <input type="text" id="woo_manager_api_key" name="woo_manager_api_key"
                        value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                    <p class="description">
                        <?php if (empty($api_key)): ?>
                            Enter the API key from the Woo Manager app to enable push notifications.
                        <?php else: ?>
                            <?php echo wp_kses_post($status_html); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php submit_button('Save API Key'); ?>
            </form>
        </div>

        <!-- Card C: Links -->
        <div class="wm-card">
            <div class="wm-links">
                <a href="https://Dimosthenis.github.io/woomanager/" target="_blank" rel="noopener">Documentation &rarr;</a>
                <a href="https://Dimosthenis.github.io/woomanager/troubleshooting/" target="_blank" rel="noopener">Need help? &rarr;</a>
            </div>
        </div>
    </div>
    <?php
}

// ── REST API: Site Info ─────────────────────────────────────

add_action('rest_api_init', 'woo_manager_register_rest_routes');

function woo_manager_register_rest_routes()
{
    register_rest_route('wc/v3', '/woo-manager/site-info', [
        'methods' => 'GET',
        'callback' => 'woo_manager_site_info',
        'permission_callback' => 'woo_manager_site_info_permission',
    ]);
}

function woo_manager_site_info_permission()
{
    // WooCommerce authenticates API key requests on wc/ namespace endpoints
    // and sets the current user before permission_callback runs.
    return current_user_can('manage_woocommerce');
}

function woo_manager_site_info()
{
    return rest_ensure_response([
        'login_url' => wp_login_url(),
        'admin_url' => admin_url(),
    ]);
}

// ── Push Notifications ──────────────────────────────────────

add_action('woocommerce_new_order', 'woo_manager_on_new_order', 10, 1);
add_action('woocommerce_order_status_changed', 'woo_manager_on_status_changed', 10, 3);

function woo_manager_on_new_order($order_id)
{
    woo_manager_send_notification($order_id, 'new_order');
}

function woo_manager_on_status_changed($order_id, $old_status, $new_status)
{
    woo_manager_send_notification($order_id, 'status_changed');
}

function woo_manager_send_notification($order_id, $event = 'new_order')
{
    if (!$order_id)
        return;

    $api_key = get_option('woo_manager_api_key');
    if (empty($api_key))
        return;

    $order = wc_get_order($order_id);
    if (!$order)
        return;

    $payload = [
        'apiKey' => $api_key,
        'event' => $event,
        'order' => [
            'id' => $order->get_id(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => $order->get_status(),
        ],
    ];

    $response = wp_remote_post(WOO_MANAGER_API_URL . '/notify', [
        'body' => wp_json_encode($payload),
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'timeout' => 5,
        'blocking' => false,
    ]);

    if (is_wp_error($response)) {
        error_log('Woo Manager: Notification error - ' . $response->get_error_message());
    }
}
