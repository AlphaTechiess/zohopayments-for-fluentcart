<?php
namespace ZohoPaymentsFC;

if (!defined('ABSPATH')) exit;

class API {
    private Settings $settings;
    private static array $memoryTokens = [];

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function base(?string $mode = null): string {
        $isTest = $this->settings->normalizeMode($mode) === 'test';
        return ($isTest ? 'https://paymentssandbox.zoho.' : 'https://payments.zoho.') . $this->settings->tld();
    }

    public function token(bool $forceRefresh = false, ?string $mode = null) {
        $m            = $this->settings->normalizeMode($mode);
        $ctxKey       = $this->settings->contextKey($m);
        $transientKey = $this->settings->tokenTransientKey($m);
        $lockKey      = $this->settings->tokenLockKey($m);
        $buffer       = 300;

        if (!$forceRefresh) {
            $mem = self::$memoryTokens[$ctxKey] ?? null;
            if (!empty($mem['access_token']) && ($mem['expires_at'] ?? 0) > (time() + $buffer)) {
                return $mem['access_token'];
            }
            $cached = get_transient($transientKey);
            if (is_array($cached) && !empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > (time() + $buffer)) {
                return (self::$memoryTokens[$ctxKey] = $cached)['access_token'];
            }
        } else {
            $cached = false;
        }

        $acquiredLock = add_option($lockKey, time(), '', 'no');
        if (!$acquiredLock) {
            if (!$forceRefresh && is_array($cached) && !empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > (time() + 30)) {
                return $cached['access_token'];
            }
            for ($i = 0; $i < 6; $i++) {
                usleep(400000);
                $fresh = get_transient($transientKey);
                if (is_array($fresh) && !empty($fresh['access_token']) && ($fresh['expires_at'] ?? 0) > (time() + $buffer)) {
                    return (self::$memoryTokens[$ctxKey] = $fresh)['access_token'];
                }
            }
            $lockTime = (int)get_option($lockKey, 0);
            if ($lockTime === 0 || (time() - $lockTime) > 30) {
                delete_option($lockKey);
                $acquiredLock = add_option($lockKey, time(), '', 'no');
            }
        }

        try {
            if (!$forceRefresh) {
                $rechecked = get_transient($transientKey);
                if (is_array($rechecked) && !empty($rechecked['access_token']) && ($rechecked['expires_at'] ?? 0) > (time() + $buffer)) {
                    return (self::$memoryTokens[$ctxKey] = $rechecked)['access_token'];
                }
            }

            $refreshToken = $this->settings->refreshToken($m);
            $clientId     = $this->settings->clientId($m);
            $clientSecret = $this->settings->clientSecret($m);

            if (!$refreshToken || !$clientId || !$clientSecret) {
                return new \WP_Error('zpfc_credentials_missing', __('Zoho Payments OAuth credentials are incomplete. Please connect OAuth in gateway settings.', 'zoho-payments-for-fluentcart'));
            }

            $res = wp_remote_post($this->settings->accountsUrl() . '/oauth/v2/token', [
                'timeout' => 25,
                'headers' => ['Accept' => 'application/json'],
                'body'    => [
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'refresh_token',
                ],
            ]);

            if (is_wp_error($res)) return $res;

            $code = wp_remote_retrieve_response_code($res);
            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($body) || empty($body['access_token'])) {
                $err = $body['error'] ?? $body['message'] ?? $body['error_description'] ?? __('Failed to refresh Zoho Payments access token.', 'zoho-payments-for-fluentcart');
                return new \WP_Error('zpfc_token_error', $err, ['status' => $code, 'body' => $body]);
            }

            $expiresIn = max(60, (int)($body['expires_in'] ?? 3600));
            $tokenData = ['access_token' => (string)$body['access_token'], 'expires_at' => time() + $expiresIn];
            set_transient($transientKey, $tokenData, max(60, $expiresIn - 180));
            self::$memoryTokens[$ctxKey] = $tokenData;

            return $tokenData['access_token'];
        } finally {
            if ($acquiredLock) delete_option($lockKey);
        }
    }

    public function primeToken(string $accessToken, int $expiresIn = 3600, ?string $mode = null): void {
        $m         = $this->settings->normalizeMode($mode);
        $expiresIn = $expiresIn > 0 ? $expiresIn : 3600;
        $tokenData = ['access_token' => $accessToken, 'expires_at' => time() + $expiresIn];
        set_transient($this->settings->tokenTransientKey($m), $tokenData, max(60, $expiresIn - 180));
        self::$memoryTokens[$this->settings->contextKey($m)] = $tokenData;
    }

    public function clearTokenCache(?string $mode = null): void {
        $m = $this->settings->normalizeMode($mode);
        unset(self::$memoryTokens[$this->settings->contextKey($m)]);
        delete_transient($this->settings->tokenTransientKey($m));
        delete_option($this->settings->tokenLockKey($m));
    }

    public function request(string $method, string $path, array $body = [], bool $isRetry = false, ?string $mode = null) {
        $m     = $this->settings->normalizeMode($mode);
        $token = $this->token($isRetry, $m);
        if (is_wp_error($token)) return $token;

        $accountId = $this->settings->accountId($m);
        if (!$accountId) {
            return new \WP_Error('zpfc_no_account_id', __('Zoho Payments Account ID is not configured.', 'zoho-payments-for-fluentcart'));
        }

        $url  = $this->base($m) . '/api/v1' . $path . (strpos($path, '?') === false ? '?' : '&') . 'account_id=' . rawurlencode($accountId);
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];
        if (!empty($body) && in_array($args['method'], ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw_response' => $raw];

        if ($code === 401 && !$isRetry) {
            $this->clearTokenCache($m);
            return $this->request($method, $path, $body, true, $m);
        }

        if ($code >= 400) {
            $err = ($data['message'] ?? $data['error'] ?? $data['error_description'] ?? ($data['errors'][0]['message'] ?? '')) ?: __('Zoho Payments API error.', 'zoho-payments-for-fluentcart');
            return new \WP_Error('zpfc_api_error', $err, ['status' => $code, 'response' => $data]);
        }

        return $data;
    }

    public function createSession(array $data, ?string $mode = null) { return $this->request('POST', '/paymentsessions', $data, false, $mode); }
    public function getSession($id, ?string $mode = null) { return $this->request('GET', '/paymentsessions/' . rawurlencode((string)$id), [], false, $mode); }
    public function getPayment($id, ?string $mode = null) { return $this->request('GET', '/payments/' . rawurlencode((string)$id), [], false, $mode); }
    public function listPayments(array $params = [], ?string $mode = null) { return $this->request('GET', '/payments' . ($params ? '?' . http_build_query($params) : ''), [], false, $mode); }

    public function refund($id, $amount, string $reason = 'requested_by_customer', string $description = 'Refund from FluentCart', array $metadata = [], ?string $mode = null) {
        $body = [
            'amount'      => (float)number_format((float)$amount, 2, '.', ''),
            'reason'      => $reason,
            'type'        => 'initiated_by_merchant',
            'description' => substr($description, 0, 500),
        ];
        $validMeta = [];
        foreach ($metadata as $item) {
            if (isset($item['key'], $item['value'])) {
                $validMeta[] = ['key' => substr((string)$item['key'], 0, 50), 'value' => substr((string)$item['value'], 0, 200)];
                if (count($validMeta) >= 5) break;
            }
        }
        if ($validMeta) $body['meta_data'] = $validMeta;

        return $this->request('POST', '/payments/' . rawurlencode((string)$id) . '/refunds', $body, false, $mode);
    }

    public static function oauthExchange(string $code, string $clientId, string $clientSecret, string $redirectUri, string $accountsUrl = 'https://accounts.zoho.in') {
        $res = wp_remote_post(rtrim($accountsUrl, '/') . '/oauth/v2/token', [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
            'body'    => [
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        ]);
        if (is_wp_error($res)) return $res;

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['refresh_token'])) {
            $msg = $body['error'] ?? $body['message'] ?? $body['error_description'] ?? __('Unable to exchange Zoho authorization code.', 'zoho-payments-for-fluentcart');
            return new \WP_Error('zpfc_oauth_error', $msg, $body);
        }
        return $body;
    }
}
