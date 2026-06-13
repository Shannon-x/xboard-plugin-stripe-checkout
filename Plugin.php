<?php

namespace Plugin\StripeCheckout;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    /**
     * Stripe 零小数货币（金额最小单位 = 1 个货币单位，没有"分"）
     * https://stripe.com/docs/currencies#zero-decimal
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    /**
     * Stripe 三位小数货币（金额最小单位 = 1/1000，且最后一位必须为 0）
     * https://stripe.com/docs/currencies#three-decimal
     */
    private const THREE_DECIMAL_CURRENCIES = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

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
        $uuid = $this->getConfig('uuid');
        if ($uuid) {
            $webhookPath = "/api/v1/guest/payment/stripe-checkout-webhook/{$uuid}";
            $notifyDomain = $this->getConfig('notify_domain');
            $webhookUrl = $notifyDomain ? rtrim($notifyDomain, '/') . $webhookPath : url($webhookPath);
        } else {
            $webhookUrl = '';
        }

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
            'webhook_url' => [
                'label'       => 'Stripe Webhook URL（复制到 Stripe Dashboard）',
                'type'        => 'string',
                'default'     => $webhookUrl,
                'description' => $uuid
                    ? '请将此地址原样配置到 Stripe Dashboard → 开发者 → Webhooks 端点。监听事件：checkout.session.completed、checkout.session.async_payment_succeeded、checkout.session.async_payment_failed'
                    : '请先保存支付方式，保存后此处将自动生成 Webhook URL',
            ],
            'currency' => [
                'label'       => '结算货币',
                'type'        => 'string',
                'default'     => 'cny',
                'description' => '如 cny、usd、hkd、eur、jpy（零小数与三位小数货币已自动按 Stripe 规则换算）',
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
                'description' => 'Stripe 固定手续费，单位为结算货币最小单位（USD: $0.30=30, EUR: €0.25=25, CNY: ¥3.50=350, JPY: ¥30=30）',
            ],
            'exchange_rate' => [
                'label'       => '固定汇率（留空自动获取）',
                'type'        => 'string',
                'default'     => '',
                'description' => '1 个 Stripe 结算货币单位等于多少 CNY。如 Stripe 收 USD 且 1 USD=7.3 CNY 填 7.3；留空或填 0 自动获取实时汇率。注意：日志里 fixed_rate_hint 字段给出的就是此处应填的值',
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
        // 转换为目标货币的最小单位（零小数/三位小数货币按 Stripe 规则归一化）
        $convertedAmount = $this->convertAmount($originalAmount, $currency);

        $chargeAmount = $this->addHandlingFee($convertedAmount);
        $chargeAmount = $this->roundForStripe($chargeAmount, $currency);

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
     * 2. session 归属判定：只处理带本插件 metadata.trade_no 的 session。
     *    Stripe 账户可能被多个产品共用，账户级事件会把其它产品的 checkout.session.completed
     *    也推送到本端点；无归属 metadata 的 session 一律确认（200）但不入账。
     * 3. 金额校验（fail-closed：本插件创建的 session 必带 expected_amount，缺失即拒绝）
     * 4. Event ID 幂等：notify() 只读检查"已处理"标记；标记本身由 WebhookController
     *    在订单入账成功之后才写入（入账失败会释放并以非 2xx 触发 Stripe 重试），
     *    避免"先标记后入账"导致支付被永久吞掉。
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

        $eventKey = "stripe_checkout_evt:{$event->id}";
        if (\Illuminate\Support\Facades\Cache::has($eventKey)) {
            \Log::info('[StripeCheckout] 事件已处理，跳过', ['event_id' => $event->id]);
            return ['custom_result' => response()->json(['received' => true], 200)];
        }

        \Log::info('[StripeCheckout] Webhook event: ' . $event->type, ['id' => $event->id]);

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $session = $event->data->object;

                // completed 但未支付 = 异步支付（银行转账等），等待后续 async 事件
                if ($event->type === 'checkout.session.completed' && $session->payment_status !== 'paid') {
                    return ['custom_result' => response()->json(['received' => true], 200)];
                }

                // 归属判定：非本插件创建的 session（共享账户里其它产品的）直接确认，不入账
                $tradeNo = $session->metadata->trade_no ?? null;
                if (!$tradeNo) {
                    \Log::info('[StripeCheckout] 非本插件创建的 session，跳过', [
                        'session_id'          => $session->id ?? 'unknown',
                        'client_reference_id' => $session->client_reference_id ?? null,
                    ]);
                    return ['custom_result' => response()->json(['received' => true], 200)];
                }

                // client_reference_id 不可信，只允许与本插件写入的 metadata.trade_no 一致
                if (!empty($session->client_reference_id) && $session->client_reference_id !== $tradeNo) {
                    \Log::error('[StripeCheckout] client_reference_id 与 metadata.trade_no 不一致，拒绝处理', [
                        'session_id'          => $session->id,
                        'client_reference_id' => $session->client_reference_id,
                        'metadata_trade_no'   => $tradeNo,
                    ]);
                    return false;
                }

                if (!$this->verifyPaymentAmount($session)) {
                    return false;
                }

                return [
                    'trade_no'      => $tradeNo,
                    'callback_no'   => $session->payment_intent ?: $session->id,
                    '_event_key'    => $eventKey,
                    'custom_result' => response()->json(['received' => true], 200),
                ];

            case 'checkout.session.async_payment_failed':
                $session = $event->data->object;
                \Log::warning('[StripeCheckout] 异步支付失败', [
                    'trade_no'   => $session->metadata->trade_no ?? $session->client_reference_id ?? null,
                    'session_id' => $session->id,
                ]);
                // 无入账动作，可直接标记已处理
                \Illuminate\Support\Facades\Cache::put($eventKey, true, 86400 * 7);
                return ['custom_result' => response()->json(['received' => true], 200)];

            default:
                return ['custom_result' => response()->json(['received' => true], 200)];
        }
    }

    /**
     * 验证 Stripe 实际收款金额 ≥ 创建 Session 时的预期金额（fail-closed）。
     *
     * 只有归属判定通过（带本插件 metadata.trade_no）的 session 才会走到这里，
     * 而本插件创建的 session 必带 expected_amount —— 缺失即数据异常，拒绝入账。
     */
    private function verifyPaymentAmount($session): bool
    {
        $expectedAmount = (int) ($session->metadata->expected_amount ?? 0);
        $actualAmount   = (int) ($session->amount_total ?? 0);

        if ($expectedAmount <= 0 || $actualAmount <= 0) {
            \Log::error('[StripeCheckout] 金额校验失败：本插件 session 缺少预期或实际金额，拒绝入账', [
                'trade_no'   => $session->metadata->trade_no ?? null,
                'expected'   => $expectedAmount,
                'actual'     => $actualAmount,
                'session_id' => $session->id,
            ]);
            return false;
        }

        if ($actualAmount < $expectedAmount) {
            \Log::error('[StripeCheckout] 安全警告：支付金额低于预期，拒绝开通', [
                'trade_no'   => $session->metadata->trade_no ?? null,
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
     * 注意：金额和固定费必须为同一货币的最小单位
     */
    private function addHandlingFee(int $amountMinor): int
    {
        $feePercent    = (float) $this->getConfig('fee_percent', 3.4);
        $feeFixedCents = (int) $this->getConfig('fee_fixed_cents', 30);

        if ($feePercent < 0 || $feePercent >= 100) {
            \Log::warning('[StripeCheckout] fee_percent 超出合理范围，跳过手续费', ['fee_percent' => $feePercent]);
            return $amountMinor;
        }

        $rate  = $feePercent / 100;
        $total = ($amountMinor + $feeFixedCents) / (1 - $rate);

        return (int) ceil($total);
    }

    /**
     * Xboard 站点价格按 CNY 分保存；Stripe 需要目标币种最小单位金额。
     * exchange_rate 填大于 0 时使用固定汇率（1 目标货币单位 = N CNY），
     * 留空/0 时自动获取 CNY -> 目标币种汇率。
     */
    private function convertAmount(int $amountCents, string $currency): int
    {
        if ($amountCents <= 0) {
            throw new ApiException('订单金额不能为 0', 400);
        }

        $customExchangeRate = (float) $this->getConfig('exchange_rate', 0);
        if ($customExchangeRate > 0) {
            $hundredths = $customExchangeRate == 1.0
                ? $amountCents
                : (int) ceil($amountCents / $customExchangeRate);

            $converted = $this->toMinorUnits($hundredths, $currency);
            $this->logExchange('manual', $amountCents, $converted, $currency, $customExchangeRate);
            return max(1, $converted);
        }

        $exchange = $this->exchange('CNY', $currency);
        if (!$exchange) {
            throw new ApiException('在线汇率转换服务未响应，请联系管理在后台设定固定汇率', 500);
        }

        $hundredths = (int) ceil($amountCents * $exchange);
        $converted = $this->toMinorUnits($hundredths, $currency);
        $this->logExchange('auto', $amountCents, $converted, $currency, $exchange);

        return max(1, $converted);
    }

    /**
     * "目标货币百分之一单位" -> Stripe 最小单位。
     * 两位小数货币（usd/eur/cny...）：不变；
     * 零小数货币（jpy/krw/vnd...）：/100（最小单位是 1 个货币单位）；
     * 三位小数货币（kwd/bhd...）：*10（最小单位是 1/1000）。
     */
    private function toMinorUnits(int $hundredths, string $currency): int
    {
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return max(1, (int) ceil($hundredths / 100));
        }
        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return $hundredths * 10;
        }
        return $hundredths;
    }

    /**
     * Stripe 要求三位小数货币的金额最后一位必须为 0（向上取整到 10 的倍数）。
     */
    private function roundForStripe(int $amountMinor, string $currency): int
    {
        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return (int) (ceil($amountMinor / 10) * 10);
        }
        return $amountMinor;
    }

    private function logExchange(string $mode, int $originalAmount, int $convertedAmount, string $currency, float $exchangeRate): void
    {
        if ($currency === 'cny' && $convertedAmount === $originalAmount) {
            return;
        }

        // fixed_rate_hint = 后台「固定汇率」字段应填的值（1 目标货币单位 = N CNY）。
        // 自动模式拿到的 API 汇率是它的倒数，直接抄进配置会导致约几十倍多收，故两种语义都写明。
        \Log::info('[StripeCheckout] 汇率换算', [
            'mode'            => $mode,
            'original'        => $originalAmount,
            'converted'       => $convertedAmount,
            'currency'        => $currency,
            'rate_used'       => $exchangeRate,
            'fixed_rate_hint' => $mode === 'auto'
                ? round(1 / $exchangeRate, 6)
                : $exchangeRate,
        ]);
    }

    /**
     * CNY -> 目标币种实时汇率。
     * 30 分钟缓存 + 最近一次成功值兜底，HTTP 带超时，避免汇率源故障时拖死 Octane worker。
     */
    private function exchange(string $from, string $to): ?float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $from = strtolower($from);
        $to = strtolower($to);
        $cacheKey = "stripe_checkout:rate:{$from}:{$to}";
        $lkgKey   = "stripe_checkout:rate_lkg:{$from}:{$to}";

        $rate = \Illuminate\Support\Facades\Cache::remember($cacheKey, 1800, function () use ($from, $to) {
            return $this->fetchRatePrimary($from, $to) ?? $this->fetchRateBackup($from, $to);
        });

        if ($rate !== null && $rate > 0) {
            \Illuminate\Support\Facades\Cache::put($lkgKey, $rate, 86400 * 14);
            return (float) $rate;
        }

        $lkg = \Illuminate\Support\Facades\Cache::get($lkgKey);
        if ($lkg) {
            \Log::warning('[StripeCheckout] 汇率源不可用，使用最近一次成功汇率', [
                'from' => $from,
                'to'   => $to,
                'rate' => $lkg,
            ]);
            return (float) $lkg;
        }

        return null;
    }

    private function fetchRatePrimary(string $from, string $to): ?float
    {
        try {
            $response = \Illuminate\Support\Facades\Http::connectTimeout(3)->timeout(5)->get(
                "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{$from}.min.json"
            );
            if ($response->successful()) {
                $rate = $response->json("{$from}.{$to}");
                if ($rate) {
                    return (float) $rate;
                }
            }
        } catch (\Throwable $e) {
            // 主源失败，由调用方回退到备用源
        }

        return null;
    }

    private function fetchRateBackup(string $from, string $to): ?float
    {
        try {
            $response = \Illuminate\Support\Facades\Http::connectTimeout(3)->timeout(5)->get(
                'https://api.exchangerate-api.com/v4/latest/' . strtoupper($from)
            );
            if ($response->successful()) {
                $rate = $response->json('rates.' . strtoupper($to));
                if ($rate) {
                    return (float) $rate;
                }
            }
        } catch (\Throwable $e) {
            // 两个源都失败时由 exchange() 走最近成功值兜底
        }

        return null;
    }
}
