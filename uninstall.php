<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

function zpfc_purge_site_data(): void {
    global $wpdb;

    $known = [
        'fluent_cart_payment_settings_zoho_payments',
        'fluent_cart_payment_method_zoho_payments',
        'fluent_cart_gateway_settings_zoho_payments',
        'fluentcart_payment_settings_zoho_payments',
        'fct_payment_settings_zoho_payments',
    ];
    foreach ($known as $opt) {
        delete_option($opt);
        delete_site_option($opt);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $fctMetaExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'fct_meta'));
    if ($fctMetaExists === $wpdb->prefix . 'fct_meta') {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fct_meta WHERE object_type = %s AND (meta_key LIKE %s OR meta_key LIKE %s)", 'option', '%zoho_payments%', $wpdb->esc_like('zpfc_') . '%'));
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $fcOptions = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '%fluent%cart%', $wpdb->esc_like('fct_') . '%'));
    $strip     = static function (&$arr, $self): bool {
        if (!is_array($arr)) return false;
        $changed = false;
        foreach ($arr as $k => &$v) {
            if (is_string($k) && (stripos($k, 'zoho_payments') !== false || stripos($k, 'zpfc') !== false)) {
                unset($arr[$k]);
                $changed = true;
            } elseif (is_array($v) && $self($v, $self)) {
                $changed = true;
            }
        }
        return $changed;
    };

    if (is_array($fcOptions)) {
        foreach ($fcOptions as $opt) {
            $val = maybe_unserialize($opt->option_value);
            if (is_array($val) && $strip($val, $strip)) {
                update_option($opt->option_name, $val);
            }
        }
    }

    $zpfcLike          = $wpdb->esc_like('zpfc_') . '%';
    $transientLike     = $wpdb->esc_like('_transient_zpfc_') . '%';
    $transientToutLike = $wpdb->esc_like('_transient_timeout_zpfc_') . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $zpfcLike, $transientLike, $transientToutLike));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $zpfcLike));

    if (function_exists('wp_cache_flush')) wp_cache_flush();
}

zpfc_purge_site_data();

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $zpfc_blog_id) {
        switch_to_blog((int)$zpfc_blog_id);
        zpfc_purge_site_data();
        restore_current_blog();
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
        $wpdb->esc_like('zpfc_') . '%',
        $wpdb->esc_like('_site_transient_zpfc_') . '%',
        $wpdb->esc_like('_site_transient_timeout_zpfc_') . '%'
    ));
}
