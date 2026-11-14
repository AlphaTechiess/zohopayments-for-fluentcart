<?php
namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class Settings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_razorpay';

    private array $cachedSecrets = [];

    public static function getDefaults()
    {
        return [
            'is_active'           => 'no',
            'payment_mode'        => 'live',
            'live_api_key'        => '',
            'live_key_secret'     => '',
            'live_webhook_secret' => '',
            'test_api_key'        => '',
            'test_key_secret'     => '',
            'test_webhook_secret' => '',
            'api_key'             => '',
            'api_secret'          => '',
            'webhook_secret'      => '',
        ];
    }

    public function get($key = '', $default = null)
    {
        if ($key === '' || $key === null) {
            return $this->settings;
        }

        return array_key_exists($key, $this->settings)
            ? $this->settings[$key]
            : $default;
    }

    public function getMode()
    {
        return $this->mode();
    }

    public function isActive()
    {
        return $this->get('is_active', 'no') === 'yes';
    }

    public function mode()
    {
        if (class_exists('\FluentCart\Api\StoreSettings')) {
            try {
                $orderMode = (new \FluentCart\Api\StoreSettings)->get('order_mode');
                if (!empty($orderMode)) {
                    return $orderMode === 'test' ? 'test' : 'live';
                }
            } catch (\Throwable $e) {}
        }
        $paymentMode = $this->get('payment_mode', '');
        if ($paymentMode === 'test' || $paymentMode === 'live') {
            return $paymentMode;
        }
        $key = $this->get('api_key', '') ?: $this->get('live_api_key', '') ?: $this->get('test_api_key', '');
        return strpos($key, 'rzp_test_') === 0 ? 'test' : 'live';
    }

    public function isTest()
    {
        return $this->mode() === 'test';
    }

    public function isConfigured()
    {
        return !empty($this->apiKey()) && !empty($this->apiSecret());
    }

    public function apiKey($mode = null)
    {
        $mode = $mode ?: $this->mode();
        if ($mode === 'test') {
            $key = $this->get('test_api_key', '');
            if (!empty($key)) {
                return trim((string) $key);
            }
        } else {
            $key = $this->get('live_api_key', '');
            if (!empty($key)) {
                return trim((string) $key);
            }
        }
        return trim((string) $this->get('api_key', ''));
    }

    public function getApiKey($mode = null)
    {
        return $this->apiKey($mode);
    }

    public function secret($key)
    {
        if (isset($this->cachedSecrets[$key])) {
            return $this->cachedSecrets[$key];
        }

        $value = $this->get($key, '');
        if (!$value) {
            return $this->cachedSecrets[$key] = '';
        }

        if (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($value)) {
            $decrypted = Helper::decryptKey($value);
            return $this->cachedSecrets[$key] = ($decrypted === false ? '' : trim((string) $decrypted));
        }

        return $this->cachedSecrets[$key] = trim((string) $value);
    }

    public function apiSecret($mode = null)
    {
        $mode = $mode ?: $this->mode();
        if ($mode === 'test') {
            $sec = $this->secret('test_key_secret');
            if (!empty($sec)) {
                return $sec;
            }
        } else {
            $sec = $this->secret('live_key_secret');
            if (!empty($sec)) {
                return $sec;
            }
        }
        return $this->secret('api_secret');
    }

    public function getKeySecret($mode = null)
    {
        return $this->apiSecret($mode);
    }

    public function webhookSecret($mode = null)
    {
        $mode = $mode ?: $this->mode();
        if ($mode === 'test') {
            $sec = $this->secret('test_webhook_secret');
            if (!empty($sec)) {
                return $sec;
            }
        } else {
            $sec = $this->secret('live_webhook_secret');
            if (!empty($sec)) {
                return $sec;
            }
        }
        return $this->secret('webhook_secret');
    }

    public function getWebhookSecret($mode = null)
    {
        return $this->webhookSecret($mode);
    }

    public function hasWebhookSecret()
    {
        return $this->webhookSecret() !== '';
    }

    public function getWebhookUrl()
    {
        return add_query_arg(
            ['fluent-cart' => 'fct_payment_listener_ipn', 'method' => 'razorpay'],
            home_url('/')
        );
    }

    public function getWebhookInstructions(): array
    {
        return [
            'title'       => __('Webhook URL', 'razorpay-for-fluentcart'),
            'webhook_url' => esc_url($this->getWebhookUrl()),
            'description' => __('Configure this webhook URL and secret in your Razorpay Dashboard for real-time payment and subscription status synchronization.', 'razorpay-for-fluentcart'),
            'steps'       => [
                'title' => __('How to configure?', 'razorpay-for-fluentcart'),
                'list'  => [
                    __('Log in to your Razorpay Dashboard and navigate to Settings &rarr; Webhooks', 'razorpay-for-fluentcart'),
                    __('Click on "Add New Webhook" and paste the Webhook URL above into the Webhook URL field', 'razorpay-for-fluentcart'),
                    __('Enter your Webhook Secret (matching the Secret saved in credentials above)', 'razorpay-for-fluentcart'),
                    __('Select the required webhook events listed below and click Save Webhook', 'razorpay-for-fluentcart'),
                ],
            ],
            'events' => [
                'title' => __('Required Webhook Events', 'razorpay-for-fluentcart'),
                'list'  => [
                    'payment.authorized',
                    'payment.captured',
                    'payment.failed',
                    'refund.processed',
                    'subscription.authenticated',
                    'subscription.activated',
                    'subscription.charged',
                    'subscription.cancelled',
                    'subscription.halted',
                    'subscription.completed',
                ],
            ],
        ];
    }

    public function getFields($gateway = null): array
    {
        $fields = [];

        if ($gateway && method_exists($gateway, 'renderStoreModeNotice')) {
            $storeNotice = $gateway->renderStoreModeNotice();
            if ($storeNotice) {
                $fields['notice'] = [
                    'value' => $storeNotice,
                    'label' => __('Store Mode notice', 'razorpay-for-fluentcart'),
                    'type'  => 'notice',
                ];
            }
        }

        $fields['payment_mode'] = [
            'type'   => 'tabs',
            'schema' => [
                [
                    'type'   => 'tab',
                    'label'  => __('Live credentials', 'razorpay-for-fluentcart'),
                    'value'  => 'live',
                    'schema' => [
                        'live_api_key' => [
                            'value'       => $this->get('live_api_key', $this->mode() === 'live' ? $this->get('api_key', '') : ''),
                            'label'       => __('Live API Key', 'razorpay-for-fluentcart'),
                            'type'        => 'text',
                            'placeholder' => __('rzp_live_xxxxxxxxxxxxx', 'razorpay-for-fluentcart'),
                        ],
                        'live_key_secret' => [
                            'value'       => $this->get('live_key_secret', $this->mode() === 'live' ? $this->get('api_secret', '') : ''),
                            'label'       => __('Live Key Secret', 'razorpay-for-fluentcart'),
                            'type'        => 'password',
                            'placeholder' => __('Your live key secret', 'razorpay-for-fluentcart'),
                        ],
                        'live_webhook_secret' => [
                            'value'       => $this->get('live_webhook_secret', $this->mode() === 'live' ? $this->get('webhook_secret', '') : ''),
                            'label'       => __('Live Webhook Secret', 'razorpay-for-fluentcart'),
                            'type'        => 'password',
                            'placeholder' => __('Your live webhook secret', 'razorpay-for-fluentcart'),
                        ],
                    ],
                ],
                [
                    'type'   => 'tab',
                    'label'  => __('Test credentials', 'razorpay-for-fluentcart'),
                    'value'  => 'test',
                    'schema' => [
                        'test_api_key' => [
                            'value'       => $this->get('test_api_key', $this->mode() === 'test' ? $this->get('api_key', '') : ''),
                            'label'       => __('Test API Key', 'razorpay-for-fluentcart'),
                            'type'        => 'text',
                            'placeholder' => __('rzp_test_xxxxxxxxxxxxx', 'razorpay-for-fluentcart'),
                        ],
                        'test_key_secret' => [
                            'value'       => $this->get('test_key_secret', $this->mode() === 'test' ? $this->get('api_secret', '') : ''),
                            'label'       => __('Test Key Secret', 'razorpay-for-fluentcart'),
                            'type'        => 'password',
                            'placeholder' => __('Your test key secret', 'razorpay-for-fluentcart'),
                        ],
                        'test_webhook_secret' => [
                            'value'       => $this->get('test_webhook_secret', $this->mode() === 'test' ? $this->get('webhook_secret', '') : ''),
                            'label'       => __('Test Webhook Secret', 'razorpay-for-fluentcart'),
                            'type'        => 'password',
                            'placeholder' => __('Your test webhook secret', 'razorpay-for-fluentcart'),
                        ],
                    ],
                ],
            ],
        ];

        $fields['international_payments_notice'] = [
            'value' => [
                'title'       => __('International Payments', 'razorpay-for-fluentcart'),
                'description' => __('If you want to accept payments from international customers:', 'razorpay-for-fluentcart'),
                'steps'       => [
                    'title' => __('You must:', 'razorpay-for-fluentcart'),
                    'list'  => [
                        __('Enable International Payments in your Razorpay Dashboard', 'razorpay-for-fluentcart'),
                        __('Go to Settings &rarr; Configuration &rarr; Payment Methods', 'razorpay-for-fluentcart'),
                    ],
                ],
            ],
            'label' => '',
            'type'  => 'html_attr',
        ];

        $fields['webhook_info'] = [
            'value' => $this->getWebhookInstructions(),
            'label' => __('Webhook Configuration', 'razorpay-for-fluentcart'),
            'type'  => 'html_attr',
        ];

        return $fields;
    }
}
