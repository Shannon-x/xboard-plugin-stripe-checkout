<?php

namespace Plugin\StripeCheckout;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
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
                'description' => 'Stripe 百分比手续费（如美国 2.9、欧洲 1.4、香港 3.4）',
            ],
            'fee_fixed_cents' => [
                'label'       => '固定手续费（结算货币最小单位）',
                'type'        => 'string',
                'default'     => '30',
                'description' => 'Stripe 固定手续费，单位为结算货币最小单位（USD: $0.30=30, EUR: €0.25=25, CNY: ¥3.50=350）',
            ],
            'exchange_rate' => [
                'label'       => '固定汇率（留空自动获取）',
                'type'        => 'string',
                'default'     => '',
                'description' => '站点 CNY 到 Stripe 结算货币的固定汇率。如 Stripe 收 USD 且 1 USD=7.3 CNY 填 7.3；留空或填 0 自动获取实时汇率',
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

        $tradeNo = $this->normalizeTradeNo($order['trade_no'] ?? '');

        $originalAmount = (int) $order['total_amount'];
        $convertedAmount = $this->convertAmount($originalAmount, $currency);

        $chargeAmount = $this->addHandlingFee($convertedAmount);

        $params = [
            'mode'        => 'payment',
            'success_url' => $order['return_url'],
            'cancel_url'  => $order['return_url'],
            'client_reference_id' => $tradeNo,
            'line_items' => [[
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $chargeAmount,
                    'product_data' => [
                        'name' => $tradeNo,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'trade_no'        => $tradeNo,
                'user_id'         => $order['user_id'],
                'expected_amount' => (string) $chargeAmount,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'trade_no'        => $tradeNo,
                    'user_id'         => $order['user_id'],
                    'expected_amount' => (string) $chargeAmount,
                ],
            ],
        ];

        try {
            $session = Session::create($params);
        } catch (\Exception $e) {
            \Log::error('[StripeCheckout] 创建支付会话失败: ' . $e->getMessage());
            throw new \App\Exceptions\ApiException('支付创建失败，请稍后重试');
        }

        return [
            'type' => 1,
            'data' => $session->url,
        ];
    }

    /**
     * Stripe Webhook 验签 + 事件处理
     *
     * 安全机制：
     * 1. HMAC-SHA256 签名验证（Stripe SDK 内置，含 300s 时间窗口防重放）
     * 2. Event ID 幂等检查（防止并发/重试导致重复开通）
     * 3. 金额校验（验证实付金额 ≥ 创建时预期金额，防止配置篡改/异常）
     *
     * 监听事件：
     * - checkout.session.completed              同步支付成功（信用卡等）
     * - checkout.session.async_payment_succeeded 异步支付成功（银行转账等）
     * - checkout.session.async_payment_failed    异步支付失败
     */
    public function notify($params): array|bool
    {
        $webhookKey = $this->getConfig('stripe_webhook_key');
        $sk         = $this->getConfig('stripe_sk_live');
        Stripe::setApiKey($sk);

        $payload   = $params['_raw_body'] ?? request()->getContent();
        $sigHeader = $params['_stripe_signature'] ?? request()->header('Stripe-Signature', '');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookKey);
        } catch (\Exception $e) {
            \Log::warning('[StripeCheckout] Webhook 签名验证失败: ' . $e->getMessage());
            return false;
        }

        // 幂等检查：同一 Stripe 事件不重复处理
        $eventKey = "stripe_checkout_evt:{$event->id}";
        if (\Illuminate\Support\Facades\Cache::has($eventKey)) {
            \Log::info('[StripeCheckout] 事件已处理，跳过', ['event_id' => $event->id]);
            return ['custom_result' => response()->json(['received' => true], 200)];
        }

        \Log::info('[StripeCheckout] Webhook event: ' . $event->type, ['id' => $event->id]);

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                if ($session->payment_status === 'paid') {
                    if (!$this->verifyPaymentAmount($session)) {
                        return false;
                    }
                    \Illuminate\Support\Facades\Cache::put($eventKey, true, 86400 * 7);
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
                if (!$this->verifyPaymentAmount($session)) {
                    return false;
                }
                \Illuminate\Support\Facades\Cache::put($eventKey, true, 86400 * 7);
                return [
                    'trade_no'      => $session->client_reference_id,
                    'callback_no'   => $session->payment_intent,
                    'custom_result' => response()->json(['received' => true], 200),
                ];

            case 'checkout.session.async_payment_failed':
                $session = $event->data->object;
                \Log::warning('[StripeCheckout] 异步支付失败', [
                    'trade_no'   => $session->client_reference_id,
                    'session_id' => $session->id,
                ]);
                \Illuminate\Support\Facades\Cache::put($eventKey, true, 86400 * 7);
                return ['custom_result' => response()->json(['received' => true], 200)];

            default:
                return ['custom_result' => response()->json(['received' => true], 200)];
        }
    }

    /**
     * 验证 Stripe 实际收款金额 ≥ 创建 Session 时的预期金额
     * 预期金额在 pay() 中写入 metadata.expected_amount
     *
     * 策略：宁可漏过也不误杀
     * - 双方金额都 > 0 且 actual < expected → 拒绝（真正的异常）
     * - 任一方缺失或为 0 → 警告但放行（防止 API 变动导致误杀付款用户）
     */
    private function verifyPaymentAmount($session): bool
    {
        $expectedAmount = (int) ($session->metadata->expected_amount ?? 0);
        $actualAmount   = (int) ($session->amount_total ?? 0);

        // 缺少校验数据：警告但放行，不阻断用户
        if ($expectedAmount <= 0 || $actualAmount <= 0) {
            \Log::warning('[StripeCheckout] 金额校验跳过：缺少预期或实际金额', [
                'trade_no'   => $session->client_reference_id,
                'expected'   => $expectedAmount,
                'actual'     => $actualAmount,
                'session_id' => $session->id,
            ]);
            return true;
        }

        // 实付 < 预期：拒绝开通
        if ($actualAmount < $expectedAmount) {
            \Log::error('[StripeCheckout] 安全警告：支付金额低于预期，拒绝开通', [
                'trade_no'   => $session->client_reference_id,
                'expected'   => $expectedAmount,
                'actual'     => $actualAmount,
                'session_id' => $session->id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * 部分环境 / 旧版核心会在支付参数里给 trade_no 加上「Xboard - 」展示前缀；
     * Stripe 行项目名与 client_reference_id 必须与库里的订单号一致，故统一剥掉此前缀。
     */
    private function normalizeTradeNo(mixed $tradeNo): string
    {
        $s = trim((string) $tradeNo);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^Xboard\s*-\s*(.+)$/iu', $s, $m)) {
            return trim($m[1]);
        }

        return $s;
    }

    /**
     * 手续费反推：让用户承担 Stripe 手续费
     * 公式：用户实付 = (金额 + 固定费) ÷ (1 - 百分比费率)
     * 注意：金额和固定费必须为同一货币的最小单位(cents/分)
     */
    private function addHandlingFee(int $amountCents): int
    {
        $feePercent    = (float) $this->getConfig('fee_percent', 3.4);
        $feeFixedCents = (int) $this->getConfig('fee_fixed_cents', 30);

        if ($feePercent < 0 || $feePercent >= 100) {
            \Log::warning('[StripeCheckout] fee_percent 超出合理范围，跳过手续费', ['fee_percent' => $feePercent]);
            return $amountCents;
        }

        $rate  = $feePercent / 100;
        $total = ($amountCents + $feeFixedCents) / (1 - $rate);

        return (int) ceil($total);
    }

    /**
     * Xboard 站点价格按 CNY 分保存；Stripe 需要目标币种最小单位金额。
     * exchange_rate 填大于 0 时使用固定汇率，留空/0 时自动获取 CNY -> 目标币种汇率。
     */
    private function convertAmount(int $amountCents, string $currency): int
    {
        if ($amountCents <= 0) {
            throw new ApiException('订单金额不能为 0', 400);
        }

        $customExchangeRate = (float) $this->getConfig('exchange_rate', 0);
        if ($customExchangeRate > 0) {
            $convertedAmount = $customExchangeRate == 1.0
                ? $amountCents
                : (int) ceil($amountCents / $customExchangeRate);

            $this->logExchange('manual', $amountCents, $convertedAmount, $currency, $customExchangeRate);
            return max(1, $convertedAmount);
        }

        $exchange = $this->exchange('CNY', $currency);
        if (!$exchange) {
            throw new ApiException('在线汇率转换服务未响应，请联系管理在后台设定固定汇率', 500);
        }

        $convertedAmount = (int) ceil($amountCents * $exchange);
        $this->logExchange('auto', $amountCents, $convertedAmount, $currency, $exchange);

        return max(1, $convertedAmount);
    }

    private function logExchange(string $mode, int $originalAmount, int $convertedAmount, string $currency, float $exchangeRate): void
    {
        if ($currency === 'cny' && $convertedAmount === $originalAmount) {
            return;
        }

        \Log::info('[StripeCheckout] 汇率换算', [
            'mode'          => $mode,
            'original'      => $originalAmount,
            'exchange_rate' => $exchangeRate,
            'converted'     => $convertedAmount,
            'currency'      => $currency,
        ]);
    }

    private function exchange(string $from, string $to): ?float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $from = strtolower($from);
        $to = strtolower($to);

        try {
            $result = @file_get_contents(
                "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{$from}.min.json"
            );

            if (is_string($result) && $result !== '') {
                $decoded = json_decode($result, true);
                if (isset($decoded[$from][$to])) {
                    return (float) $decoded[$from][$to];
                }
            }
        } catch (\Throwable $e) {
            // Try the backup provider below.
        }

        return $this->exchangeFromBackup($from, $to);
    }

    private function exchangeFromBackup(string $from, string $to): ?float
    {
        try {
            $result = @file_get_contents('https://api.exchangerate-api.com/v4/latest/' . strtoupper($from));
            if (!is_string($result) || $result === '') {
                return null;
            }

            $decoded = json_decode($result, true);
            $to = strtoupper($to);

            return isset($decoded['rates'][$to]) ? (float) $decoded['rates'][$to] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
