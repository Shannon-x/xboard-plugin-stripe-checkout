<?php

use Illuminate\Support\Facades\Route;
use Plugin\StripeCheckout\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Stripe Checkout Webhook 路由
|--------------------------------------------------------------------------
|
| Webhook URL：POST /api/v1/guest/payment/stripe-checkout-webhook/{uuid}
|
| 在 Stripe Dashboard 配置 Webhook 端点时使用此地址，监听事件：
|   - checkout.session.completed
|   - checkout.session.async_payment_succeeded
|   - checkout.session.async_payment_failed
|   - payment_intent.payment_failed   （试卡防护失败计数，必须勾选）
|
*/

Route::prefix('api/v1/guest/payment')->group(function () {
    Route::post('stripe-checkout-webhook/{uuid}', [WebhookController::class, 'handle']);
});
