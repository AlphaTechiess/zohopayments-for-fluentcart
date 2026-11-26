<?php
/**
 * Plugin Name: Zoho Payments for FluentCart
 * Plugin URI: https://www.zoho.com/payments/
 * Description: Zoho Payments embedded checkout gateway for FluentCart (India & US editions) with on-demand OAuth token caching.
 * Author: Zoho Payments
 * Author URI: https://www.zoho.com/payments/
 * Version: 1.0
 * Requires Plugins: fluent-cart
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: zoho-payments-for-fluentcart
 */

if (!defined('ABSPATH')) exit;

defined('ZPFC_VER') || define('ZPFC_VER', '1.0');
defined('ZPFC_DIR') || define('ZPFC_DIR', plugin_dir_path(__FILE__));
defined('ZPFC_URL') || define('ZPFC_URL', plugin_dir_url(__FILE__));

register_deactivation_hook(__FILE__, static function () {
    foreach (['in_live', 'in_test', 'com_live', 'com_test'] as $k) {
        delete_option('zpfc_tlock_' . $k);
    }
});

function zpfc_get_gateway(): ?\ZohoPaymentsFC\Gateway {
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

function zpfc_require_gateway(): \ZohoPaymentsFC\Gateway {
    $gateway = zpfc_get_gateway();
    if (!$gateway) {
        wp_die(esc_html__('FluentCart must be installed and active.', 'zoho-payments-for-fluentcart'));
    }
    return $gateway;
}

// Register AJAX & OAuth hooks directly without reading $_REQUEST before nonce verification.
add_action('wp_ajax_zpfc_confirm_payment', static function () { zpfc_require_gateway()->confirmPayment(); });
add_action('wp_ajax_nopriv_zpfc_confirm_payment', static function () { zpfc_require_gateway()->confirmPayment(); });
add_action('wp_ajax_zpfc_payment_status', static function () { zpfc_require_gateway()->paymentStatus(); });
add_action('wp_ajax_nopriv_zpfc_payment_status', static function () { zpfc_require_gateway()->paymentStatus(); });

add_action('admin_post_zpfc_zoho_oauth_callback', static function () { zpfc_require_gateway()->oauthCallback(); });
add_action('admin_post_nopriv_zpfc_zoho_oauth_callback', static function () { zpfc_require_gateway()->oauthCallback(); });
add_action('admin_post_zpfc_zoho_start_oauth', static function () { zpfc_require_gateway()->startOAuth(); });
add_action('admin_post_zpfc_zoho_disconnect_oauth', static function () { zpfc_require_gateway()->disconnectOAuth(); });

add_action('fluent_cart/register_payment_methods', static function ($data = null) {
    $gateway = zpfc_get_gateway();
    if (!$gateway) return;
    if (is_object($data) && method_exists($data, 'registerCustomPaymentMethod')) {
        $data->registerCustomPaymentMethod('zoho_payments', $gateway);
    } elseif (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('zoho_payments', $gateway);
    }
});

add_action('admin_notices', static function () {
    if (defined('FLUENTCART_VERSION') || function_exists('fluent_cart_api') || !current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . sprintf(
            /* translators: 1: opening strong tag, 2: closing strong tag, 3: opening link tag, 4: closing link tag */
            esc_html__('%1$sZoho Payments for FluentCart%2$s requires %3$sFluentCart%4$s to be installed and active.', 'zoho-payments-for-fluentcart'),
            '<strong>', '</strong>', '<a href="https://fluentcart.com" target="_blank" rel="noopener">', '</a>'
        )
        . '</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    if (function_exists('fluent_cart_api')) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/payments/zoho_payments')) . '">' . esc_html__('Settings', 'zoho-payments-for-fluentcart') . '</a>');
    }
    return $links;
});
