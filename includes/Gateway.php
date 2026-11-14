<?php
namespace RazorpayFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Subscription\SubscriptionRenewal;

class Gateway extends AbstractPaymentGateway
{
    protected bool $isBooted = false;
    protected static array $cancellingVendorIds = [];
    protected static array $runtimePlanCache = [];
    private ?API $apiInstance = null;

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'custom_payment',
        'subscriptions',
        'system_subscription',
    ];

    public function __construct()
    {
        parent::__construct(new Settings());
        $this->boot();
    }

    public function init(): void
    {
        parent::init();
        $this->boot();
    }

    private function api(): API
    {
        return $this->apiInstance ?? ($this->apiInstance = new API($this->settings));
    }

    public function boot()
    {
        if ($this->isBooted) {
            return;
        }
        $this->isBooted = true;

        add_action('wp_ajax_rpfc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_rpfc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_fluent_cart_razorpay_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_fluent_cart_razorpay_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_rpfc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_nopriv_rpfc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_rpfc_ipn', [$this, 'handleIPN']);
        add_action('wp_ajax_nopriv_rpfc_ipn', [$this, 'handleIPN']);
        add_action('fluent_cart/handle_payment_ipn_razorpay', [$this, 'handleIPN']);
        add_action('fluent_cart/subscription_cancelled', [$this, 'cancelVendorSubscription'], 20, 1);
        add_action('fluent_cart/subscription_canceled', [$this, 'cancelVendorSubscription'], 20, 1);
    }

    public function meta(): array
    {
        return [
            'title'              => __('Razorpay', 'razorpay-for-fluentcart'),
            'route'              => 'razorpay',
            'slug'               => 'razorpay',
            'label'              => 'Razorpay',
            'description'        => __('Accept cards, UPI, netbanking, wallets, and subscriptions with Razorpay.', 'razorpay-for-fluentcart'),
            'logo'               => RPFC_URL . 'assets/icon.svg',
            'icon'               => RPFC_URL . 'assets/icon.svg',
            'brand_color'        => '#0C0D4D',
            'status'             => $this->settings->isActive(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    public function fields(): array
    {
        return $this->settings->getFields($this);
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_razorpay_data' => [
                'api_key'       => $this->settings->apiKey(),
                'checkout_type' => 'modal',
                'ajax_url'      => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('rpfc_confirm'),
                'translations'  => [
                    'Processing payment...'       => __('Processing payment...', 'razorpay-for-fluentcart'),
                    'Pay Now'                     => __('Pay Now', 'razorpay-for-fluentcart'),
                    'Place Order'                 => __('Place Order', 'razorpay-for-fluentcart'),
                    'Payment Modal is opening...' => __('Payment Modal is opening, Please complete the payment', 'razorpay-for-fluentcart'),
                    'Available payment methods'   => __('Available payment methods', 'razorpay-for-fluentcart'),
                    'Cards'                       => __('Cards', 'razorpay-for-fluentcart'),
                    'UPI'                         => __('UPI', 'razorpay-for-fluentcart'),
                    'Net Banking'                 => __('Net Banking', 'razorpay-for-fluentcart'),
                    'Wallets'                     => __('Wallets', 'razorpay-for-fluentcart'),
                ],
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-razorpay-checkout',
                'src'    => 'https://checkout.razorpay.com/v1/checkout.js',
            ],
            [
                'handle'  => 'fluent-cart-razorpay-custom',
                'src'     => RPFC_URL . 'assets/checkout.min.js',
                'deps'    => ['fluent-cart-razorpay-checkout'],
                'version' => RPFC_VERSION,
            ],
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getWebhookInstructions(): array
    {
        return $this->settings->getWebhookInstructions();
    }

    public function getOrderInfo($data = null)
    {
        if (!$this->isCurrencySupported()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Razorpay does not support the currency configured in your store.', 'razorpay-for-fluentcart'),
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'razorpay-for-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'api_key'       => $this->settings->apiKey(),
                'checkout_type' => 'modal',
            ],
        ], 200);
    }

    public function isCurrencySupported(): bool
    {
        $currency = 'INR';
        if (class_exists('\FluentCart\Api\CurrencySettings') && method_exists('\FluentCart\Api\CurrencySettings', 'get')) {
            try {
                $currency = strtoupper((string) \FluentCart\Api\CurrencySettings::get('currency'));
            } catch (\Throwable $e) {}
        }
        $supported = apply_filters('fluent_cart/razorpay_supported_currencies', [
            'INR', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'AED', 'ALL', 'AMD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
            'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BZD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP',
            'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'FJD', 'FKP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ',
            'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW',
            'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LTL', 'LVL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD',
            'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD',
            'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR',
            'SDG', 'SEK', 'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD',
            'TWD', 'TZS', 'UAH', 'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMK', 'ZMW', 'ZWL'
        ]);
        return in_array($currency, $supported, true);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if (!$this->settings->isConfigured()) {
            return [
                'status'  => 'failed',
                'message' => __('Razorpay is not fully configured. Please configure Key ID and Key Secret in settings.', 'razorpay-for-fluentcart'),
            ];
        }

        if ($paymentInstance->subscription) {
            return $this->createSubscriptionCheckout($paymentInstance);
        }

        return $this->createOneTimeCheckout($paymentInstance);
    }

    private function createOneTimeCheckout(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        if (!$order || !$transaction) {
            return [
                'status'  => 'failed',
                'message' => __('Unable to initialize the payment transaction.', 'razorpay-for-fluentcart'),
            ];
        }

        $currency = strtoupper(trim((string) $transaction->currency));
        if (strlen($currency) !== 3) {
            return [
                'status'  => 'failed',
                'message' => __('Invalid currency specified for this transaction.', 'razorpay-for-fluentcart'),
            ];
        }

        $amount = (int) round($transaction->total);
        if ($amount <= 0) {
            return [
                'status'  => 'failed',
                'message' => __('Razorpay cannot process a zero-value payment.', 'razorpay-for-fluentcart'),
            ];
        }

        $result = $this->api()->createOrder([
            'amount'          => $amount,
            'currency'        => $currency,
            'receipt'         => $this->receipt($transaction),
            'payment_capture' => 1,
            'notes'           => [
                'fluent_transaction_uuid' => (string) $transaction->uuid,
                'fluent_order_uuid'       => (string) $order->uuid,
                'fluent_order_id'         => (string) $order->id,
            ],
        ]);

        if (is_wp_error($result)) {
            return [
                'status'  => 'failed',
                'message' => $result->get_error_message(),
            ];
        }

        $orderId = (string) ($result['id'] ?? '');
        $meta = (array) $transaction->meta;
        $meta['razorpay_order_id'] = $orderId;
        $meta['razorpay_type'] = 'one_time';
        $transaction->payment_mode = $this->settings->mode();
        $transaction->meta = $meta;
        $transaction->save();

        if ($orderId) {
            set_transient('rpfc_tx_order_' . md5($orderId), (string) $transaction->uuid, 30 * DAY_IN_SECONDS);
        }

        return $this->checkoutResponse($transaction, [
            'razorpay_order_id' => $orderId,
            'amount'            => $amount,
            'currency'          => $currency,
        ], $order);
    }

    private function createSubscriptionCheckout(PaymentInstance $paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;

        if (!$order || !$transaction || !$subscription) {
            return [
                'status'  => 'failed',
                'message' => __('Unable to initialize the subscription payment.', 'razorpay-for-fluentcart'),
            ];
        }

        $currency = strtoupper(trim((string) $transaction->currency));
        if (strlen($currency) !== 3) {
            return [
                'status'  => 'failed',
                'message' => __('Invalid currency specified for this subscription.', 'razorpay-for-fluentcart'),
            ];
        }

        $recurringAmount = (int) round($subscription->recurring_total);
        if ($recurringAmount <= 0) {
            return [
                'status'  => 'failed',
                'message' => __('The subscription recurring amount must be greater than zero.', 'razorpay-for-fluentcart'),
            ];
        }

        $period = $this->mapSubscriptionPeriod($subscription->billing_interval);
        if (!$period) {
            return [
                'status'  => 'failed',
                'message' => __('This subscription billing interval is not supported by Razorpay. Supported intervals are weekly, monthly, quarterly and yearly.', 'razorpay-for-fluentcart'),
            ];
        }

        $plan = $this->getOrCreatePlan($period, $recurringAmount, $currency, $order, $subscription);
        if (is_wp_error($plan)) {
            return [
                'status'  => 'failed',
                'message' => $plan->get_error_message(),
            ];
        }

        $planId = (string) ($plan['id'] ?? '');
        if (!$planId) {
            return [
                'status'  => 'failed',
                'message' => __('Razorpay did not return a valid subscription plan ID.', 'razorpay-for-fluentcart'),
            ];
        }

        $totalCount = $this->subscriptionTotalCount($subscription, $period);
        if ($totalCount < 1) {
            return [
                'status'  => 'failed',
                'message' => __('Razorpay requires at least one subscription billing cycle.', 'razorpay-for-fluentcart'),
            ];
        }

        $trialStartAt = $this->getSubscriptionTrialStartAt($subscription, $order);
        $hasTrial = ($trialStartAt !== null && $trialStartAt > time() + 60);

        $subscriptionData = [
            'plan_id'         => $planId,
            'total_count'     => $totalCount,
            'quantity'        => 1,
            'customer_notify' => false,
            'notes'           => [
                'fluent_transaction_uuid' => (string) $transaction->uuid,
                'fluent_subscription_id'  => (string) $subscription->id,
                'fluent_order_uuid'       => (string) $order->uuid,
                'fluent_order_id'         => (string) $order->id,
            ],
        ];

        if ($hasTrial) {
            $subscriptionData['start_at'] = (int) $trialStartAt;
        }

        $initialAmount = (int) round($transaction->total);

        if ($hasTrial) {
            // Free trial: regular recurring billing is deferred to start_at.
            // If the merchant configured a setup fee upfront, charge it today as an addon.
            if ($initialAmount > 0) {
                $subscriptionData['addons'] = [[
                    'item' => [
                        'name'     => __('Initial / Setup Fee', 'razorpay-for-fluentcart'),
                        'amount'   => $initialAmount,
                        'currency' => $currency,
                    ],
                ]];
            }
        } else {
            // Immediate billing: first cycle is charged today.
            if ($initialAmount < $recurringAmount && $initialAmount > 0) {
                return [
                    'status'  => 'failed',
                    'message' => __('Razorpay subscriptions require the initial charge to cover at least the first recurring cycle unless configured with a free trial.', 'razorpay-for-fluentcart'),
                ];
            }
            if ($initialAmount > $recurringAmount) {
                $addonAmount = $initialAmount - $recurringAmount;
                $subscriptionData['addons'] = [[
                    'item' => [
                        'name'     => __('Initial / Setup Fee', 'razorpay-for-fluentcart'),
                        'amount'   => $addonAmount,
                        'currency' => $currency,
                    ],
                ]];
            }
        }

        $remoteSubscription = $this->api()->createSubscription($subscriptionData);

        // If Razorpay imposes a lower total_count limit, retry once with that exact count:
        if (is_wp_error($remoteSubscription)) {
            $maxCount = $this->maximumTotalCountFromError($remoteSubscription);
            if ($maxCount && $totalCount > $maxCount) {
                $subscriptionData['total_count'] = $maxCount;
                $remoteSubscription = $this->api()->createSubscription($subscriptionData);
            }
        }

        // If a previously cached plan was deleted in Razorpay, discard cache and create fresh plan once.
        if (is_wp_error($remoteSubscription) && $this->isMissingRazorpayPlanError($remoteSubscription)) {
            $this->deleteCachedPlan($period, $recurringAmount, $currency, $subscription);
            $plan = $this->getOrCreatePlan($period, $recurringAmount, $currency, $order, $subscription);
            if (!is_wp_error($plan) && !empty($plan['id'])) {
                $planId = (string) $plan['id'];
                $subscriptionData['plan_id'] = $planId;
                $remoteSubscription = $this->api()->createSubscription($subscriptionData);
            }
        }

        if (is_wp_error($remoteSubscription)) {
            return [
                'status'  => 'failed',
                'message' => $remoteSubscription->get_error_message(),
            ];
        }

        $subscriptionId = (string) ($remoteSubscription['id'] ?? '');
        if (!$subscriptionId) {
            return [
                'status'  => 'failed',
                'message' => __('Razorpay did not return a valid subscription ID.', 'razorpay-for-fluentcart'),
            ];
        }

        $subscription->vendor_subscription_id = $subscriptionId;
        $subscription->collection_method = 'system';
        $subscription->current_payment_method = 'razorpay';
        if ($hasTrial) {
            $subscription->status = 'trialing';
        }
        $subscription->save();

        $meta = (array) $transaction->meta;
        $meta['razorpay_subscription_id'] = $subscriptionId;
        $meta['razorpay_plan_id'] = $planId;
        $meta['razorpay_type'] = 'subscription';
        if ($hasTrial) {
            $meta['razorpay_has_trial'] = true;
            $meta['razorpay_trial_start_at'] = (int) $trialStartAt;
        }
        $transaction->payment_mode = $this->settings->mode();
        $transaction->meta = $meta;
        $transaction->save();

        set_transient('rpfc_tx_sub_' . md5($subscriptionId), (string) $transaction->uuid, 30 * DAY_IN_SECONDS);

        return $this->checkoutResponse($transaction, [
            'razorpay_subscription_id' => $subscriptionId,
        ], $order);
    }

    private function planCacheKey($period, $amount, $currency, $subscription = null)
    {
        $productId = $subscription ? (string) ($subscription->product_id ?? '') : '';
        return 'rpfc_plan_' . $this->settings->mode() . '_' . md5(wp_json_encode([
            $productId,
            $period['period'],
            $period['interval'],
            (int) $amount,
            strtoupper($currency),
        ]));
    }

    private function deleteCachedPlan($period, $amount, $currency, $subscription = null)
    {
        $key = $this->planCacheKey($period, $amount, $currency, $subscription);
        unset(self::$runtimePlanCache[$key]);
        delete_option($key);
    }

    private function maximumTotalCountFromError($error): ?int
    {
        if (!is_wp_error($error)) {
            return null;
        }
        $msg = (string) $error->get_error_message();
        return preg_match('/maximum\s+total[_ ]count[^\d]*(\d+)/i', $msg, $m) ? (int) $m[1] : null;
    }

    private function isMissingRazorpayPlanError($error): bool
    {
        if (!is_wp_error($error)) {
            return false;
        }
        $data = $error->get_error_data();
        $desc = strtolower((string) ($data['response']['error']['description'] ?? $error->get_error_message()));
        return strpos($desc, 'plan') !== false && (strpos($desc, 'exist') !== false || strpos($desc, 'not found') !== false);
    }

    private function getOrCreatePlan($period, $amount, $currency, $order, $subscription)
    {
        $key = $this->planCacheKey($period, $amount, $currency, $subscription);
        if (isset(self::$runtimePlanCache[$key])) {
            return ['id' => self::$runtimePlanCache[$key]];
        }

        $cached = get_option($key, '');
        if ($cached) {
            self::$runtimePlanCache[$key] = (string) $cached;
            return ['id' => (string) $cached];
        }

        $result = $this->api()->createPlan([
            'period'   => $period['period'],
            'interval' => $period['interval'],
            'item'     => [
                'name'        => substr($this->orderDescription($order), 0, 80),
                'amount'      => (int) $amount,
                'currency'    => strtoupper($currency),
                'description' => substr($this->orderDescription($order), 0, 255),
            ],
            'notes' => [
                'fluent_subscription_id' => (string) $subscription->id,
                'fluent_order_uuid'      => (string) $order->uuid,
            ],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $planId = (string) ($result['id'] ?? '');
        if (!$planId) {
            return new \WP_Error('rpfc_plan', __('Razorpay did not return a valid subscription plan ID.', 'razorpay-for-fluentcart'));
        }

        update_option($key, $planId, false);
        self::$runtimePlanCache[$key] = $planId;
        return $result;
    }

    private function mapSubscriptionPeriod($interval): ?array
    {
        $map = [
            'weekly'        => ['period' => 'weekly', 'interval' => 1],
            'monthly'       => ['period' => 'monthly', 'interval' => 1],
            'bi-monthly'    => ['period' => 'monthly', 'interval' => 2],
            'bi_monthly'    => ['period' => 'monthly', 'interval' => 2],
            'quarterly'     => ['period' => 'quarterly', 'interval' => 1],
            'half_yearly'   => ['period' => 'monthly', 'interval' => 6],
            'half-yearly'   => ['period' => 'monthly', 'interval' => 6],
            'semi-annually' => ['period' => 'monthly', 'interval' => 6],
            'yearly'        => ['period' => 'yearly', 'interval' => 1],
            'annual'        => ['period' => 'yearly', 'interval' => 1],
        ];
        return $map[strtolower(trim((string) $interval))] ?? null;
    }

    private function subscriptionTotalCount($subscription, $period)
    {
        // 100-year duration caps defined by Razorpay API:
        $maxCounts = [
            'weekly'    => 5200,
            'monthly'   => max(1, (int) floor(1200 / max(1, (int) ($period['interval'] ?? 1)))),
            'quarterly' => 400,
            'yearly'    => 100,
        ];
        $maxAllowed = $maxCounts[$period['period']] ?? 100;

        $candidates = [
            $subscription->bill_times ?? null,
            $subscription->total_cycles ?? null,
            $subscription->billing_cycles ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return min($maxAllowed, (int) $candidate);
            }
        }

        $expireAt = $subscription->expire_at ?? null;
        $next = $subscription->next_billing_date ?? null;
        if ($expireAt && $next) {
            $start = strtotime((string) $next);
            $end = strtotime((string) $expireAt);
            if ($start && $end && $end > $start) {
                $diffDays = max(1, (int) floor(($end - $start) / DAY_IN_SECONDS));
                $divisor = ['weekly' => 7, 'monthly' => 30 * max(1, (int) ($period['interval'] ?? 1)), 'quarterly' => 90, 'yearly' => 365][$period['period']] ?? 30;
                return min($maxAllowed, max(1, (int) ceil($diffDays / $divisor)) + 1);
            }
        }

        return $maxAllowed;
    }

    public function confirmPayment()
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? ($_POST['_nonce'] ?? '')));
        if (!wp_verify_nonce($nonce, 'rpfc_confirm') && !wp_verify_nonce($nonce, 'fluent_cart_razorpay_nonce')) {
            wp_send_json_error(['message' => __('Security verification failed. Please refresh and try again.', 'razorpay-for-fluentcart')], 403);
        }

        $transactionId  = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ($_POST['transaction_hash'] ?? '')));
        $paymentId      = sanitize_text_field(wp_unslash($_POST['razorpay_payment_id'] ?? ''));
        $orderId        = sanitize_text_field(wp_unslash($_POST['razorpay_order_id'] ?? ''));
        $signature      = sanitize_text_field(wp_unslash($_POST['razorpay_signature'] ?? ''));
        $subscriptionId = sanitize_text_field(wp_unslash($_POST['razorpay_subscription_id'] ?? ''));

        if (!$transactionId || !$paymentId) {
            wp_send_json_error(['message' => __('Required payment data is missing.', 'razorpay-for-fluentcart')], 400);
        }

        $transaction = OrderTransaction::query()->where('uuid', $transactionId)->first();
        if (!$transaction && class_exists(OrderTransaction::class)) {
            $transaction = OrderTransaction::query()->where('transaction_hash', $transactionId)->first();
        }
        if (!$transaction) {
            wp_send_json_error(['message' => __('FluentCart transaction not found.', 'razorpay-for-fluentcart')], 404);
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === 'succeeded') {
            wp_send_json_success(['redirect_url' => $transaction->getReceiptPageUrl()]);
        }

        $meta = (array) $transaction->meta;
        $expectedOrderId = (string) ($meta['razorpay_order_id'] ?? '');
        $expectedSubscriptionId = (string) ($meta['razorpay_subscription_id'] ?? '');

        if ($expectedOrderId && $orderId && $expectedOrderId !== $orderId) {
            wp_send_json_error(['message' => __('Razorpay Order ID does not match this transaction.', 'razorpay-for-fluentcart')], 400);
        }
        if ($expectedSubscriptionId && $subscriptionId && $expectedSubscriptionId !== $subscriptionId) {
            wp_send_json_error(['message' => __('Razorpay Subscription ID does not match this transaction.', 'razorpay-for-fluentcart')], 400);
        }

        $payment = $this->verifyCheckoutSignature(
            $paymentId,
            $signature,
            $orderId ?: $expectedOrderId,
            $subscriptionId ?: $expectedSubscriptionId
        );
        if (is_wp_error($payment)) {
            wp_send_json_error(['message' => $payment->get_error_message()], 400);
        }

        if ($expectedOrderId) {
            $remoteOrderId = (string) ($payment['order_id'] ?? '');
            if ($remoteOrderId && $remoteOrderId !== $expectedOrderId) {
                wp_send_json_error(['message' => __('Razorpay payment is linked to a different order.', 'razorpay-for-fluentcart')], 400);
            }
        }

        $isSubscription = (($meta['razorpay_type'] ?? '') === 'subscription') || !empty($subscriptionId) || !empty($expectedSubscriptionId);
        $status = strtolower((string) ($payment['status'] ?? ''));

        // Validate currency (strictly for one-time orders; subscriptions may use nominal INR token auth):
        $remoteCurrency = strtoupper(trim((string) ($payment['currency'] ?? '')));
        $expectedCurrency = strtoupper(trim((string) $transaction->currency));
        if (!$isSubscription && $remoteCurrency && $remoteCurrency !== $expectedCurrency) {
            wp_send_json_error(['message' => __('Payment currency mismatch.', 'razorpay-for-fluentcart')], 400);
        }

        // Validate amount (only for one-time payments; subscriptions use plan amount + addons or token validation):
        $expectedAmount = (int) round($transaction->total);
        $remoteAmount = (int) ($payment['amount'] ?? 0);
        if (!$isSubscription && $remoteAmount > 0 && $remoteAmount !== $expectedAmount) {
            wp_send_json_error(['message' => __('Razorpay payment amount does not match the transaction total.', 'razorpay-for-fluentcart')], 400);
        }

        if (!$isSubscription) {
            if ($status === 'authorized') {
                $captured = $this->api()->capturePayment($paymentId, $remoteAmount ?: $expectedAmount, (string) $transaction->currency);
                if (is_wp_error($captured)) {
                    wp_send_json_error(['message' => $captured->get_error_message()], 400);
                }
                $status = 'captured';
            }

            if (!in_array($status, ['captured', 'processed'], true)) {
                wp_send_json_error(['message' => __('Payment has not been captured yet by Razorpay.', 'razorpay-for-fluentcart')], 400);
            }
        } else {
            // For subscriptions:
            // 1. If Razorpay explicitly marks the payment as failed:
            if ($status === 'failed') {
                $errorDesc = $payment['error_description'] ?? __('Subscription authorization payment failed.', 'razorpay-for-fluentcart');
                wp_send_json_error(['message' => $errorDesc], 400);
            }

            // 2. Check if subscription entity is already cancelled or expired on Razorpay:
            $subId = $subscriptionId ?: $expectedSubscriptionId;
            if ($subId) {
                $subObj = $this->api()->getSubscription($subId);
                if (!is_wp_error($subObj)) {
                    $subStatus = strtolower((string) ($subObj['status'] ?? ''));
                    if (in_array($subStatus, ['cancelled', 'expired'], true)) {
                        wp_send_json_error(['message' => __('Subscription was cancelled or expired.', 'razorpay-for-fluentcart')], 400);
                    }
                }
            }
        }

        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->vendor_charge_id = $paymentId;
        $meta['razorpay_payment_id'] = $paymentId;
        $transaction->meta = $meta;
        $transaction->save();

        $this->syncOrder($transaction);

        if ($isSubscription && ($subscriptionId ?: $expectedSubscriptionId)) {
            $subId = $subscriptionId ?: $expectedSubscriptionId;
            $subscription = Subscription::query()->where('vendor_subscription_id', $subId)->first();
            if (!$subscription && $transaction->order_id) {
                $subscription = Subscription::query()->where('order_id', $transaction->order_id)->first();
            }
            if ($subscription) {
                $isTrial = !empty($meta['razorpay_has_trial']) || $this->isSubscriptionInTrial($subscription);
                $subscription->status = $isTrial ? 'trialing' : 'active';
                $subscription->collection_method = 'system';
                $subscription->current_payment_method = 'razorpay';
                if (!$subscription->vendor_subscription_id) {
                    $subscription->vendor_subscription_id = $subId;
                }
                $subscription->save();
            }
        }

        wp_send_json_success(['redirect_url' => $transaction->getReceiptPageUrl()]);
    }

    private function verifyCheckoutSignature($paymentId, $signature, $orderId, $subscriptionId)
    {
        if (!$paymentId || !$signature) {
            return new \WP_Error('rpfc_signature', __('Razorpay did not return a valid payment signature.', 'razorpay-for-fluentcart'));
        }

        if (!$orderId && !$subscriptionId) {
            return new \WP_Error('rpfc_reference', __('Razorpay order or subscription reference is missing.', 'razorpay-for-fluentcart'));
        }

        $secret = $this->settings->apiSecret();
        $isValid = false;

        if ($subscriptionId) {
            // Razorpay official subscription checkout signature format: payment_id|subscription_id
            $expectedSub = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $secret);
            $isValid = hash_equals($expectedSub, $signature);

            // Backward-compatible fallback check:
            if (!$isValid) {
                $expectedFallback = hash_hmac('sha256', $subscriptionId . '|' . $paymentId, $secret);
                $isValid = hash_equals($expectedFallback, $signature);
            }
        } else {
            // Razorpay official order checkout signature format: order_id|payment_id
            $expectedOrder = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
            $isValid = hash_equals($expectedOrder, $signature);
        }

        if (!$isValid) {
            return new \WP_Error('rpfc_signature', __('Invalid Razorpay payment signature.', 'razorpay-for-fluentcart'));
        }

        // Retrieve authoritative payment record directly from Razorpay API:
        return $this->api()->getPayment($paymentId);
    }

    public function paymentStatus()
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'rpfc_confirm')) {
            wp_send_json_error(['message' => __('Security verification failed.', 'razorpay-for-fluentcart')], 403);
        }

        $transactionId = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
        $transaction = OrderTransaction::query()->where('uuid', $transactionId)->first();

        if (!$transaction) {
            wp_send_json_error(['message' => __('Transaction not found.', 'razorpay-for-fluentcart')], 404);
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === 'succeeded') {
            wp_send_json_success([
                'transaction_status' => 'succeeded',
                'redirect_url'       => $transaction->getReceiptPageUrl(),
            ]);
        }

        // If the transaction is pending, verify status directly against Razorpay API as a resilience fallback:
        $meta = (array) $transaction->meta;
        $orderId = (string) ($meta['razorpay_order_id'] ?? '');
        if ($orderId) {
            $remoteOrder = $this->api()->getOrder($orderId);
            if (!is_wp_error($remoteOrder) && strtolower((string) ($remoteOrder['status'] ?? '')) === 'paid') {
                $payments = $this->api()->getOrderPayments($orderId);
                $items = is_array($payments) && isset($payments['items']) ? $payments['items'] : [];
                foreach ($items as $item) {
                    if (in_array(strtolower((string) ($item['status'] ?? '')), ['captured', 'processed'], true)) {
                        $paymentId = (string) ($item['id'] ?? '');
                        $transaction->status = Status::TRANSACTION_SUCCEEDED;
                        $transaction->vendor_charge_id = $paymentId;
                        $meta['razorpay_payment_id'] = $paymentId;
                        $transaction->meta = $meta;
                        $transaction->save();

                        $this->syncOrder($transaction);

                        wp_send_json_success([
                            'transaction_status' => 'succeeded',
                            'redirect_url'       => $transaction->getReceiptPageUrl(),
                        ]);
                    }
                }
            }
        }

        wp_send_json_success(['transaction_status' => (string) $transaction->status]);
    }

    public function processRefund($transaction, $amount, $args = [])
    {
        if (!$transaction) {
            return new \WP_Error('rpfc_refund', __('FluentCart transaction was not found.', 'razorpay-for-fluentcart'));
        }

        $meta = (array) $transaction->meta;
        $paymentId = (string) ($transaction->vendor_charge_id ?: ($meta['razorpay_payment_id'] ?? ($transaction->transaction_id ?? '')));
        if (!$paymentId) {
            return new \WP_Error('rpfc_refund', __('Razorpay payment ID was not found on this transaction.', 'razorpay-for-fluentcart'));
        }

        $requestedAmount = (int) round($amount);
        if ($requestedAmount <= 0) {
            return new \WP_Error('rpfc_refund', __('Refund amount must be greater than zero.', 'razorpay-for-fluentcart'));
        }

        $payment = $this->api()->getPayment($paymentId);
        if (is_wp_error($payment)) {
            return $payment;
        }

        $paymentStatus = strtolower((string) ($payment['status'] ?? ''));
        if (!in_array($paymentStatus, ['captured', 'processed'], true)) {
            return new \WP_Error('rpfc_refund', __('The Razorpay payment is not in a refundable state.', 'razorpay-for-fluentcart'));
        }

        $captured = (int) ($payment['amount'] ?? 0);
        $alreadyRefunded = (int) ($payment['amount_refunded'] ?? 0);
        $refundableRemaining = max(0, $captured - $alreadyRefunded);

        if ($requestedAmount > $refundableRemaining) {
            return new \WP_Error(
                'rpfc_refund',
                sprintf(
                    __('The requested refund exceeds the remaining refundable amount (%s).', 'razorpay-for-fluentcart'),
                    $refundableRemaining
                )
            );
        }

        $receipt = 'FC-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($transaction->uuid ?: uniqid())), 0, 36);
        $reason = sanitize_text_field((string) ($args['reason'] ?? 'FluentCart refund'));
        $notes = ['fluent_transaction_uuid' => (string) $transaction->uuid];
        if ($reason) {
            $notes['reason'] = substr($reason, 0, 100);
        }

        $idempotency = 'fc_' . substr(hash('sha256', (string) $transaction->uuid . '|' . $paymentId . '|' . $requestedAmount), 0, 48);
        $result = $this->api()->createRefund($paymentId, $requestedAmount, $receipt, $notes, $idempotency);
        if (is_wp_error($result)) {
            return $result;
        }

        $refundId = (string) ($result['id'] ?? '');
        if (!$refundId) {
            return new \WP_Error('rpfc_refund', __('Razorpay did not return a refund ID.', 'razorpay-for-fluentcart'));
        }

        return [
            'success'          => true,
            'message'          => __('Refund processed successfully.', 'razorpay-for-fluentcart'),
            'transaction_id'   => $refundId,
            'vendor_charge_id' => $refundId,
            'refund_id'        => $refundId,
            'data'             => $result,
        ];
    }

    public function refund($transaction, $amount, $args = [])
    {
        return $this->processRefund($transaction, $amount, $args);
    }

    public function chargeRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        $subscription = $paymentInstance->subscription;
        $transaction = $paymentInstance->transaction;

        if (!$subscription || !$transaction) {
            return new \WP_Error('rpfc_subscription', __('Subscription transaction is unavailable.', 'razorpay-for-fluentcart'));
        }

        $vendorSubscriptionId = (string) $subscription->vendor_subscription_id;
        if (!$vendorSubscriptionId) {
            return new \WP_Error('rpfc_subscription', __('Razorpay Subscription ID is missing.', 'razorpay-for-fluentcart'));
        }

        $meta = (array) $transaction->meta;
        $meta['razorpay_subscription_id'] = $vendorSubscriptionId;
        $meta['razorpay_type'] = 'renewal';
        $transaction->meta = $meta;
        $transaction->save();

        return 'processing';
    }

    public function reconcileRenewalCharge(PaymentInstance $paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        if (!$subscription) {
            return new \WP_Error('rpfc_subscription', __('Subscription is unavailable.', 'razorpay-for-fluentcart'));
        }

        $subscriptionId = (string) $subscription->vendor_subscription_id;
        if (!$subscriptionId) {
            return new \WP_Error('rpfc_subscription', __('Razorpay Subscription ID is missing.', 'razorpay-for-fluentcart'));
        }

        $remote = $this->api()->getSubscription($subscriptionId);
        if (is_wp_error($remote)) {
            return $remote;
        }

        $status = strtolower((string) ($remote['status'] ?? ''));
        if (in_array($status, ['cancelled', 'expired', 'halted'], true)) {
            return new \WP_Error('rpfc_subscription_status', sprintf(__('Razorpay subscription is %s.', 'razorpay-for-fluentcart'), $status));
        }

        return 'processing';
    }

    /**
     * Cancel subscription on Razorpay.
     * Supports calls from:
     * 1. Customer Profile Dashboard ($gateway->cancelSubscription / cancelRemoteSubscription)
     * 2. Merchant Admin Dashboard (via fluent_cart/subscription_cancelled action hook)
     * 3. FluentCart REST API / services
     *
     * @param Subscription|object|array|int $subscription
     * @param array $args
     * @return bool|\WP_Error
     */
    public function cancelSubscription($subscription, $args = [])
    {
        if (is_numeric($subscription)) {
            $subscription = Subscription::find($subscription);
        } elseif (is_array($subscription)) {
            $subscription = $subscription['subscription'] ?? null;
        }

        if (!$subscription || !is_object($subscription)) {
            return false;
        }

        // Only handle Razorpay subscriptions:
        $method = (string) ($subscription->current_payment_method ?? ($subscription->payment_method ?? ''));
        if ($method && $method !== 'razorpay') {
            return false;
        }

        $vendorId = (string) ($subscription->vendor_subscription_id ?? '');
        if (!$vendorId) {
            return true;
        }

        // Avoid infinite recursion from webhooks or duplicate calls:
        if (isset(self::$cancellingVendorIds[$vendorId])) {
            return true;
        }
        self::$cancellingVendorIds[$vendorId] = true;

        $cancelAtCycleEnd = !empty($args['cancel_at_cycle_end']);
        $result = $this->api()->cancelSubscription($vendorId, $cancelAtCycleEnd);

        if (is_wp_error($result)) {
            $message = strtolower($result->get_error_message());
            // Idempotent: If already cancelled or not found on Razorpay, treat as success:
            if (
                strpos($message, 'already cancelled') !== false ||
                strpos($message, 'already canceled') !== false ||
                strpos($message, 'does not exist') !== false ||
                strpos($message, 'not found') !== false
            ) {
                return true;
            }
            return $result;
        }

        return true;
    }

    public function cancelRemoteSubscription($subscription, $args = [])
    {
        return $this->cancelSubscription($subscription, $args);
    }

    public function cancel($subscription, $args = [])
    {
        return $this->cancelSubscription($subscription, $args);
    }

    public function cancelVendorSubscription($data)
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : $data;
        if ($subscription) {
            $this->cancelSubscription($subscription);
        }
    }

    public function handleIPN()
    {
        $raw = file_get_contents('php://input');
        $signature = '';
        if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
            $signature = (string) $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
        }

        // Verification: If secret configured, verify HMAC signature. If optional (empty), use zero-trust API validation.
        $signatureVerified = $this->verifyWebhookSignature($raw, $signature);
        if ($this->settings->hasWebhookSecret() && !$signatureVerified) {
            status_header(401);
            echo 'Invalid signature';
            exit;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            status_header(400);
            echo 'Invalid payload';
            exit;
        }

        $event = strtolower((string) ($data['event'] ?? ''));
        $handled = $this->handleWebhookEvent($event, $data, $signatureVerified);

        if (is_wp_error($handled)) {
            status_header(400);
            echo esc_html($handled->get_error_message());
            exit;
        }

        status_header(200);
        echo 'ok';
        exit;
    }

    private function verifyWebhookSignature($raw, $signature)
    {
        $secret = $this->settings->webhookSecret();
        if (!$secret) {
            // Webhook secret is optional. Zero-trust fallback verification will be applied during event handling.
            return false;
        }

        if (!$raw || !$signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, trim($signature));
    }

    private function handleWebhookEvent($event, array $data, bool $signatureVerified)
    {
        $payload = $data['payload'] ?? [];
        $payment = $payload['payment']['entity'] ?? [];
        $order = $payload['order']['entity'] ?? [];
        $subscription = $payload['subscription']['entity'] ?? [];

        switch ($event) {
            case 'order.paid':
            case 'payment.captured':
                return $this->handleCapturedPayment($payment, $order, $signatureVerified);

            case 'payment.failed':
                return $this->handleFailedPayment($payment, $order, $signatureVerified);

            case 'subscription.authenticated':
            case 'subscription.charged':
                return $this->handleSubscriptionCharge($subscription, $payment, $event === 'subscription.charged', $signatureVerified);

            case 'subscription.completed':
                return $this->updateSubscriptionStatus($subscription, 'completed', $signatureVerified);

            case 'subscription.halted':
                return $this->updateSubscriptionStatus($subscription, 'on-hold', $signatureVerified);

            case 'subscription.cancelled':
                return $this->updateSubscriptionStatus($subscription, 'canceled', $signatureVerified);

            case 'subscription.paused':
                return $this->updateSubscriptionStatus($subscription, 'on-hold', $signatureVerified);

            case 'subscription.resumed':
                return $this->updateSubscriptionStatus($subscription, 'active', $signatureVerified);

            default:
                return true;
        }
    }

    private function handleCapturedPayment(array $payment, array $order, bool $signatureVerified)
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $razorpayOrderId = (string) ($payment['order_id'] ?? ($order['id'] ?? ''));
        $notes = array_merge($order['notes'] ?? [], $payment['notes'] ?? []);

        $transaction = $this->findTransactionByRazorpayReference($razorpayOrderId, $paymentId, $notes);
        if (!$transaction) {
            return true;
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === 'succeeded') {
            return true;
        }

        // Zero-Trust verification: If webhook secret wasn't configured, verify directly with Razorpay API.
        if (!$signatureVerified) {
            if (!$paymentId) {
                return new \WP_Error('rpfc_webhook_unverified', __('Payment ID is missing for verification.', 'razorpay-for-fluentcart'));
            }
            $apiPayment = $this->api()->getPayment($paymentId);
            if (is_wp_error($apiPayment)) {
                return $apiPayment;
            }
            $remoteStatus = strtolower((string) ($apiPayment['status'] ?? ''));
            if (!in_array($remoteStatus, ['captured', 'processed'], true)) {
                return new \WP_Error('rpfc_webhook_unverified', __('Razorpay API indicates payment is not captured.', 'razorpay-for-fluentcart'));
            }
            $payment = $apiPayment;
        }

        $expectedAmount = (int) round($transaction->total);
        $actualAmount = (int) ($payment['amount'] ?? 0);
        $meta = (array) $transaction->meta;
        if (($meta['razorpay_type'] ?? '') === 'one_time' && $actualAmount > 0 && $actualAmount !== $expectedAmount) {
            return new \WP_Error('rpfc_amount_mismatch', __('Razorpay webhook amount does not match the FluentCart transaction.', 'razorpay-for-fluentcart'));
        }

        $actualCurrency = strtoupper(trim((string) ($payment['currency'] ?? '')));
        $expectedCurrency = strtoupper(trim((string) $transaction->currency));
        if ($actualCurrency && $expectedCurrency && $actualCurrency !== $expectedCurrency) {
            return new \WP_Error('rpfc_currency_mismatch', __('Razorpay webhook currency does not match the FluentCart transaction.', 'razorpay-for-fluentcart'));
        }

        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->vendor_charge_id = $paymentId;
        $meta['razorpay_payment_id'] = $paymentId;
        $transaction->meta = $meta;
        $transaction->save();

        $this->syncOrder($transaction);

        return true;
    }

    private function handleFailedPayment(array $payment, array $order, bool $signatureVerified)
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $razorpayOrderId = (string) ($payment['order_id'] ?? ($order['id'] ?? ''));
        $notes = array_merge($order['notes'] ?? [], $payment['notes'] ?? []);

        $transaction = $this->findTransactionByRazorpayReference($razorpayOrderId, $paymentId, $notes);
        if (!$transaction || $transaction->status === Status::TRANSACTION_SUCCEEDED || $transaction->status === 'succeeded') {
            return true;
        }

        $transaction->status = 'failed';
        $meta = (array) $transaction->meta;
        if ($paymentId) {
            $meta['razorpay_payment_id'] = $paymentId;
        }
        $transaction->meta = $meta;
        $transaction->save();

        $this->syncOrder($transaction);

        return true;
    }

    private function handleSubscriptionCharge(array $subscription, array $payment, bool $isChargedEvent, bool $signatureVerified)
    {
        $subscriptionId = (string) ($subscription['id'] ?? '');
        $paymentId = (string) ($payment['id'] ?? '');
        if (!$subscriptionId) {
            return true;
        }

        $fluentSubscription = Subscription::query()->where('vendor_subscription_id', $subscriptionId)->first();
        if (!$fluentSubscription) {
            return true;
        }

        // Zero-Trust verification: If webhook secret wasn't configured, verify subscription via Razorpay API.
        if (!$signatureVerified) {
            $apiSub = $this->api()->getSubscription($subscriptionId);
            if (is_wp_error($apiSub)) {
                return $apiSub;
            }
            $subscription = $apiSub;
        }

        $isInTrial = $this->isSubscriptionInTrial($fluentSubscription);

        if ($isChargedEvent) {
            // A charged event means an actual monetary billing cycle was successfully paid
            $fluentSubscription->status = 'active';
        } else {
            // An authenticated event (initial signup authorization)
            $fluentSubscription->status = $isInTrial ? 'trialing' : 'active';
        }
        $fluentSubscription->collection_method = 'system';
        $fluentSubscription->current_payment_method = 'razorpay';
        $fluentSubscription->save();

        $paidCount = (int) ($subscription['paid_count'] ?? 1);
        $transactionUuid = (string) ($subscription['notes']['fluent_transaction_uuid'] ?? '');

        // Cycle 1 is the initial checkout payment.
        if ($paidCount <= 1 && $transactionUuid) {
            $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
            if ($transaction && $paymentId && $transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                $transaction->status = Status::TRANSACTION_SUCCEEDED;
                $transaction->vendor_charge_id = $paymentId;
                $meta = (array) $transaction->meta;
                $meta['razorpay_payment_id'] = $paymentId;
                $transaction->meta = $meta;
                $transaction->save();

                $this->syncOrder($transaction);
            }

            // If initial transaction had 0 total (free trial) and was already completed, record first paid cycle:
            if ($isChargedEvent && $transaction && $transaction->status === Status::TRANSACTION_SUCCEEDED && (int) round($transaction->total) <= 0) {
                $this->recordRenewal($fluentSubscription, $paymentId, $payment['amount'] ?? 0);
            }
            return true;
        }

        // Cycle 2+ is a recurring renewal charge:
        if ($isChargedEvent && $paidCount > 1 && $paymentId) {
            $this->recordRenewal($fluentSubscription, $paymentId, $payment['amount'] ?? 0);
        }

        return true;
    }

    private function updateSubscriptionStatus(array $remoteSubscription, string $status, bool $signatureVerified)
    {
        $id = (string) ($remoteSubscription['id'] ?? '');
        if (!$id) {
            return true;
        }

        $subscription = Subscription::query()->where('vendor_subscription_id', $id)->first();
        if (!$subscription) {
            return true;
        }

        if (!$signatureVerified) {
            $apiSub = $this->api()->getSubscription($id);
            if (is_wp_error($apiSub)) {
                return $apiSub;
            }
        }

        $map = [
            'active'    => 'active',
            'canceled'  => 'canceled',
            'cancelled' => 'canceled',
            'completed' => 'completed',
            'on-hold'   => 'on-hold',
            'paused'    => 'paused',
            'halted'    => 'on-hold',
        ];

        $oldStatus = (string) $subscription->status;
        $newStatus = $map[$status] ?? $status;

        if ($oldStatus === $newStatus) {
            return true;
        }

        $subscription->status = $newStatus;
        $subscription->save();

        // Retrieve linked order:
        $order = null;
        $orderId = $subscription->order_id ?? ($subscription->initial_order_id ?? null);
        if ($orderId) {
            $order = Order::find($orderId);
        }

        // If subscription is canceled or halted, mark any pending renewal transactions as failed:
        if (in_array($newStatus, ['canceled', 'on-hold'], true)) {
            $pendingRenewals = OrderTransaction::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', ['pending', 'processing'])
                ->get();

            foreach ($pendingRenewals as $tx) {
                $tx->status = 'failed';
                $tx->save();
            }
        }

        // Dispatch FluentCart core subscription lifecycle action hooks:
        if ($newStatus === 'canceled') {
            if ($id) {
                self::$cancellingVendorIds[$id] = true;
            }
            do_action('fluent_cart/subscription_cancelled', [
                'subscription' => $subscription,
                'order'        => $order,
            ]);
            do_action('fluent_cart/subscription_canceled', [
                'subscription' => $subscription,
                'order'        => $order,
            ]);
        } elseif ($newStatus === 'paused') {
            do_action('fluent_cart/subscription_paused', [
                'subscription' => $subscription,
                'order'        => $order,
            ]);
        } elseif ($newStatus === 'active') {
            do_action('fluent_cart/subscription_resumed', [
                'subscription' => $subscription,
                'order'        => $order,
            ]);
            do_action('fluent_cart/subscription_activated', [
                'subscription' => $subscription,
                'order'        => $order,
            ]);
        }

        do_action('fluent_cart/subscription_status_changed', $subscription, $oldStatus, $newStatus);

        return true;
    }

    private function syncOrder($transaction)
    {
        if ($transaction && !empty($transaction->order_id) && ($order = Order::find($transaction->order_id))) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
    }

    private function recordRenewal($subscription, $paymentId, $amount)
    {
        if ($paymentId && !OrderTransaction::query()->where('vendor_charge_id', $paymentId)->exists()) {
            try {
                SubscriptionRenewal::recordRenewalPayment($subscription, [
                    'amount'         => (int) $amount,
                    'transaction_id' => $paymentId,
                    'payment_method' => 'razorpay',
                    'status'         => 'completed',
                ]);
            } catch (\Throwable $e) {}
        }
    }

    private function findTransactionByRazorpayReference($orderId, $paymentId = '', array $notes = [])
    {
        if ($txUuid = (string) ($notes['fluent_transaction_uuid'] ?? '')) {
            if ($transaction = OrderTransaction::query()->where('uuid', $txUuid)->first()) {
                return $transaction;
            }
        }

        if ($orderId && ($uuid = get_transient('rpfc_tx_order_' . md5((string) $orderId)))) {
            if ($transaction = OrderTransaction::query()->where('uuid', $uuid)->first()) {
                return $transaction;
            }
        }

        return $paymentId ? OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first() : null;
    }

    public function getWebhookUrl()
    {
        return $this->settings->getWebhookUrl();
    }

    private function receipt($transaction)
    {
        return 'FC-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $transaction->uuid), 0, 36);
    }

    private function orderDescription($order)
    {
        $items = [];
        try {
            $order->loadMissing('order_items');
            foreach ($order->order_items ?? [] as $item) {
                if ($name = trim((string) ($item->title ?? ($item->item_name ?? '')))) {
                    $items[] = $name;
                }
                if (count($items) >= 3) {
                    break;
                }
            }
        } catch (\Throwable $e) {}

        return $items
            ? substr(implode(', ', $items), 0, 255)
            : sprintf(__('FluentCart Order #%s', 'razorpay-for-fluentcart'), (string) $order->id);
    }

    /**
     * Determine future Unix timestamp when subscription should begin charging (trial or deferred start).
     *
     * @param object $subscription FluentCart Subscription model
     * @param object|null $order FluentCart Order model
     * @return int|null Future Unix timestamp for Razorpay start_at, or null if starts immediately
     */
    private function getSubscriptionTrialStartAt($subscription, $order = null): ?int
    {
        if (!$subscription || (method_exists($subscription, 'hasTrial') && !$subscription->hasTrial())) {
            return null;
        }

        $now = time();
        $toTs = static function ($val) use ($now): ?int {
            if (empty($val)) {
                return null;
            }
            $ts = is_numeric($val) ? (int) $val : strtotime((string) $val);
            return ($ts && $ts > $now + 60) ? $ts : null;
        };

        // 1. Direct timestamps on subscription / methods / properties:
        $trialDate = (method_exists($subscription, 'getTrialEndsAt') ? $subscription->getTrialEndsAt() : null)
            ?: ($subscription->trial_ends_at ?? $subscription->trial_end_date ?? $subscription->trial_expire_at ?? $subscription->trial_end ?? null);

        if ($ts = $toTs($trialDate)) {
            return $ts;
        }

        // 2. Direct trial days:
        $days = (method_exists($subscription, 'getTrialDays') ? (int) $subscription->getTrialDays() : 0)
            ?: (int) ($subscription->trial_days ?? 0);

        // 3. Subscription meta:
        $meta = is_array($subscription->meta ?? null) ? $subscription->meta : [];
        if (!$days && ($ts = $toTs($meta['trial_ends_at'] ?? null))) {
            return $ts;
        }
        $days = $days ?: (int) ($meta['trial_days'] ?? 0);

        // 4. Order items fallback:
        if (!$days && $order) {
            try {
                $order->loadMissing('order_items');
                foreach ($order->order_items ?? [] as $item) {
                    if ($itemDays = (int) ($item->trial_days ?? 0)) {
                        $days = $itemDays;
                        break;
                    }
                    $info = is_array($item->other_info ?? null) ? $item->other_info : json_decode((string) ($item->other_info ?? ''), true);
                    if (!empty($info['trial_days'])) {
                        $days = (int) $info['trial_days'];
                        break;
                    }
                    if ($ts = $toTs($info['trial_ends_at'] ?? null)) {
                        return $ts;
                    }
                    $settings = is_array($item->settings ?? null) ? $item->settings : json_decode((string) ($item->settings ?? ''), true);
                    if (!empty($settings['trial_days'])) {
                        $days = (int) $settings['trial_days'];
                        break;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 5. Subscription in trialing status with billing start date:
        if (!$days && ($subscription->status ?? '') === 'trialing') {
            $billingDate = $subscription->next_billing_date ?? $subscription->billing_start_date ?? $subscription->bill_start_date ?? null;
            if ($ts = $toTs($billingDate)) {
                return $ts;
            }
        }

        return $days > 0 ? ($now + ($days * DAY_IN_SECONDS)) : null;
    }

    /**
     * Check if a subscription is currently in a trial period.
     *
     * @param object|null $subscription
     * @return bool
     */
    private function isSubscriptionInTrial($subscription): bool
    {
        if (!$subscription) {
            return false;
        }
        if (($subscription->status ?? '') === 'trialing' || !empty($subscription->trial_days)) {
            return true;
        }
        $endsAt = $subscription->trial_ends_at ?? null;
        if ($endsAt) {
            $ts = is_numeric($endsAt) ? (int) $endsAt : strtotime((string) $endsAt);
            return (bool) ($ts && $ts > time());
        }
        return false;
    }

    private function checkoutResponse($transaction, array $params, $order = null): array
    {
        return [
            'status'     => 'success',
            'nextAction' => 'razorpay',
            'actionName' => 'custom',
            'message'    => __('Opening Razorpay Checkout...', 'razorpay-for-fluentcart'),
            'response'   => array_merge([
                'api_key'          => $this->settings->apiKey(),
                'transaction_uuid' => (string) $transaction->uuid,
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('rpfc_confirm'),
                'test_mode'        => $this->settings->isTest(),
                'prefill'          => $this->customerPrefill($order),
            ], $params),
        ];
    }

    private function customerPrefill($order): array
    {
        if (!$order) {
            return ['name' => '', 'email' => '', 'contact' => ''];
        }
        try {
            $order->loadMissing('customer', 'billing_address');
        } catch (\Throwable $e) {}

        $billing = $order->billing_address ?? null;
        $customer = $order->customer ?? null;
        $name = trim((string) ($billing->name ?? ''));
        if (!$name && $customer) {
            $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: (string) ($customer->name ?? '');
        }

        return [
            'name'    => $name,
            'email'   => (string) ($customer->email ?? ($order->email ?? '')),
            'contact' => preg_replace('/[^\+0-9]/', '', (string) ($billing->phone ?? ($customer->phone ?? ''))),
        ];
    }
}
