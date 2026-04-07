<?php

namespace Plugin\StripeCheckout\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
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

        $pluginManager = app(PluginManager::class);
        $paymentPlugin = null;

        $config = is_string($payment->config) ? json_decode($payment->config, true) : ($payment->config ?? []);
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
            $order = Order::where('trade_no', $result['trade_no'])->first();
            if ($order && $order->status === Order::STATUS_PENDING) {
                $orderService = new OrderService($order);
                $orderService->paid($result['callback_no']);
                HookManager::call('payment.notify.success', $order);
            }
        }

        if (isset($result['custom_result'])) {
            return $result['custom_result'];
        }

        return response()->json(['received' => true]);
    }
}
