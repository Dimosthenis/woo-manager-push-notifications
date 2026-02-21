<?php
/**
 * Plugin Name: Woo Manager Push Notifications
 * Description: Sends push notifications to Woo Manager App via central backend when a new order is received.
 * Version: 1.0.1
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

function woo_manager_settings_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $api_key = get_option('woo_manager_api_key', '');
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
    <div class="wrap">
        <h1>Woo Manager Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('woo_manager_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="woo_manager_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="woo_manager_api_key" name="woo_manager_api_key"
                            value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description">
                            <?php if (empty($api_key)): ?>
                                Enter the API key from the Woo Manager app to enable push notifications.
                            <?php else: ?>
                                <?php echo wp_kses_post($status_html); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
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
