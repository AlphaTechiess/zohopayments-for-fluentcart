<?php
/**
 * Plugin Name: Zoho Payments for FluentCart
 * Description: Zoho Payments embedded checkout gateway for FluentCart.
 * Author: FluentCart Team
 * Author URI: https://fluentcart.com
 * Version: 1.1.5
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: zoho-payments-fluentcart
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

if (!defined('ZPFC_VER')) define('ZPFC_VER', '1.1.5');
if (!defined('ZPFC_DIR')) define('ZPFC_DIR', plugin_dir_path(__FILE__));
if (!defined('ZPFC_URL')) define('ZPFC_URL', plugin_dir_url(__FILE__));

/**
 * Load plugin text domain for internationalization.
 */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('zoho-payments-fluentcart', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Get the shared singleton instance of Zoho Payments Gateway.
 *
 * @return \ZohoPaymentsFC\Gateway|null
 */
function zpfc_get_gateway() {
    static $gateway = null;
    if ($gateway === null) {
        if (!class_exists(\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::class)) {
            return null;
        }
        require_once ZPFC_DIR . 'includes/Settings.php';
        require_once ZPFC_DIR . 'includes/API.php';
        require_once ZPFC_DIR . 'includes/Gateway.php';
        $gateway = new \ZohoPaymentsFC\Gateway();
    }
    return $gateway;
}

/**
 * Early boot for AJAX actions and webhook IPN listener.
 */
add_action('init', function () {
    if (wp_doing_ajax() && isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['zpfc_confirm_payment', 'zpfc_payment_status'], true)) {
        zpfc_get_gateway();
    } elseif ((isset($_GET['fluent-cart']) || isset($_GET['fluent_cart'])) && isset($_GET['method']) && $_GET['method'] === 'zoho_payments') {
        zpfc_get_gateway();
    }
}, 5);

/**
 * Handle Zoho OAuth Callback securely.
 */
function zpfc_handle_oauth_callback() {
    $gateway = zpfc_get_gateway();
    if (!$gateway) {
        wp_die(esc_html__('FluentCart must be installed and active to connect Zoho Payments.', 'zoho-payments-fluentcart'));
    }
    $gateway->oauthCallback();
}

add_action('admin_post_zpfc_zoho_oauth_callback', 'zpfc_handle_oauth_callback');
add_action('admin_post_nopriv_zpfc_zoho_oauth_callback', 'zpfc_handle_oauth_callback');

/**
 * Handle Start Zoho OAuth action.
 */
add_action('admin_post_zpfc_zoho_start_oauth', function () {
    $gateway = zpfc_get_gateway();
    if (!$gateway) {
        wp_die(esc_html__('FluentCart must be installed and active to connect Zoho Payments.', 'zoho-payments-fluentcart'));
    }
    $gateway->startOAuth();
});

/**
 * Handle Disconnect Zoho OAuth action.
 */
add_action('admin_post_zpfc_zoho_disconnect_oauth', function () {
    $gateway = zpfc_get_gateway();
    if (!$gateway) {
        wp_die(esc_html__('FluentCart must be installed and active.', 'zoho-payments-fluentcart'));
    }
    $gateway->disconnectOAuth();
});

/**
 * Register Zoho Payments Gateway with FluentCart.
 */
add_action('fluent_cart/register_payment_methods', function ($data = null) {
    $gateway = zpfc_get_gateway();
    if (!$gateway) {
        return;
    }

    if (is_object($data) && method_exists($data, 'registerCustomPaymentMethod')) {
        $data->registerCustomPaymentMethod('zoho_payments', $gateway);
        return;
    }

    if (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('zoho_payments', $gateway);
    }
});

/**
 * Admin notice if FluentCart is missing.
 */
add_action('admin_notices', function () {
    if (defined('FLUENTCART_VERSION') || function_exists('fluent_cart_api')) {
        return;
    }
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . sprintf(
            esc_html__('%1$sZoho Payments for FluentCart%2$s requires %3$sFluentCart%4$s to be installed and active.', 'zoho-payments-fluentcart'),
            '<strong>',
            '</strong>',
            '<a href="https://fluentcart.com" target="_blank" rel="noopener">',
            '</a>'
        )
        . '</p></div>';
});

/**
 * Settings action link on plugins list page.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (function_exists('fluent_cart_api')) {
        $settingsUrl = admin_url('admin.php?page=fluent-cart#/settings/payments/zoho_payments');
        $settingsLink = '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings', 'zoho-payments-fluentcart') . '</a>';
        array_unshift($links, $settingsLink);
    }
    return $links;
});
