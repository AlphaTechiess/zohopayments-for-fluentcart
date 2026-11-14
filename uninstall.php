<?php
/**
 * Zoho Payments for FluentCart Uninstall Handler.
 *
 * Triggered automatically when the merchant deletes the plugin via WordPress Admin.
 * Completely purges all plugin settings, OAuth credentials, encrypted tokens,
 * transients, and option rows across single-site and multisite installs.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

if (!function_exists('zpfc_purge_site_data')) {
    function zpfc_purge_site_data() {
        global $wpdb;

        // 1. Delete known option keys for Zoho Payments
        $knownOptions = [
            'fluent_cart_payment_settings_zoho_payments',
            'fluent_cart_payment_method_zoho_payments',
            'fluent_cart_gateway_settings_zoho_payments',
            'fluentcart_payment_settings_zoho_payments',
            'fct_payment_settings_zoho_payments',
            'zpfc_oauth_live',
            'zpfc_oauth_test',
            'zpfc_tlock_live',
            'zpfc_tlock_test',
        ];

        foreach ($knownOptions as $opt) {
            delete_option($opt);
            delete_site_option($opt);
        }

        // 2. Remove zoho_payments from ALL FluentCart master settings arrays
        $fcOptions = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%fluent%cart%' OR option_name LIKE '%fct_%'"
        );

        $stripZoho = function (&$array, $self) {
            if (!is_array($array)) return false;
            $modified = false;
            foreach ($array as $key => &$value) {
                if (is_string($key) && (stripos($key, 'zoho') !== false || stripos($key, 'zpfc') !== false)) {
                    unset($array[$key]);
                    $modified = true;
                    continue;
                }
                if (is_array($value)) {
                    if ($self($value, $self)) {
                        $modified = true;
                    }
                }
            }
            return $modified;
        };

        if (!empty($fcOptions) && is_array($fcOptions)) {
            foreach ($fcOptions as $opt) {
                $val = maybe_unserialize($opt->option_value);
                if (is_array($val)) {
                    if ($stripZoho($val, $stripZoho)) {
                        update_option($opt->option_name, $val);
                    }
                }
            }
        }

        // 3. Purge all options containing zoho or zpfc in option_name
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%zoho%' OR option_name LIKE '%zpfc%'"
        );

        // 4. Purge all plugin transients and timeouts
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_zpfc_%' OR option_name LIKE '_transient_timeout_zpfc_%' OR option_name LIKE '_transient_%zoho%' OR option_name LIKE '_transient_timeout_%zoho%'"
        );

        // 5. Purge any user meta
        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%zoho%' OR meta_key LIKE '%zpfc%'"
        );

        // 6. Flush object cache if enabled
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
}

// Execute on current site
zpfc_purge_site_data();

// Handle Multisite networks
if (is_multisite()) {
    $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    if (!empty($blogIds) && is_array($blogIds)) {
        foreach ($blogIds as $blogId) {
            switch_to_blog((int)$blogId);
            zpfc_purge_site_data();
            restore_current_blog();
        }
    }

    // Also purge from network sitemeta
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%zoho%' OR meta_key LIKE '%zpfc%'"
    );
}
