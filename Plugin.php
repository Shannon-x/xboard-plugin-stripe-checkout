<?php

namespace Plugin\StripeCheckout;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            $methods['StripeCheckout'] = [
                'name'        => 'Stripe 一次性支付',
                'icon'        => '💳',
                'plugin_code' => $this->getPluginCode(),
                'type'        => 'plugin',
            ];
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'stripe_sk_live' => [
                'label'       => 'Secret Key (SK)',
                'type'        => 'string',
                'required'    => true,
                'description' => 'Stripe API 密钥（sk_live_ 或 sk_test_）',
            ],
            'stripe_pk_live' => [
                'label'       => 'Publishable Key (PK)',
                'type'        => 'string',
                'required'    => true,
                'description' => 'Stripe API 公钥（pk_live_ 或 pk_test_）',
            ],
            'stripe_webhook_key' => [
                'label'       => 'Webhook 签名密钥',
                'type'        => 'string',
                'required'    => true,
                'description' => 'Webhook 端点签名密钥（whsec_）',
            ],
            'currency' => [
                'label'       => '结算货币',
                'type'        => 'string',
                'default'     => 'cny',
                'description' => '如 cny、usd、hkd',
            ],
            'fee_percent' => [
                'label'       => '手续费百分比 (%)',
                'type'        => 'string',
                'default'     => '3.4',
                'description' => 'Stripe 百分比手续费',
            ],
            'fee_fixed_cents' => [
                'label'       => '固定手续费（分）',
                'type'        => 'string',
                'default'     => '50',
                'description' => '如 $0.50 = 50，¥3.50 = 350',
            ],
            'exchange_rate' => [
                'label'       => '汇率',
                'type'        => 'string',
                'default'     => '1',
                'description' => '固定手续费换算汇率',
            ],
        ];
    }

    /**
     * 创建 Stripe Checkout Session（一次性支付模式）
     */
    public function pay($order): array
    {
        $sk       = $this->getConfig('stripe_sk_live');
        $currency = strtolower($this->getConfig('currency', 'cny'));
        Stripe::setApiKey($sk);

        $originalAmount = (int) $order['total_amount'];
        $chargeAmount   = $this->addHandlingFee($originalAmount);

        $params = [
            'mode'        => 'payment',
            'success_url' => $order['return_url'],
            'cancel_url'  => $order['return_url'],
            'client_reference_id' => $order['trade_no'],
            'line_items' => [[
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $chargeAmount,
                    'product_data' => [
                        'name' => 'Xboard - ' . $order['trade_no'],
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'trade_no' => $order['trade_no'],
                'user_id'  => $order['user_id'],
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'trade_no' => $order['trade_no'],
                    'user_id'  => $order['user_id'],
                ],
            ],
        ];

        try {
            $session = Session::create($params);
        } catch (\Exception $e) {
            \Log::error('[StripeCheckout] 创建支付会话失败: ' . $e->getMessage());
            throw new \App\Exceptions\ApiException('支付创建失败：' . $e->getMessage());
        }

        return [
            'type' => 1,
            'data' => $session->url,
        ];
    }

    /**
     * Stripe Webhook 验签 + 事件处理
     *
     * 监听事件：
     * - checkout.session.completed         首次支付成功（同步支付方式如信用卡）
     * - checkout.session.async_payment_succeeded  异步支付成功（如银行转账）
     */
    public function notify($params): array|bool
    {
        $webhookKey = $this->getConfig('stripe_webhook_key');
        $sk         = $this->getConfig('stripe_sk_live');
        Stripe::setApiKey($sk);

        $payload   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookKey);
        } catch (\Exception $e) {
            \Log::warning('[StripeCheckout] Webhook 签名验证失败: ' . $e->getMessage());
            return false;
        }

        \Log::info('[StripeCheckout] Webhook event: ' . $event->type, ['id' => $event->id]);

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                if ($session->payment_status === 'paid') {
                    return [
                        'trade_no'      => $session->client_reference_id,
                        'callback_no'   => $session->payment_intent,
                        'custom_result' => response()->json(['received' => true], 200),
                    ];
                }
                // payment_status 不是 paid 说明是异步支付，等后续事件
                return ['custom_result' => response()->json(['received' => true], 200)];

            case 'checkout.session.async_payment_succeeded':
                $session = $event->data->object;
                return [
                    'trade_no'      => $session->client_reference_id,
                    'callback_no'   => $session->payment_intent,
                    'custom_result' => response()->json(['received' => true], 200),
                ];

            case 'checkout.session.async_payment_failed':
                $session = $event->data->object;
                \Log::warning('[StripeCheckout] 异步支付失败', [
                    'trade_no' => $session->client_reference_id,
                ]);
                return ['custom_result' => response()->json(['received' => true], 200)];

            default:
                return ['custom_result' => response()->json(['received' => true], 200)];
        }
    }

    /**
     * 手续费反推：让用户承担 Stripe 手续费
     * 公式：用户实付 = (原价 + 固定费 × 汇率) ÷ (1 - 百分比费率)
     */
    private function addHandlingFee(int $amountCents): int
    {
        $feePercent    = (float) $this->getConfig('fee_percent', 3.4);
        $feeFixedCents = (int) $this->getConfig('fee_fixed_cents', 50);
        $exchangeRate  = (float) $this->getConfig('exchange_rate', 1);

        $fixedInLocal = (int) round($feeFixedCents * $exchangeRate);
        $rate         = $feePercent / 100;

        $total = ($amountCents + $fixedInLocal) / (1 - $rate);

        return (int) ceil($total);
    }
}
