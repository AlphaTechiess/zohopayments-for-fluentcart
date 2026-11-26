<?php
namespace ZohoPaymentsFC;

if (!defined('ABSPATH')) exit;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class Settings extends BaseGatewaySettings {
    public $methodHandler = 'fluent_cart_payment_settings_zoho_payments';

    public static function getDefaults(): array {
        return [
            'is_active'          => 'no',
            'title'              => 'Zoho Payments',
            'label'              => 'Zoho Payments',
            'edition'            => 'in',
            'payment_mode'       => 'test',
            'live_account_id'    => '',
            'live_api_key'       => '',
            'live_signing_key'   => '',
            'live_client_id'     => '',
            'live_client_secret' => '',
            'test_account_id'    => '',
            'test_api_key'       => '',
            'test_signing_key'   => '',
            'test_client_id'     => '',
            'test_client_secret' => '',
        ];
    }

    public static function validateSettings($data) {
        if (!is_array($data)) return $data;

        $data['title']   = 'Zoho Payments';
        $data['label']   = 'Zoho Payments';
        $ed              = strtolower(trim((string)($data['edition'] ?? 'in')));
        $data['edition'] = ($ed === 'com' || $ed === 'us') ? 'com' : 'in';

        $mode                 = strtolower(trim((string)($data['payment_mode'] ?? 'live')));
        $data['payment_mode'] = $mode === 'test' ? 'test' : 'live';

        $fields = [
            'live_account_id', 'live_api_key', 'live_signing_key', 'live_client_id', 'live_client_secret',
            'test_account_id', 'test_api_key', 'test_signing_key', 'test_client_id', 'test_client_secret',
        ];
        foreach ($fields as $f) {
            if (isset($data[$f]) && is_string($data[$f])) {
                $data[$f] = trim($data[$f]);
            }
        }

        foreach (['in_live', 'in_test', 'com_live', 'com_test'] as $ctx) {
            delete_transient('zpfc_token_' . $ctx);
            delete_option('zpfc_tlock_' . $ctx);
        }

        return $data;
    }

    public function get($key = '', $default = null) {
        if ($key === 'title' || $key === 'label') return 'Zoho Payments';

        if ($key === '' || $key === null) {
            $all                 = (array)$this->settings;
            $all['title']        = 'Zoho Payments';
            $all['label']        = 'Zoho Payments';
            $all['payment_mode'] = $this->getMode();
            return $all;
        }

        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function edition(): string {
        $ed = strtolower(trim((string)$this->get('edition', 'in')));
        return ($ed === 'com' || $ed === 'us') ? 'com' : 'in';
    }

    public function isUS(): bool { return $this->edition() === 'com'; }
    public function tld(): string { return $this->isUS() ? 'com' : 'in'; }
    public function widgetDomain(): string { return $this->isUS() ? 'US' : 'IN'; }
    public function accountsUrl(): string { return 'https://accounts.zoho.' . $this->tld(); }
    public function apiConsoleUrl(): string { return 'https://api-console.zoho.com/add?client_type=ORG'; }

    public function getStoreMode(): string {
        if (class_exists(StoreSettings::class)) {
            try {
                $mode = strtolower(trim((string)(new StoreSettings())->get('order_mode')));
                if (in_array($mode, ['live', 'test'], true)) {
                    return $mode;
                }
            } catch (\Throwable $e) {
                // Fall back to saved gateway mode if StoreSettings is unavailable.
            }
        }

        if (is_array($this->settings) && !empty($this->settings['enable_sandbox'])) {
            if (in_array(strtolower(trim((string)$this->settings['enable_sandbox'])), ['yes', '1', 'true', 'on'], true)) {
                return 'test';
            }
        }

        $fallback = strtolower(trim((string)($this->settings['payment_mode'] ?? 'live')));
        return $fallback === 'test' ? 'test' : 'live';
    }

    public function normalizeMode(?string $mode = null): string {
        if ($mode === null || $mode === '') {
            return $this->getMode();
        }
        return strtolower(trim($mode)) === 'test' ? 'test' : 'live';
    }

    public function getMode(): string { return $this->getStoreMode(); }
    public function isTest(): bool { return $this->getMode() === 'test'; }
    public function isActive(): bool { return $this->get('is_active', 'no') === 'yes'; }

    public function contextKey(?string $mode = null): string { return $this->edition() . '_' . $this->normalizeMode($mode); }
    public function oauthOptionKey(?string $mode = null): string { return 'zpfc_oauth_' . $this->contextKey($mode); }
    public function tokenTransientKey(?string $mode = null): string { return 'zpfc_token_' . $this->contextKey($mode); }
    public function tokenLockKey(?string $mode = null): string { return 'zpfc_tlock_' . $this->contextKey($mode); }

    private function credential(string $field, ?string $mode = null, bool $encrypted = false): string {
        $m   = $this->normalizeMode($mode);
        $val = $this->readRawOrDecrypted($m . '_' . $field, $encrypted);
        if ($val !== '') {
            return $val;
        }

        // Backward compatibility for installs upgraded from pre-tabbed single-set credentials.
        $legacySandbox = in_array(strtolower(trim((string)$this->get('enable_sandbox', 'no'))), ['yes', '1', 'true', 'on'], true);
        $legacyMode    = $legacySandbox ? 'test' : 'live';
        if ($m === $legacyMode) {
            return $this->readRawOrDecrypted($field, $encrypted);
        }

        return '';
    }

    private function readRawOrDecrypted(string $key, bool $encrypted): string {
        $val = (string)$this->get($key, '');
        if ($val === '') return '';
        if ($encrypted && class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($val)) {
            $dec = Helper::decryptKey($val);
            return $dec === false ? '' : trim((string)$dec);
        }
        return trim($val);
    }

    public function accountId(?string $mode = null): string { return $this->credential('account_id', $mode); }
    public function clientId(?string $mode = null): string { return $this->credential('client_id', $mode); }
    public function apiKey(?string $mode = null): string { return $this->credential('api_key', $mode, true); }
    public function clientSecret(?string $mode = null): string { return $this->credential('client_secret', $mode, true); }
    public function signingKey(?string $mode = null): string { return $this->credential('signing_key', $mode, true); }
    public function oauthCallbackUrl(): string { return admin_url('admin-post.php?action=zpfc_zoho_oauth_callback'); }

    public function refreshToken(?string $mode = null): string {
        $stored = $this->oauthConnection($mode);
        return !empty($stored['refresh_token']) ? $this->decryptStoredSecret((string)$stored['refresh_token']) : '';
    }

    public function storeOAuthRefreshToken(string $refreshToken, ?string $mode = null): bool {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') return false;

        $m = $this->normalizeMode($mode);
        update_option($this->oauthOptionKey($m), [
            'refresh_token' => $this->encryptStoredSecret($refreshToken),
            'connected_at'  => time(),
            'user_id'       => get_current_user_id(),
            'edition'       => $this->edition(),
            'mode'          => $m,
        ], false);
        return true;
    }

    public function disconnectOAuth(?string $mode = null): void {
        $m = $this->normalizeMode($mode);
        delete_option($this->oauthOptionKey($m));
        delete_transient($this->tokenTransientKey($m));
        delete_option($this->tokenLockKey($m));
    }

    public function oauthConnection(?string $mode = null): array {
        $val = get_option($this->oauthOptionKey($mode), []);
        return (is_array($val) && !empty($val['refresh_token'])) ? $val : [];
    }

    public function isOAuthConnected(?string $mode = null): bool {
        return !empty($this->oauthConnection($mode)['refresh_token']);
    }

    public function missingOAuthSettings(?string $mode = null): array {
        $m       = $this->normalizeMode($mode);
        $missing = [];
        if (!$this->accountId($m)) $missing[] = __('Account ID', 'zoho-payments-for-fluentcart');
        if (!$this->clientId($m)) $missing[] = __('OAuth Client ID', 'zoho-payments-for-fluentcart');
        if (!$this->clientSecret($m)) $missing[] = __('OAuth Client Secret', 'zoho-payments-for-fluentcart');
        return $missing;
    }

    private function encryptStoredSecret(string $value): string {
        if (class_exists(Helper::class) && method_exists(Helper::class, 'encryptKey')) {
            try { return Helper::encryptKey($value); } catch (\Throwable $e) {}
        }
        $key    = hash('sha256', wp_salt('auth'), true);
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $cipher === false ? '' : 'zpfc2:' . base64_encode($iv . $cipher);
    }

    private function decryptStoredSecret(string $value): string {
        if ($value === '') return '';
        if (strpos($value, 'zpfc2:') === 0) {
            $raw = base64_decode(substr($value, 6), true);
            if ($raw === false || strlen($raw) < 17) return '';
            $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            return $plain === false ? '' : trim((string)$plain);
        }
        if (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($value)) {
            $dec = Helper::decryptKey($value);
            return $dec === false ? '' : trim((string)$dec);
        }
        return trim($value);
    }
}

$zpfcForceTitle = static function ($data) {
    if (is_array($data)) {
        $data['title'] = 'Zoho Payments';
        $data['label'] = 'Zoho Payments';
    }
    return $data;
};
add_filter('option_fluent_cart_payment_settings_zoho_payments', $zpfcForceTitle, 999);
add_filter('default_option_fluent_cart_payment_settings_zoho_payments', $zpfcForceTitle, 999);
