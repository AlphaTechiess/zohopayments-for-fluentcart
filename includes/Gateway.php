<?php
namespace ZohoPaymentsFC;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Payments\Refund;

class Gateway extends AbstractPaymentGateway {
    public array $supportedFeatures = ['payment', 'refund', 'webhook'];
    private ?API $api = null;

    public function __construct() {
        parent::__construct(new Settings());
    }

    private function api(): API {
        return $this->api ??= new API($this->settings);
    }

    public function meta(): array {
        $iconFile = ZPFC_DIR . 'assets/icon.svg';
        $iconUrl  = ZPFC_URL . 'assets/icon.svg' . (file_exists($iconFile) ? '?v=' . filemtime($iconFile) : '');

        return [
            'title'              => $this->getTitle(),
            'route'              => 'zoho_payments',
            'slug'               => 'zoho_payments',
            'label'              => $this->getLabel(),
            'description'        => $this->getDescription(),
            'logo'               => $iconUrl,
            'icon'               => $iconUrl,
            'brand_color'        => '#226db4',
            'status'             => $this->settings->isActive(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool { return in_array($feature, $this->supportedFeatures, true); }
    public function getTitle(): string { return __('Zoho Payments', 'zoho-payments-for-fluentcart'); }
    public function getLabel(): string { return __('Zoho Payments', 'zoho-payments-for-fluentcart'); }

    public function getDescription(): string {
        return $this->settings->isUS()
            ? __('Pay securely with Cards and Bank Transfer (ACH) via Zoho Payments.', 'zoho-payments-for-fluentcart')
            : __('Pay securely with Cards, Net Banking, UPI, and Wallets via Zoho Payments.', 'zoho-payments-for-fluentcart');
    }

    public function getOAuthInstructions(): array {
        return [
            'title'       => __('Authorized Redirect URI', 'zoho-payments-for-fluentcart'),
            'webhook_url' => esc_url_raw($this->settings->oauthCallbackUrl()),
            'description' => __('Copy and paste this exact Redirect URI into your Zoho API Console ORG Client.', 'zoho-payments-for-fluentcart'),
            'steps'       => [
                'title' => __('How to configure Zoho OAuth?', 'zoho-payments-for-fluentcart'),
                'list'  => [
                    /* translators: %s: Zoho API Console URL */
                    sprintf(__('Create an ORG client in the Zoho API Console at %s', 'zoho-payments-for-fluentcart'), $this->settings->apiConsoleUrl()),
                    __('Copy the Authorized Redirect URI above and paste it into Client Details → Authorized Redirect URIs', 'zoho-payments-for-fluentcart'),
                    __('Enter Account ID, Zoho Payments API Key, OAuth Client ID & OAuth Client Secret above and save settings', 'zoho-payments-for-fluentcart'),
                    __('Click "Connect Zoho OAuth" below to grant permissions and complete setup', 'zoho-payments-for-fluentcart'),
                ],
            ],
        ];
    }

    public function getWebhookInstructions(): array {
        return [
            'title'       => __('Webhook URL', 'zoho-payments-for-fluentcart'),
            'webhook_url' => esc_url_raw($this->getWebhookUrl()),
            'description' => __('Register this webhook endpoint under Zoho Payments → Developer Space → Webhooks.', 'zoho-payments-for-fluentcart'),
            'steps'       => [
                'title' => __('How to configure?', 'zoho-payments-for-fluentcart'),
                'list'  => [
                    __('In Zoho Payments, navigate to Developer Space → Webhooks', 'zoho-payments-for-fluentcart'),
                    __('Click Add Webhook, paste the Webhook URL above, and select the required events below', 'zoho-payments-for-fluentcart'),
                    __('Copy your Signing Key from Developer Space into the Webhook Signing Key field above', 'zoho-payments-for-fluentcart'),
                ],
            ],
            'events' => [
                'title' => __('Required Webhook Events', 'zoho-payments-for-fluentcart'),
                'list'  => ['payment.succeeded', 'payment.failed', 'refund.succeeded', 'refund.failed'],
            ],
        ];
    }

    private function buildCredentialTabFields(string $mode, array $webhookInstructions, array $oauthInstructions, string $storeMode, string $editionLabel): array {
        $m             = $mode === 'test' ? 'test' : 'live';
        $connected     = $this->settings->isOAuthConnected($m);
        $startUrl      = wp_nonce_url(add_query_arg(['action' => 'zpfc_zoho_start_oauth', 'mode' => $m], admin_url('admin-post.php')), 'zpfc_oauth_connect');
        $disconnectUrl = wp_nonce_url(add_query_arg(['action' => 'zpfc_zoho_disconnect_oauth', 'mode' => $m], admin_url('admin-post.php')), 'zpfc_oauth_disconnect');

        $badge   = $connected
            ? '<span style="color:#22c55e;font-weight:600">' . esc_html__('Connected', 'zoho-payments-for-fluentcart') . '</span>'
            : '<span style="color:#ef4444;font-weight:600">' . esc_html__('Not connected', 'zoho-payments-for-fluentcart') . '</span>';
        $btnCss  = 'margin-left:10px;background:#2563eb;color:#fff!important;border:1px solid #1d4ed8;padding:6px 14px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:500;font-size:13px;line-height:1.4';
        $discCss = 'margin-left:8px;background:rgba(239,68,68,.1);color:#ef4444!important;border:1px solid rgba(239,68,68,.3);padding:5px 12px;border-radius:6px;text-decoration:none;display:inline-block;font-size:12px;line-height:1.4';

        $oauthHtml  = '<div style="margin-top:6px;line-height:1.8"><strong>' . esc_html__('OAuth Status:', 'zoho-payments-for-fluentcart') . '</strong> ' . $badge;
        $oauthHtml .= ' <a style="' . esc_attr($btnCss) . '" href="' . esc_url($startUrl) . '">' . esc_html($connected ? __('Reconnect Zoho OAuth', 'zoho-payments-for-fluentcart') : __('Connect Zoho OAuth', 'zoho-payments-for-fluentcart')) . '</a>';
        if ($connected) {
            $oauthHtml .= ' <a style="' . esc_attr($discCss) . '" href="' . esc_url($disconnectUrl) . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to disconnect Zoho OAuth?', 'zoho-payments-for-fluentcart')) . '\')">' . esc_html__('Disconnect', 'zoho-payments-for-fluentcart') . '</a>';
        }
        $oauthHtml .= '<br><small style="color:#94a3b8;margin-top:6px;display:inline-block;font-size:12px">' . /* translators: 1: Zoho edition label, 2: tab credential mode, 3: active store order mode */
            esc_html(sprintf(__('Context: %1$s • %2$s credentials (Store Order Mode: %3$s). Save settings before connecting OAuth.', 'zoho-payments-for-fluentcart'), $editionLabel, strtoupper($m), strtoupper($storeMode))) . '</small></div>';

        return [
            $m . '_account_id' => [
                'type'        => 'text',
                'label'       => __('Account ID', 'zoho-payments-for-fluentcart'),
                'description' => __('Located in Zoho Payments → Settings → Account Details.', 'zoho-payments-for-fluentcart'),
            ],
            $m . '_api_key' => [
                'type'        => 'password',
                'label'       => __('Zoho Payments API Key', 'zoho-payments-for-fluentcart'),
                'description' => __('Located in Zoho Payments → Developer Space → API Keys. Used to securely initialize the checkout widget.', 'zoho-payments-for-fluentcart'),
            ],
            $m . '_signing_key' => [
                'type'        => 'password',
                'label'       => __('Webhook Signing Key', 'zoho-payments-for-fluentcart'),
                'description' => __('Located in Zoho Payments → Developer Space → Webhooks / Signing Key. Used to verify webhook payloads and widget signatures.', 'zoho-payments-for-fluentcart'),
            ],
            $m . '_webhook_info' => [
                'value' => $webhookInstructions,
                'label' => __('Webhook Configuration', 'zoho-payments-for-fluentcart'),
                'type'  => 'html_attr',
            ],
            $m . '_client_id' => [
                'type'        => 'text',
                'label'       => __('OAuth Client ID', 'zoho-payments-for-fluentcart'),
                'description' => __('From your Zoho Payments ORG client in the Zoho API Console.', 'zoho-payments-for-fluentcart'),
            ],
            $m . '_client_secret' => [
                'type'        => 'password',
                'label'       => __('OAuth Client Secret', 'zoho-payments-for-fluentcart'),
                'description' => __('From your Zoho Payments ORG client in the Zoho API Console.', 'zoho-payments-for-fluentcart'),
            ],
            $m . '_oauth_instructions' => [
                'value' => $oauthInstructions,
                'label' => __('OAuth Configuration & Redirect URI', 'zoho-payments-for-fluentcart'),
                'type'  => 'html_attr',
            ],
            $m . '_oauth_connect' => [
                'type'  => 'html_attr',
                'value' => $oauthHtml,
            ],
        ];
    }

    public function fields(): array {
        $webhookInstructions = $this->getWebhookInstructions();
        $oauthInstructions   = $this->getOAuthInstructions();
        $storeMode           = $this->settings->getMode();
        $editionLabel        = $this->settings->isUS() ? 'US (zoho.com)' : 'India (zoho.in)';

        return [
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'zoho-payments-for-fluentcart'),
                        'value'  => 'live',
                        'schema' => $this->buildCredentialTabFields('live', $webhookInstructions, $oauthInstructions, $storeMode, $editionLabel),
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'zoho-payments-for-fluentcart'),
                        'value'  => 'test',
                        'schema' => $this->buildCredentialTabFields('test', $webhookInstructions, $oauthInstructions, $storeMode, $editionLabel),
                    ],
                ],
            ],
            'edition' => [
                'type'        => 'select',
                'label'       => __('Zoho Edition / Region', 'zoho-payments-for-fluentcart'),
                'description' => __('Select India (zoho.in) or United States (zoho.com) matching your Zoho Payments account.', 'zoho-payments-for-fluentcart'),
                'options'     => [
                    ['value' => 'in', 'label' => __('India (zoho.in)', 'zoho-payments-for-fluentcart')],
                    ['value' => 'com', 'label' => __('United States (zoho.com)', 'zoho-payments-for-fluentcart')],
                ],
                'value'       => 'in',
            ],
            'subscription_notice' => [
                'value' => [
                    'title'       => __('Selling Recurring Subscriptions?', 'zoho-payments-for-fluentcart'),
                    'description' => __('Zoho Payments processes one-time payments and recurring renewal invoices via manual pay links.', 'zoho-payments-for-fluentcart'),
                    'steps'       => [
                        'title' => __('To collect recurring subscription payments:', 'zoho-payments-for-fluentcart'),
                        'list'  => [
                            __('Go to FluentCart Settings → Store Settings → Subscriptions', 'zoho-payments-for-fluentcart'),
                            __('Set Renewal Collection Method to "Store-managed billing (Manual Pay Link)"', 'zoho-payments-for-fluentcart'),
                            __('FluentCart will automatically generate recurring renewal invoices and send direct payment links to your customers', 'zoho-payments-for-fluentcart'),
                        ],
                    ],
                ],
                'label' => '',
                'type'  => 'html_attr',
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array {
        return [
            ['handle' => 'zoho-payments-widget', 'src' => 'https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js'],
            ['handle' => 'zoho-payments-for-fluentcart', 'src' => ZPFC_URL . 'assets/checkout.min.js', 'version' => ZPFC_VER, 'deps' => ['zoho-payments-widget']],
        ];
    }

    public function getEnqueueStyleSrc(): array { return []; }

    public function getLocalizeData(): array {
        return [
            'fct_zoho_payments_data' => [
                'account_id'    => $this->settings->accountId(),
                'api_key'       => $this->settings->apiKey(),
                'edition'       => $this->settings->edition(),
                'domain'        => $this->settings->widgetDomain(),
                'test_mode'     => $this->settings->isTest(),
                'upi_icon'      => ZPFC_URL . 'assets/upi.svg',
                'ajax_url'      => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('zpfc_confirm'),
                'translations'  => [
                    'Processing payment...'                          => __('Processing payment…', 'zoho-payments-for-fluentcart'),
                    'Place Order'                                    => __('Place Order', 'zoho-payments-for-fluentcart'),
                    'Payment Modal is opening...'                    => __('Opening Zoho Payments, please complete payment…', 'zoho-payments-for-fluentcart'),
                    'Available payment methods'                      => __('Available payment methods', 'zoho-payments-for-fluentcart'),
                    'Cards'                                          => __('Cards', 'zoho-payments-for-fluentcart'),
                    'UPI'                                            => __('UPI', 'zoho-payments-for-fluentcart'),
                    'Net Banking'                                    => __('Net Banking', 'zoho-payments-for-fluentcart'),
                    'Wallets'                                        => __('Wallets', 'zoho-payments-for-fluentcart'),
                    'Bank Transfer (ACH)'                            => __('Bank Transfer (ACH)', 'zoho-payments-for-fluentcart'),
                    'An error occurred while loading Zoho Payments.' => __('An error occurred while loading Zoho Payments.', 'zoho-payments-for-fluentcart'),
                ],
            ],
        ];
    }

    private function currencySymbol(string $currency): string {
        static $map = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'C$', 'AUD' => 'A$', 'SGD' => 'S$', 'AED' => 'AED', 'JPY' => '¥'];
        $code = strtoupper(trim($currency));
        return $map[$code] ?? $code;
    }

    private function isCurrencyAllowed(string $currency): bool {
        $code = strtoupper(trim($currency));
        return $code !== '' && ($this->settings->isUS() || $code === 'INR');
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance) {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        if (!$order || !$transaction) {
            return ['status' => 'failed', 'message' => __('Unable to initialize the payment transaction.', 'zoho-payments-for-fluentcart')];
        }

        $currency = strtoupper(trim((string)$transaction->currency));
        if (!$this->isCurrencyAllowed($currency)) {
            return ['status' => 'failed', 'message' => __('Zoho Payments (India edition) supports INR currency only. Switch to US edition for USD/international currencies.', 'zoho-payments-for-fluentcart')];
        }

        $amount = number_format((float)Helper::toDecimalWithoutComma((int)$transaction->total), 2, '.', '');
        if ((float)$amount <= 0) {
            return ['status' => 'failed', 'message' => __('Zoho Payments cannot process a zero-value payment.', 'zoho-payments-for-fluentcart')];
        }

        $order->loadMissing('customer', 'billing_address', 'shipping_address');
        $reference   = substr((string)$transaction->uuid, 0, 50);
        $description = $this->getOrderDescription($order);

        $result = $this->api()->createSession([
            'amount'           => (float)$amount,
            'currency'         => $currency,
            'description'      => $description,
            'reference_number' => $reference,
            'meta_data'        => [['key' => 'Method', 'value' => 'FluentCart']],
        ]);

        if (is_wp_error($result)) {
            /* translators: %s: error message */
            return ['status' => 'failed', 'message' => sprintf(__('Unable to initialize Zoho Payments: %s', 'zoho-payments-for-fluentcart'), $result->get_error_message())];
        }

        $session       = $result['payments_session'] ?? ($result['payment_session'] ?? $result);
        $sessionId     = (string)($session['payments_session_id'] ?? ($session['payment_session_id'] ?? ''));
        $sessionAmount = number_format((float)($session['amount'] ?? $amount), 2, '.', '');

        if (!$sessionId || (float)$sessionAmount <= 0) {
            return ['status' => 'failed', 'message' => __('Zoho Payments returned an invalid payment session.', 'zoho-payments-for-fluentcart')];
        }

        $meta                             = (array)$transaction->meta;
        $meta['zoho_payments_session_id'] = $sessionId;
        $meta['zoho_payments_amount']     = $sessionAmount;
        $meta['zoho_payments_edition']    = $this->settings->edition();
        $transaction->meta                = $meta;
        $transaction->save();

        set_transient('zpfc_tx_' . $sessionId, (string)$transaction->uuid, 7 * DAY_IN_SECONDS);

        $customer = $order->customer;
        $billing  = $order->billing_address ?: $order->shipping_address;
        $name     = trim((string)($billing->name ?? ''));
        if (!$name && $customer) {
            $name = trim((string)($customer->first_name ?? '') . ' ' . (string)($customer->last_name ?? ''));
        }

        return [
            'status'     => 'success',
            'nextAction' => 'zoho_payments',
            'actionName' => 'custom',
            'message'    => __('Opening Zoho Payments…', 'zoho-payments-for-fluentcart'),
            'response'   => [
                'payments_session_id'  => $sessionId,
                'payment_session_id'   => $sessionId,
                'amount'               => $sessionAmount,
                'currency_code'        => $currency,
                'currency_symbol'      => $this->currencySymbol($currency),
                'description'          => $description,
                'reference_number'     => $reference,
                'address'              => [
                    'name'  => $name,
                    'email' => (string)($customer->email ?? ($order->email ?? '')),
                    'phone' => $this->sanitizePhone((string)($billing->phone ?? ($customer->phone ?? ''))),
                ],
                'api_key'              => $this->settings->apiKey(),
                'account_id'           => $this->settings->accountId(),
                'edition'              => $this->settings->edition(),
                'domain'               => $this->settings->widgetDomain(),
                'test_mode'            => $this->settings->isTest(),
                'transaction_uuid'     => (string)$transaction->uuid,
                'ajax_url'             => admin_url('admin-ajax.php'),
                'nonce'                => wp_create_nonce('zpfc_confirm'),
                'checkout_button_text' => __('Place Order', 'zoho-payments-for-fluentcart'),
            ],
        ];
    }

    private function sanitizePhone(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if ($this->settings->isUS()) {
            if (strlen($clean) === 11 && $clean[0] === '1') $clean = substr($clean, 1);
        } elseif (strlen($clean) === 12 && substr($clean, 0, 2) === '91') {
            $clean = substr($clean, 2);
        } elseif (strlen($clean) === 11 && $clean[0] === '0') {
            $clean = substr($clean, 1);
        }
        return strlen($clean) === 10 ? $clean : '';
    }

    private function getOrderDescription($order): string {
        $id = $order->id ?? '';
        return $id !== '' ? ('Order #' . $id) : 'FluentCart Order';
    }

    public function getOrderInfo($data) {
        if (class_exists('\FluentCart\Api\CurrencySettings')) {
            $currency = strtoupper((string)\FluentCart\Api\CurrencySettings::get('currency'));
            if ($currency && !$this->isCurrencyAllowed($currency)) {
                wp_send_json(['status' => 'failed', 'message' => __('Zoho Payments (India edition) supports INR currency only.', 'zoho-payments-for-fluentcart')], 422);
            }
        }

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved successfully.', 'zoho-payments-for-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'account_id' => $this->settings->accountId(),
                'api_key'    => $this->settings->apiKey(),
                'domain'     => $this->settings->widgetDomain(),
                'edition'    => $this->settings->edition(),
            ],
        ], 200);
    }

    private function normalizeRefundReason($reason): string {
        $r = strtolower(trim((string)$reason));
        if (in_array($r, ['duplicate', 'duplicate_charge'], true)) return 'duplicate';
        if (in_array($r, ['fraud', 'fraudulent'], true)) return 'fraudulent';
        if (in_array($r, ['order_change', 'order_canceled', 'cancelled', 'canceled', 'product_unsatisfactory', 'defective', 'system_error', 'other', 'others'], true)) return 'others';
        return 'requested_by_customer';
    }

    private function refundErrorFromApi($result, string $fallback = 'Unable to process the Zoho Payments refund.'): \WP_Error {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $resp = is_array($data['response'] ?? null) ? $data['response'] : [];
            $msg  = (string)($resp['message'] ?? ($resp['error'] ?? ($result->get_error_message() ?: $fallback)));
            return new \WP_Error($result->get_error_code() ?: 'zpfc_refund_failed', $msg, $data);
        }
        $msg = is_array($result)
            ? (string)($result['message'] ?? ($result['error'] ?? ($result['error_description'] ?? ($result['errors'][0]['message'] ?? $fallback))))
            : $fallback;
        return new \WP_Error('zpfc_refund_failed', $msg, is_array($result) ? $result : []);
    }

    private function extractPaymentId(array $data): string {
        return (string)(
            $data['payments'][0]['payment_id']
            ?? ($data['payments_session']['payments'][0]['payment_id'] ?? '')
            ?? ($data['payment_session']['payments'][0]['payment_id'] ?? '')
            ?? ($data['payment_id'] ?? '')
            ?? ($data['payment']['payment_id'] ?? '')
        );
    }

    private function syncOrder($transaction): void {
        if ($transaction && !empty($transaction->order_id)) {
            $order = Order::find($transaction->order_id);
            if ($order) {
                try { (new StatusHelper($order))->syncOrderStatuses($transaction); } catch (\Throwable $e) {}
            }
        }
    }

    private function extractPaymentMethodInfo(array $paymentData): array {
        $pm   = is_array($paymentData['payment_method'] ?? null)
            ? $paymentData['payment_method']
            : (is_array($paymentData['payments'][0]['payment_method'] ?? null) ? $paymentData['payments'][0]['payment_method'] : []);
        $raw  = strtolower(trim((string)($pm['type'] ?? '')));
        $map  = [
            'card'         => 'card',
            'credit_card'  => 'card',
            'debit_card'   => 'card',
            'upi'          => 'upi',
            'net_banking'  => 'net_banking',
            'netbanking'   => 'net_banking',
            'wallet'       => 'wallet',
            'wallets'      => 'wallet',
            'bank_account' => 'ach',
            'ach'          => 'ach',
        ];
        $type = $map[$raw] ?? ($raw !== '' ? $raw : 'zoho_payments');

        $card  = is_array($pm['card'] ?? null) ? $pm['card'] : [];
        $brand = trim((string)($card['brand'] ?? ($card['card_brand'] ?? ($card['network'] ?? ''))));
        $last4 = preg_replace('/[^0-9]/', '', (string)($card['last_four_digits'] ?? ($card['last4'] ?? ($card['last_4'] ?? ''))));

        return [
            'type'  => $type,
            'brand' => $brand,
            'last4' => strlen($last4) >= 4 ? substr($last4, -4) : '',
        ];
    }

    private function markSucceeded($transaction, string $paymentId = '', array $paymentData = []): void {
        $meta = (array)$transaction->meta;
        if ($paymentId) $meta['zoho_payments_id'] = $paymentId;
        $chargeId = $paymentId ?: ($meta['zoho_payments_session_id'] ?? '');

        $pmInfo = $this->extractPaymentMethodInfo($paymentData);
        $transaction->status              = 'succeeded';
        $transaction->payment_method      = 'zoho_payments';
        $transaction->payment_method_type = $pmInfo['type'];
        if ($pmInfo['brand'] !== '') $transaction->card_brand = $pmInfo['brand'];
        if ($pmInfo['last4'] !== '') $transaction->card_last_4 = $pmInfo['last4'];
        if ($chargeId) $transaction->vendor_charge_id = $chargeId;

        $meta['zoho_payment_method_type'] = $pmInfo['type'];
        $transaction->meta                = $meta;
        $transaction->save();
        $this->syncOrder($transaction);
    }

    private function parseMetaData($metaData): array {
        $parsed = [];
        if (!is_array($metaData)) return $parsed;
        foreach ($metaData as $item) {
            if (is_array($item) && isset($item['key'])) {
                $parsed[strtolower(trim((string)$item['key']))] = trim((string)($item['value'] ?? ''));
            }
        }
        return $parsed;
    }

    private function checkFluentCartMethod($metaData): ?bool {
        $parsed = $this->parseMetaData($metaData);
        return array_key_exists('method', $parsed) ? (strtolower($parsed['method']) === 'fluentcart') : null;
    }

    public function resolvePaymentId($transaction): string {
        $meta            = (array)$transaction->meta;
        $sessionId       = (string)($meta['zoho_payments_session_id'] ?? '');
        $storedPaymentId = trim((string)($meta['zoho_payments_id'] ?? ''));
        $vendorChargeId  = trim((string)($transaction->vendor_charge_id ?? ''));

        if ($storedPaymentId && $storedPaymentId !== $sessionId) return $storedPaymentId;

        if ($vendorChargeId && $vendorChargeId !== $sessionId) {
            $meta['zoho_payments_id'] = $vendorChargeId;
            $transaction->meta        = $meta;
            $transaction->save();
            return $vendorChargeId;
        }

        if ($sessionId) {
            $sessionRes = $this->api()->getSession($sessionId);
            if (!is_wp_error($sessionRes)) {
                $session = $sessionRes['payments_session'] ?? ($sessionRes['payment_session'] ?? $sessionRes);
                $pid     = $this->extractPaymentId($sessionRes) ?: $this->extractPaymentId((array)$session);
                if ($pid && $pid !== $sessionId) {
                    $meta['zoho_payments_id']      = $pid;
                    $transaction->vendor_charge_id = $pid;
                    $transaction->meta             = $meta;
                    $transaction->save();
                    return $pid;
                }
            }

            $listRes = $this->api()->listPayments(['per_page' => 50]);
            if (!is_wp_error($listRes)) {
                foreach (($listRes['payments'] ?? ($listRes['data'] ?? [])) as $item) {
                    $itemSid = (string)($item['payments_session_id'] ?? ($item['payment_session_id'] ?? ''));
                    $itemPid = (string)($item['payment_id'] ?? '');
                    $itemRef = (string)($item['reference_number'] ?? '');
                    $match   = ($itemSid && $itemSid === $sessionId)
                        || ($itemRef && $itemRef === (string)$transaction->uuid)
                        || (($this->parseMetaData($item['meta_data'] ?? [])['transaction_uuid'] ?? '') === (string)$transaction->uuid);

                    if ($match && $itemPid && $itemPid !== $sessionId) {
                        $meta['zoho_payments_id']      = $itemPid;
                        $transaction->vendor_charge_id = $itemPid;
                        $transaction->meta             = $meta;
                        $transaction->save();
                        return $itemPid;
                    }
                }
            }
        }

        return $storedPaymentId ?: ($vendorChargeId ?: $sessionId);
    }

    public function processRefund($transaction, $amount, $args) {
        $chargeId = $this->resolvePaymentId($transaction);
        if (!$chargeId) {
            return new \WP_Error('zpfc_no_charge_id', __('Zoho Payments charge ID is missing on this transaction.', 'zoho-payments-for-fluentcart'));
        }

        $amountToRefund = (float)Helper::toDecimalWithoutComma((int)$amount);
        if ($amountToRefund <= 0) {
            return new \WP_Error('zpfc_invalid_amount', __('Refund amount must be greater than zero.', 'zoho-payments-for-fluentcart'));
        }

        $lockKey = 'zpfc_refund_lock_' . md5($chargeId . '|' . number_format($amountToRefund, 2, '.', ''));
        $hasLock = add_option($lockKey, time(), '', 'no');
        if (!$hasLock) {
            if ((time() - (int)get_option($lockKey, 0)) > 60) {
                delete_option($lockKey);
                $hasLock = add_option($lockKey, time(), '', 'no');
            } else {
                return new \WP_Error('zpfc_concurrent_refund', __('A refund for this charge is currently in progress. Please wait a moment.', 'zoho-payments-for-fluentcart'));
            }
        }

        try {
            $refundMeta = [
                ['key' => 'Method', 'value' => 'FluentCart'],
                ['key' => 'transaction_uuid', 'value' => (string)$transaction->uuid],
                ['key' => 'parent_charge_id', 'value' => $chargeId],
            ];
            if (!empty($args['refund_transaction_id'])) {
                $refundMeta[] = ['key' => 'refund_transaction_id', 'value' => (string)$args['refund_transaction_id']];
            }

            $result = $this->api()->refund(
                $chargeId,
                $amountToRefund,
                $this->normalizeRefundReason($args['reason'] ?? 'requested_by_customer'),
                sanitize_text_field(substr((string)($args['note'] ?? 'Refund from FluentCart'), 0, 500)),
                $refundMeta
            );
            if (is_wp_error($result)) return $this->refundErrorFromApi($result);

            $refund   = $result['refund'] ?? $result;
            $refundId = (string)($refund['refund_id'] ?? '');
            $status   = strtolower((string)($refund['status'] ?? ''));
            if (!$refundId) {
                return $this->refundErrorFromApi($result, __('Zoho Payments did not return a valid refund confirmation.', 'zoho-payments-for-fluentcart'));
            }

            $isSuccess = in_array($status, ['succeeded', 'success', 'completed'], true);
            $isPending = in_array($status, ['initiated', 'processing', 'in_progress', 'pending'], true);
            if (!$isSuccess && !$isPending) {
                return new \WP_Error('zpfc_refund_failed', (string)($refund['failure_reason'] ?? ($refund['failure_message'] ?? 'Zoho Payments rejected the refund request.')), $refund);
            }

            $refundStatus = $isSuccess ? 'refunded' : 'processing';
            $pmType       = (string)($transaction->payment_method_type ?: 'zoho_payments');

            // If FluentCart pre-created a refund transaction, update it in place with the authoritative Zoho refund ID
            if (!empty($args['refund_transaction_id']) && ($refundTx = OrderTransaction::find($args['refund_transaction_id']))) {
                $refundTx->status              = $refundStatus;
                $refundTx->vendor_charge_id    = $refundId;
                $refundTx->payment_method      = 'zoho_payments';
                $refundTx->payment_method_type = $pmType;
                $rMeta                         = (array)$refundTx->meta;
                $rMeta['zoho_refund_id']       = $refundId;
                $rMeta['zoho_payment_id']      = $chargeId;
                $rMeta['zoho_refund_status']   = $status;
                $refundTx->meta                = $rMeta;
                $refundTx->save();
            }

            // Return the Zoho refund ID string so FluentCart records the single refund transaction with accurate minor-unit total & Gateway ID
            return $refundId;
        } finally {
            if ($hasLock) delete_option($lockKey);
        }
    }

    private function verifyPayment($transaction, string $paymentId = '', string $signature = '') {
        $paymentId         = sanitize_text_field(trim($paymentId));
        $expectedSessionId = (string)($transaction->meta['zoho_payments_session_id'] ?? '');
        $expectedCurrency  = strtoupper(trim((string)$transaction->currency));

        if ($paymentId) {
            $res = $this->api()->getPayment($paymentId);
            if (!is_wp_error($res)) {
                $payment   = $res['payment'] ?? $res;
                $actualSid = (string)($payment['payments_session_id'] ?? '');

                if ($expectedSessionId && $actualSid && $actualSid !== $expectedSessionId) {
                    return new \WP_Error('zpfc_mismatch', __('Zoho payment session does not match this transaction.', 'zoho-payments-for-fluentcart'));
                }

                $expectedAmount = (float)number_format((float)Helper::toDecimalWithoutComma((int)$transaction->total), 2, '.', '');
                $paidAmount     = (float)($payment['amount_captured'] ?? ($payment['amount'] ?? 0));
                if (abs($paidAmount - $expectedAmount) > 0.05) {
                    return new \WP_Error('zpfc_amount', __('Zoho payment amount does not match the FluentCart transaction total.', 'zoho-payments-for-fluentcart'));
                }

                $actualCurrency = strtoupper(trim((string)($payment['currency'] ?? '')));
                if ($expectedCurrency && $actualCurrency && $actualCurrency !== $expectedCurrency) {
                    return new \WP_Error('zpfc_currency', __('Unexpected payment currency.', 'zoho-payments-for-fluentcart'));
                }

                $signingKey = $this->settings->signingKey();
                if ($signingKey && $signature) {
                    $expectedSig = hash_hmac('sha256', $paymentId . '|' . $expectedSessionId, $signingKey);
                    if (!hash_equals(strtolower($expectedSig), strtolower(trim($signature)))) {
                        return new \WP_Error('zpfc_signature', __('Invalid Zoho payment signature.', 'zoho-payments-for-fluentcart'));
                    }
                }

                return $payment;
            }
        }

        if ($expectedSessionId) {
            $sessionRes = $this->api()->getSession($expectedSessionId);
            if (!is_wp_error($sessionRes)) {
                $session = $sessionRes['payments_session'] ?? ($sessionRes['payment_session'] ?? $sessionRes);
                $status  = strtolower((string)($session['payment_session_status'] ?? ($session['status'] ?? '')));
                if (in_array($status, ['succeeded', 'active', 'completed', 'authorized'], true)) {
                    return $session;
                }
            }
        }

        return new \WP_Error('zpfc_payment', __('Unable to verify Zoho payment status.', 'zoho-payments-for-fluentcart'));
    }

    public function confirmPayment() {
        if (!check_ajax_referer('zpfc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed. Please refresh and try again.', 'zoho-payments-for-fluentcart')], 403);
        }

        $txid        = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
        $pid         = sanitize_text_field(wp_unslash($_POST['payment_id'] ?? ''));
        $sig         = sanitize_text_field(wp_unslash($_POST['signature'] ?? ''));
        $transaction = OrderTransaction::query()->where('uuid', $txid)->first();

        if (!$transaction) wp_send_json_error(['message' => __('Transaction not found.', 'zoho-payments-for-fluentcart')], 404);
        if ($transaction->status === 'succeeded') wp_send_json_success(['redirect_url' => $transaction->getReceiptPageUrl()]);

        $payment = $this->verifyPayment($transaction, $pid, $sig);
        if (is_wp_error($payment)) wp_send_json_error(['message' => $payment->get_error_message()], 400);

        $remoteStatus = strtolower((string)($payment['status'] ?? ($payment['payment_session_status'] ?? 'succeeded')));
        if (!in_array($remoteStatus, ['succeeded', 'active', 'completed', 'authorized'], true)) {
            wp_send_json_error(['message' => __('Payment has not succeeded yet.', 'zoho-payments-for-fluentcart')], 400);
        }

        $pid = $pid ?: ($this->extractPaymentId((array)$payment) ?: $this->resolvePaymentId($transaction));
        $this->markSucceeded($transaction, $pid, is_array($payment) ? $payment : []);
        wp_send_json_success(['redirect_url' => $transaction->getReceiptPageUrl()]);
    }

    public function paymentStatus() {
        if (!check_ajax_referer('zpfc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'zoho-payments-for-fluentcart')], 403);
        }

        $txid        = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
        $transaction = OrderTransaction::query()->where('uuid', $txid)->first();
        if (!$transaction) wp_send_json_error(['message' => __('Transaction not found.', 'zoho-payments-for-fluentcart')], 404);

        if ($transaction->status === 'succeeded') {
            wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $transaction->getReceiptPageUrl()]);
        }
        if (in_array($transaction->status, ['failed', 'canceled', 'cancelled'], true)) {
            wp_send_json_success(['transaction_status' => $transaction->status]);
        }

        $sessionId = (string)($transaction->meta['zoho_payments_session_id'] ?? '');
        if (!$sessionId) wp_send_json_success(['transaction_status' => (string)$transaction->status]);

        $result = $this->api()->getSession($sessionId);
        if (is_wp_error($result)) wp_send_json_success(['transaction_status' => (string)$transaction->status]);

        $session      = $result['payments_session'] ?? ($result['payment_session'] ?? $result);
        $remoteStatus = strtolower((string)($session['payment_session_status'] ?? ($session['status'] ?? '')));

        if (in_array($remoteStatus, ['succeeded', 'active', 'completed'], true)) {
            $pid = $this->extractPaymentId((array)$session) ?: ($this->extractPaymentId((array)$result) ?: $this->resolvePaymentId($transaction));
            $this->markSucceeded($transaction, $pid, is_array($session) ? $session : []);
            wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $transaction->getReceiptPageUrl()]);
        }
        if (in_array($remoteStatus, ['failed', 'expired', 'canceled', 'cancelled'], true)) {
            $transaction->status = 'failed';
            $transaction->save();
            $this->syncOrder($transaction);
            wp_send_json_success(['transaction_status' => 'failed']);
        }

        wp_send_json_success(['transaction_status' => (string)$transaction->status, 'zoho_status' => $remoteStatus]);
    }

    private function ackWebhook(string $eventKey = '', int $status = 200, string $msg = 'Webhook processed successfully.'): void {
        if ($eventKey !== '') set_transient($eventKey, true, 7 * DAY_IN_SECONDS);
        if (function_exists('nocache_headers')) nocache_headers();
        wp_send_json(['status' => ($status >= 200 && $status < 300) ? 'ok' : 'error', 'message' => $msg], $status);
    }

    public function handleIPN() {
        $raw             = file_get_contents('php://input');
        $signatureHeader = isset($_SERVER['HTTP_X_ZOHO_WEBHOOK_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ZOHO_WEBHOOK_SIGNATURE'])) : (isset($_SERVER['REDIRECT_HTTP_X_ZOHO_WEBHOOK_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_X_ZOHO_WEBHOOK_SIGNATURE'])) : '');
        if (!$signatureHeader && function_exists('getallheaders')) {
            $signatureHeader = (string)(array_change_key_case((array)getallheaders(), CASE_LOWER)['x-zoho-webhook-signature'] ?? '');
        }

        if (!$this->verifyWebhookSignature($raw, $signatureHeader)) $this->ackWebhook('', 401, 'Invalid webhook signature.');

        $data = json_decode($raw, true);
        if (!is_array($data)) $this->ackWebhook('', 400, 'Invalid JSON payload.');

        $event    = (string)($data['event_type'] ?? ($data['event'] ?? ''));
        $eventId  = (string)($data['event_id_str'] ?? ($data['event_id'] ?? ''));
        $eventKey = $eventId !== '' ? 'zpfc_webhook_' . md5($this->settings->contextKey() . '|' . $eventId) : '';

        if ($eventKey && get_transient($eventKey)) $this->ackWebhook('', 200, 'Duplicate webhook event already processed.');

        $eventObject = is_array($data['event_object'] ?? null) ? $data['event_object'] : (is_array($data['data']['event_object'] ?? null) ? $data['data']['event_object'] : []);
        $eventLower  = strtolower($event);

        // Refund Webhooks (Zoho sends refund.succeeded / refund.failed)
        if (strpos($eventLower, 'refund.') === 0) {
            if (!in_array($eventLower, ['refund.succeeded', 'refund.succeed', 'refund.failed'], true)) {
                $this->ackWebhook($eventKey, 200, 'Ignored: unsupported refund webhook event.');
            }

            $refund = $eventObject['refund'] ?? ($data['data']['refund'] ?? ($data['refund'] ?? null));
            if (!is_array($refund)) $this->ackWebhook($eventKey, 200, 'Ignored: missing refund object.');
            if ($this->checkFluentCartMethod($refund['meta_data'] ?? []) === false) $this->ackWebhook($eventKey, 200, 'Ignored: event belongs to another method.');

            $refundId  = sanitize_text_field((string)($refund['refund_id'] ?? ''));
            $paymentId = sanitize_text_field((string)($refund['payment_id'] ?? ''));
            if (!$refundId) $this->ackWebhook($eventKey, 200, 'Ignored: missing refund ID.');

            $refundMeta        = $this->parseMetaData($refund['meta_data'] ?? []);
            $refundTransaction = OrderTransaction::query()->where('vendor_charge_id', $refundId)->first();
            $parentTransaction = !empty($refundMeta['transaction_uuid']) ? OrderTransaction::query()->where('uuid', $refundMeta['transaction_uuid'])->first() : null;
            if (!$refundTransaction && !$parentTransaction && $paymentId) {
                $parentTransaction = OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first();
            }
            if (!$refundTransaction && !$parentTransaction) $this->ackWebhook($eventKey, 200, 'Ignored: matching FluentCart refund transaction not found.');

            $status      = $eventLower === 'refund.failed' ? 'failed' : 'refunded';
            $amountMinor = (int)round(((float)($refund['amount'] ?? 0)) * 100);
            if ($amountMinor <= 0 && $refundTransaction) $amountMinor = (int)($refundTransaction->total ?? 0);

            $pmType = (string)(($parentTransaction->payment_method_type ?? '') ?: ($refundTransaction->payment_method_type ?? '') ?: 'zoho_payments');

            if ($refundTransaction) {
                if ($refundTransaction->status !== 'refunded') $refundTransaction->status = $status;
                if ($amountMinor > 0 && empty($refundTransaction->total)) $refundTransaction->total = $amountMinor;
                if (empty($refundTransaction->payment_method_type)) $refundTransaction->payment_method_type = $pmType;
                $meta                       = (array)$refundTransaction->meta;
                $meta['zoho_refund_status'] = $status;
                if (!empty($refund['failure_reason'])) $meta['zoho_refund_failure_reason'] = (string)$refund['failure_reason'];
                if ($paymentId) $meta['zoho_payment_id'] = $paymentId;
                $refundTransaction->meta = $meta;
                $refundTransaction->save();
            } else {
                Refund::createOrRecordRefund([
                    'vendor_charge_id'    => $refundId,
                    'payment_method'      => 'zoho_payments',
                    'payment_method_type' => $pmType,
                    'payment_mode'        => $parentTransaction->payment_mode ?? $this->settings->getMode(),
                    'status'              => $status,
                    'total'               => $amountMinor,
                ], $parentTransaction);
            }

            $this->ackWebhook($eventKey, 200, 'Refund status updated to ' . $status . '.');
        }

        // Payment Webhooks
        $payment = $eventObject['payment'] ?? ($data['data']['payment'] ?? ($data['payment'] ?? null));
        if (!is_array($payment)) $this->ackWebhook($eventKey, 200, 'Ignored: missing payment object.');

        $methodCheck = $this->checkFluentCartMethod($payment['meta_data'] ?? []);
        if ($methodCheck === false) $this->ackWebhook($eventKey, 200, 'Ignored: event belongs to another method.');

        $paymentId = sanitize_text_field((string)($payment['payment_id'] ?? ''));
        $sessionId = sanitize_text_field((string)($payment['payments_session_id'] ?? ($payment['payment_session_id'] ?? '')));
        if (!$paymentId && !$sessionId) $this->ackWebhook($eventKey, 200, 'Ignored: missing payment and session identifiers.');

        $paymentMeta     = $this->parseMetaData($payment['meta_data'] ?? []);
        $transactionUuid = sanitize_text_field((string)($payment['reference_number'] ?? ($paymentMeta['transaction_uuid'] ?? '')));
        if (!$transactionUuid && $sessionId) $transactionUuid = (string)get_transient('zpfc_tx_' . $sessionId);

        if (($methodCheck === null || !$transactionUuid) && $paymentId) {
            $remote = $this->api()->getPayment($paymentId);
            if (!is_wp_error($remote)) {
                $rp = $remote['payment'] ?? $remote;
                if ($methodCheck === null) {
                    $methodCheck = $this->checkFluentCartMethod($rp['meta_data'] ?? []);
                    if ($methodCheck === false) $this->ackWebhook($eventKey, 200, 'Ignored: event belongs to another method.');
                }
                if (!$sessionId) $sessionId = sanitize_text_field((string)($rp['payments_session_id'] ?? ''));
                if (!$transactionUuid) {
                    $rpMeta          = $this->parseMetaData($rp['meta_data'] ?? []);
                    $transactionUuid = sanitize_text_field((string)($rp['reference_number'] ?? ($rpMeta['transaction_uuid'] ?? '')));
                }
            }
        }

        if ($methodCheck !== true) $this->ackWebhook($eventKey, 200, 'Ignored: event is not tagged for FluentCart.');
        if (!$transactionUuid) $this->ackWebhook($eventKey, 200, 'Ignored: unable to resolve FluentCart transaction reference.');

        $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
        if (!$transaction) $this->ackWebhook($eventKey, 200, 'Ignored: matching FluentCart transaction not found.');

        $isSuccessEvent = $eventLower === 'payment.succeeded' || strpos($eventLower, 'payment.succeed') !== false || (strpos($eventLower, 'payment') !== false && strpos($eventLower, 'success') !== false);
        if ($isSuccessEvent && $paymentId) {
            if ($transaction->status === 'succeeded') $this->ackWebhook($eventKey, 200, 'Payment already marked as succeeded.');

            $verified = $this->verifyPayment($transaction, $paymentId);
            if (is_wp_error($verified) || !in_array(strtolower((string)($verified['status'] ?? '')), ['succeeded', 'completed'], true)) {
                $this->ackWebhook('', 500, 'Webhook payment verification failed: ' . (is_wp_error($verified) ? $verified->get_error_message() : 'Remote payment status is not succeeded.'));
            }

            $this->markSucceeded($transaction, $paymentId, is_array($verified) ? $verified : $payment);
            $this->ackWebhook($eventKey, 200, 'Payment succeeded and FluentCart order updated.');
        }

        if ($eventLower === 'payment.failed' || strpos($eventLower, 'payment.fail') !== false) {
            if ($transaction->status === 'succeeded') $this->ackWebhook($eventKey, 200, 'Ignored: transaction already succeeded.');

            if ($sessionId) {
                $sessionRes = $this->api()->getSession($sessionId);
                if (!is_wp_error($sessionRes)) {
                    $session       = $sessionRes['payments_session'] ?? $sessionRes;
                    $sessionStatus = strtolower((string)($session['payment_session_status'] ?? ($session['status'] ?? '')));
                    if (in_array($sessionStatus, ['failed', 'expired', 'canceled', 'cancelled'], true)) {
                        $transaction->status                 = 'failed';
                        $meta                                = (array)$transaction->meta;
                        $meta['zoho_payment_failure_code']   = (string)($payment['failure_code'] ?? '');
                        $meta['zoho_payment_failure_reason'] = (string)($payment['failure_category'] ?? ($payment['tip'] ?? 'Payment failed'));
                        $transaction->meta                   = $meta;
                        $transaction->save();
                        $this->syncOrder($transaction);
                        $this->ackWebhook($eventKey, 200, 'Payment marked as failed.');
                    }
                }
            }
            $this->ackWebhook($eventKey, 200, 'Payment attempt failed; session still active.');
        }

        $this->ackWebhook($eventKey, 200, 'Ignored: unhandled webhook event (' . $event . ').');
    }

    private function verifyWebhookSignature(string $raw, string $header): bool {
        $key = $this->settings->signingKey();
        if (!$raw || !$header || !$key) return false;

        $ts = $val = '';
        foreach (preg_split('/[,;]\s*/', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) continue;
            $k = strtolower(trim($pair[0]));
            if ($k === 't') $ts = trim($pair[1]);
            elseif ($k === 'v' || $k === 'v1') $val = trim($pair[1]);
        }

        if (!$ts || !$val) return false;
        $tsSec = strlen($ts) > 11 ? (int)($ts / 1000) : (int)$ts;
        if (abs(time() - $tsSec) > 900) return false;

        return hash_equals(strtolower(hash_hmac('sha256', $ts . '.' . $raw, $key)), strtolower($val));
    }

    public function getWebhookUrl(): string {
        return add_query_arg(['fluent-cart' => 'fct_payment_listener_ipn', 'method' => 'zoho_payments'], home_url('/'));
    }

    public function startOAuth() {
        if (!current_user_can('manage_options') || !check_admin_referer('zpfc_oauth_connect')) {
            wp_die(esc_html__('Unauthorized request.', 'zoho-payments-for-fluentcart'));
        }

        $requestedMode = sanitize_key(wp_unslash($_GET['mode'] ?? ''));
        $mode          = in_array($requestedMode, ['live', 'test'], true) ? $requestedMode : $this->settings->getMode();

        $missing = $this->settings->missingOAuthSettings($mode);
        if ($missing) {
            wp_die(esc_html(sprintf(
                /* translators: %s: comma-separated list of missing settings */
                __('Zoho Payments OAuth cannot start because required settings are missing: %s. Please save these settings first.', 'zoho-payments-for-fluentcart'),
                implode(', ', $missing)
            )));
        }

        $state = wp_generate_password(32, false, false);
        set_transient('zpfc_oauth_state_' . $state, [
            'user'    => get_current_user_id(),
            'mode'    => $mode,
            'edition' => $this->settings->edition(),
        ], 15 * MINUTE_IN_SECONDS);

        $prefix = $mode === 'test' ? 'ZohoPaySandbox' : 'ZohoPay';
        $scopes = [
            $prefix . '.payments.CREATE',
            $prefix . '.payments.READ',
            $prefix . '.payments.UPDATE',
            $prefix . '.refunds.CREATE',
            $prefix . '.refunds.READ',
        ];

        add_filter('allowed_redirect_hosts', static function (array $hosts): array {
            $hosts[] = 'accounts.zoho.in';
            $hosts[] = 'accounts.zoho.com';
            return $hosts;
        });
        wp_safe_redirect(add_query_arg([
            'scope'         => implode(',', $scopes),
            'client_id'     => $this->settings->clientId($mode),
            'soid'          => $prefix . '.' . $this->settings->accountId($mode),
            'response_type' => 'code',
            'redirect_uri'  => $this->settings->oauthCallbackUrl(),
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ], $this->settings->accountsUrl() . '/oauth/v2/org/auth'));
        exit;
    }

    public function disconnectOAuth() {
        if (!current_user_can('manage_options') || !check_admin_referer('zpfc_oauth_disconnect')) {
            wp_die(esc_html__('Unauthorized request.', 'zoho-payments-for-fluentcart'));
        }
        $requestedMode = sanitize_key(wp_unslash($_GET['mode'] ?? ''));
        $mode          = in_array($requestedMode, ['live', 'test'], true) ? $requestedMode : $this->settings->getMode();
        $this->settings->disconnectOAuth($mode);
        wp_safe_redirect(admin_url('admin.php?page=fluent-cart') . '#/settings/payments/zoho_payments');
        exit;
    }

    public function oauthCallback() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth 2.0 callback verified via cryptographic one-time state transient.
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $code  = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $error = sanitize_text_field(wp_unslash($_GET['error'] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($error) $this->renderOAuthResult(false, __('Zoho OAuth authorization was canceled or denied.', 'zoho-payments-for-fluentcart'));
        if (!$state || !$code) $this->renderOAuthResult(false, __('Zoho did not return a valid authorization code.', 'zoho-payments-for-fluentcart'));

        $ctx = get_transient('zpfc_oauth_state_' . $state);
        delete_transient('zpfc_oauth_state_' . $state);
        if (!is_array($ctx)) $this->renderOAuthResult(false, __('This OAuth request is invalid or has expired. Please try connecting again.', 'zoho-payments-for-fluentcart'));
        if (!is_user_logged_in() || get_current_user_id() !== (int)($ctx['user'] ?? 0)) {
            $this->renderOAuthResult(false, __('The OAuth callback user does not match the administrator who initiated the request.', 'zoho-payments-for-fluentcart'));
        }
        if ($this->settings->edition() !== ($ctx['edition'] ?? '')) {
            $this->renderOAuthResult(false, __('The Zoho edition changed during authorization. Please reconnect.', 'zoho-payments-for-fluentcart'));
        }

        $mode = in_array($ctx['mode'] ?? '', ['live', 'test'], true) ? (string)$ctx['mode'] : $this->settings->getMode();

        $res = API::oauthExchange($code, $this->settings->clientId($mode), $this->settings->clientSecret($mode), $this->settings->oauthCallbackUrl(), $this->settings->accountsUrl());
        if (is_wp_error($res)) $this->renderOAuthResult(false, $res->get_error_message());
        if (!$this->settings->storeOAuthRefreshToken((string)$res['refresh_token'], $mode)) {
            $this->renderOAuthResult(false, __('Failed to store refresh token securely.', 'zoho-payments-for-fluentcart'));
        }

        $this->api()->clearTokenCache($mode);
        if (!empty($res['access_token'])) {
            $this->api()->primeToken((string)$res['access_token'], (int)($res['expires_in'] ?? 3600), $mode);
        }

        $this->renderOAuthResult(true, __('Your Zoho Payments account has been connected successfully!', 'zoho-payments-for-fluentcart'));
    }

    private function renderOAuthResult(bool $success, string $message): void {
        $url    = admin_url('admin.php?page=fluent-cart') . '#/settings/payments/zoho_payments';
        $title  = $success ? __('Zoho Payments Connected', 'zoho-payments-for-fluentcart') : __('Connection Failed', 'zoho-payments-for-fluentcart');
        $accent = $success ? '#16a34a' : '#dc2626';

        status_header($success ? 200 : 400);
        nocache_headers();

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title>'
            . '<style>body{margin:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{width:min(480px,calc(100% - 32px));background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:36px;text-align:center;border:1px solid #e2e8f0}.ic{width:60px;height:60px;border-radius:50%;background:' . esc_attr($accent) . ';color:#fff;font-size:34px;line-height:60px;margin:0 auto 18px;font-weight:700}h1{font-size:22px;margin:0 0 10px}p{font-size:15px;line-height:1.6;color:#475569;margin:0 0 24px}a{display:inline-block;padding:11px 22px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}a:hover{background:#1e293b}</style></head>'
            . '<body><div class="card"><div class="ic">' . esc_html($success ? '✓' : '×') . '</div><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p><a href="' . esc_url($url) . '">' . esc_html__('Back to FluentCart Settings', 'zoho-payments-for-fluentcart') . '</a></div></body></html>';
        exit;
    }
}
