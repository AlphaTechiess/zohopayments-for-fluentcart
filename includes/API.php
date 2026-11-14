<?php
namespace ZohoPaymentsFC;

if (!defined('ABSPATH')) exit;

class API {
    private Settings $settings;
    private static array $memoryTokens = [];

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function base(): string {
        return $this->settings->isTest() ? 'https://paymentssandbox.zoho.in' : 'https://payments.zoho.in';
    }

    public function accountsUrl(): string {
        return 'https://accounts.zoho.in';
    }

    public function token() {
        $mode = $this->settings->mode();
        $now = time();

        if (isset(self::$memoryTokens[$mode])) {
            $mem = self::$memoryTokens[$mode];
            if (!empty($mem['access_token']) && $mem['expires_at'] > ($now + 300)) {
                return $mem['access_token'];
            }
        }

        $transientKey = 'zpfc_token_' . $mode;
        $cached = get_transient($transientKey);

        if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])) {
            if ($cached['expires_at'] > ($now + 300)) {
                self::$memoryTokens[$mode] = $cached;
                return $cached['access_token'];
            }
        }

        $lockKey = 'zpfc_tlock_' . $mode;
        $hasLock = add_option($lockKey, $now, '', 'no');

        if (!$hasLock) {
            if (is_array($cached) && !empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > ($now + 30)) {
                return $cached['access_token'];
            }

            $waited = 0;
            while ($waited < 5) {
                usleep(500000);
                $waited++;
                $fresh = get_transient($transientKey);
                if (is_array($fresh) && !empty($fresh['access_token']) && ($fresh['expires_at'] ?? 0) > ($now + 300)) {
                    self::$memoryTokens[$mode] = $fresh;
                    return $fresh['access_token'];
                }
            }

            $lockTime = (int)get_option($lockKey, 0);
            if (($now - $lockTime) > 30) {
                delete_option($lockKey);
                add_option($lockKey, $now, '', 'no');
            }
        }

        try {
            $refreshToken = $this->settings->refreshToken();
            $clientId = $this->settings->clientId();
            $clientSecret = $this->settings->clientSecret();

            if (!$refreshToken || !$clientId || !$clientSecret) {
                return new \WP_Error('zpfc_credentials_missing', __('Zoho Payments OAuth credentials are incomplete. Please connect OAuth in gateway settings.', 'zoho-payments-fluentcart'));
            }

            $response = wp_remote_post($this->accountsUrl() . '/oauth/v2/token', [
                'timeout' => 25,
                'headers' => ['Accept' => 'application/json'],
                'body'    => [
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type'    => 'refresh_token',
                    'redirect_uri'  => $this->settings->oauthCallbackUrl()
                ]
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $rawBody = wp_remote_retrieve_body($response);
            $body = json_decode($rawBody, true);

            if (!is_array($body) || empty($body['access_token'])) {
                $errorMsg = $body['error'] ?? $body['message'] ?? $body['error_description'] ?? 'Failed to refresh Zoho Payments access token.';
                return new \WP_Error('zpfc_token_error', $errorMsg, ['status' => $code, 'body' => $body]);
            }

            $accessToken = (string)$body['access_token'];
            $expiresIn = isset($body['expires_in']) ? (int)$body['expires_in'] : 3600;
            if ($expiresIn <= 0) $expiresIn = 3600;

            $tokenData = [
                'access_token' => $accessToken,
                'expires_at'   => $now + $expiresIn
            ];

            set_transient($transientKey, $tokenData, max(60, $expiresIn - 180));
            self::$memoryTokens[$mode] = $tokenData;

            return $accessToken;
        } finally {
            delete_option($lockKey);
        }
    }

    public function primeToken(string $accessToken, int $expiresIn = 3600): void {
        $mode = $this->settings->mode();
        $expiresIn = $expiresIn > 0 ? $expiresIn : 3600;
        $tokenData = [
            'access_token' => $accessToken,
            'expires_at'   => time() + $expiresIn
        ];
        set_transient('zpfc_token_' . $mode, $tokenData, max(60, $expiresIn - 180));
        self::$memoryTokens[$mode] = $tokenData;
    }

    public function clearTokenCache(): void {
        $mode = $this->settings->mode();
        unset(self::$memoryTokens[$mode]);
        delete_transient('zpfc_token_' . $mode);
        delete_option('zpfc_tlock_' . $mode);
    }

    public function request(string $method, string $path, array $body = [], bool $isRetry = false) {
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }

        $accountId = $this->settings->accountId();
        if (!$accountId) {
            return new \WP_Error('zpfc_no_account_id', __('Zoho Payments Account ID is not configured.', 'zoho-payments-fluentcart'));
        }

        $sep = strpos($path, '?') === false ? '?' : '&';
        $url = $this->base() . '/api/v1' . $path . $sep . 'account_id=' . rawurlencode($accountId);

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ]
        ];

        if (!empty($body) && in_array($args['method'], ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $data = ['raw_response' => $rawBody];
        }

        if ($code === 401 && !$isRetry) {
            $this->clearTokenCache();
            return $this->request($method, $path, $body, true);
        }

        if ($code >= 400) {
            $errorMessage = $data['message'] 
                ?? $data['error'] 
                ?? $data['error_description'] 
                ?? ($data['errors'][0]['message'] ?? '')
                ?? __('Zoho Payments API error.', 'zoho-payments-fluentcart');

            return new \WP_Error('zpfc_api_error', $errorMessage, [
                'status'   => $code,
                'response' => $data
            ]);
        }

        return $data;
    }

    public function createSession(array $data) {
        return $this->request('POST', '/paymentsessions', $data);
    }

    public function getSession($id) {
        return $this->request('GET', '/paymentsessions/' . rawurlencode((string)$id));
    }

    public function getPayment($id) {
        return $this->request('GET', '/payments/' . rawurlencode((string)$id));
    }

    public function listPayments(array $params = []) {
        $path = '/payments';
        if (!empty($params)) {
            $path .= '?' . http_build_query($params);
        }
        return $this->request('GET', $path);
    }

    public function refund($id, $amount, $reason = 'requested_by_customer', $description = 'Refund from FluentCart', array $metadata = []) {
        $body = [
            'amount'      => (float)number_format((float)$amount, 2, '.', ''),
            'reason'      => (string)$reason,
            'type'        => 'initiated_by_merchant',
            'description' => substr((string)$description, 0, 500)
        ];
        if (!empty($metadata)) {
            $validMeta = [];
            foreach ($metadata as $item) {
                if (isset($item['key'], $item['value'])) {
                    $validMeta[] = [
                        'key'   => substr((string)$item['key'], 0, 50),
                        'value' => substr((string)$item['value'], 0, 200),
                    ];
                }
                if (count($validMeta) >= 5) break;
            }
            if (!empty($validMeta)) {
                $body['meta_data'] = $validMeta;
            }
        }
        return $this->request('POST', '/payments/' . rawurlencode((string)$id) . '/refunds', $body);
    }

    public function getRefund($id) {
        return $this->request('GET', '/refunds/' . rawurlencode((string)$id));
    }

    public static function oauthExchange(string $code, string $clientId, string $clientSecret, string $redirectUri) {
        $response = wp_remote_post('https://accounts.zoho.in/oauth/v2/token', [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
            'body'    => [
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code'
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['refresh_token'])) {
            $msg = $body['error'] ?? $body['message'] ?? $body['error_description'] ?? __('Unable to exchange Zoho authorization code.', 'zoho-payments-fluentcart');
            return new \WP_Error('zpfc_oauth_error', $msg, $body);
        }

        return $body;
    }
}
