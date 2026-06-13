<?php

namespace Plugin\StripeCheckout\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stripe Checkout Webhook 专用控制器
 * Stripe 签名验证需要原始 JSON body，不能走核心 PaymentController
 */
class WebhookController extends Controller
{
    public function handle(string $uuid, Request $request)
    {
        $payment = Payment::where('uuid', $uuid)->first();
        if (!$payment) {
            return response()->json(['error' => 'payment not found'], 404);
        }

        $config = is_string($payment->config) ? json_decode($payment->config, true) : ($payment->config ?? []);

        // 网关被运营禁用后不再入账，但必须回 2xx：本端点收的是 Stripe 账户级事件流
        // （共享账户下含其它产品的 session），持续 403 会让 Stripe 把端点自动停用，
        // 重新启用网关后所有真实回调都会静默丢失（即 2026-06-10 订阅端点事故的机制）。
        // 先验签再确认，未签名的垃圾请求仍然 400。
        if (!$payment->enable) {
            try {
                \Stripe\WebhookSignature::verifyHeader(
                    $request->getContent(),
                    $request->header('Stripe-Signature', ''),
                    $config['stripe_webhook_key'] ?? '',
                    300
                );
            } catch (\Throwable $e) {
                return response()->json(['error' => 'invalid signature'], 400);
            }
            $eventId = json_decode($request->getContent(), true)['id'] ?? 'unknown';
            Log::warning('[StripeCheckout] Webhook: 网关已禁用，事件已确认但不入账', [
                'uuid'     => $uuid,
                'event_id' => $eventId,
            ]);
            return response()->json(['received' => true, 'ignored' => 'gateway disabled']);
        }

        $pluginManager = app(PluginManager::class);
        $paymentPlugin = null;

        $config['enable'] = $payment->enable;
        $config['id']     = $payment->id;
        $config['uuid']   = $payment->uuid;

        $paymentMethods = HookManager::filter('available_payment_methods', []);
        if (isset($paymentMethods[$payment->payment])) {
            $pluginCode = $paymentMethods[$payment->payment]['plugin_code'];
            foreach ($pluginManager->getEnabledPaymentPlugins() as $plugin) {
                if ($plugin->getPluginCode() === $pluginCode) {
                    $plugin->setConfig($config);
                    $paymentPlugin = $plugin;
                    break;
                }
            }
        }

        if (!$paymentPlugin) {
            return response()->json(['error' => 'plugin not available'], 500);
        }

        $result = $paymentPlugin->notify(array_merge($request->all(), [
            '_raw_body'         => $request->getContent(),
            '_stripe_signature' => $request->header('Stripe-Signature', ''),
        ]));

        if ($result === false) {
            return response()->json(['error' => 'verification failed'], 400);
        }

        if (isset($result['trade_no']) && isset($result['callback_no'])) {
            $eventKey = $result['_event_key'] ?? null;
            $lockKey  = $eventKey ? "{$eventKey}:lock" : null;

            // 原子抢占（SETNX）防并发重投；"已处理"标记只在入账成功后写入，
            // 入账失败时释放锁并回非 2xx，让 Stripe 重试还能再次入账。
            if ($lockKey && !Cache::add($lockKey, true, 600)) {
                Log::info('[StripeCheckout] 事件正在处理或已处理，跳过', ['event_key' => $eventKey]);
            } else {
                try {
                    $order = Order::where('trade_no', $result['trade_no'])->first();

                    // 网关绑定：与核心 PaymentController 的 payment_gateway_bind 对齐，
                    // 订单必须属于本回调网关，防止共享 Stripe 账户下的跨网关入账。
                    if ($order && (int) $order->payment_id !== (int) $payment->id) {
                        Log::warning('[StripeCheckout] 订单网关不匹配，拒绝入账', [
                            'trade_no'         => $result['trade_no'],
                            'order_payment_id' => $order->payment_id,
                            'gateway_id'       => $payment->id,
                        ]);
                    } elseif ($order && $order->status === Order::STATUS_PENDING) {
                        $orderService = new OrderService($order);
                        if (!$orderService->paid($result['callback_no'])) {
                            if ($lockKey) {
                                Cache::forget($lockKey);
                            }
                            Log::error('[StripeCheckout] 订单入账失败，返回 400 等待 Stripe 重试', [
                                'trade_no' => $result['trade_no'],
                            ]);
                            return response()->json(['error' => 'order processing failed'], 400);
                        }
                        HookManager::call('payment.notify.success', $order);
                    }

                    if ($eventKey) {
                        Cache::put($eventKey, true, 86400 * 7);
                    }
                } catch (\Throwable $e) {
                    if ($lockKey) {
                        Cache::forget($lockKey);
                    }
                    throw $e;
                }
            }
        }

        if (isset($result['custom_result'])) {
            return $result['custom_result'];
        }

        return response()->json(['received' => true]);
    }
}
