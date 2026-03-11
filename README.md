# Stripe 一次性支付插件

为 Xboard 增加 **Stripe Checkout 一次性支付**，参考 [wyx2685/v2board](https://github.com/wyx2685/v2board) 的 StripeCheckout 实现逻辑。

## 功能

- ✅ Stripe Checkout Session 一次性支付模式
- ✅ 手续费自动计算并转嫁用户（固定 + 百分比）
- ✅ 手动设置汇率、手续费
- ✅ Webhook 签名验证
- ✅ 支持同步支付（信用卡）和异步支付（银行转账等）
- ✅ 纯插件实现，不修改核心代码

## 与循环订阅插件的区别

| | StripeCheckout（本插件） | StripeSubscription |
|--|------------------------|--------------------|
| 支付模式 | 一次性 (payment) | 循环 (subscription) |
| 自动续费 | 否 | 是 |
| 适用场景 | 单次购买、流量重置包 | 月付/年付自动续费 |
| Webhook 事件 | checkout.session.completed | invoice.paid / payment_failed |

## 安装

```bash
cd /path/to/xboard
git clone https://github.com/Shannon-x/xboard-plugin-stripe-checkout.git plugins/StripeCheckout
```

管理后台 → 插件管理 → 安装 → 启用。

## 配置

### Stripe Dashboard

1. 获取 **Secret Key** 和 **Publishable Key**
2. 创建 Webhook 端点：
   - URL: `https://your-domain.com/api/v1/guest/payment/stripe-checkout-webhook/{uuid}`
   - 事件：`checkout.session.completed`、`checkout.session.async_payment_succeeded`
3. 记录 **Webhook Signing Secret**

### Xboard 后台

支付配置 → 添加支付方式 → 选择 **StripeCheckout** → 填写密钥和手续费参数。

| 配置项 | 说明 | 示例 |
|--------|------|------|
| Secret Key | Stripe 密钥 | `sk_live_...` |
| Publishable Key | Stripe 公钥 | `pk_live_...` |
| Webhook 签名密钥 | Webhook 签名 | `whsec_...` |
| 结算货币 | Stripe 收款币种 | `cny` |
| 手续费百分比 | 百分比费率 | `3.4` |
| 固定手续费(分) | 固定费用 | `50` ($0.50) |
| 汇率 | 固定费换算 | `7.2` (USD→CNY) |

## 手续费计算

用户实付 = `(原价 + 固定费 × 汇率) ÷ (1 - 百分比费率)`

示例（原价 ¥100，手续费 3.4% + $0.50，汇率 7.2）：
- 用户实付：¥107.25
- Stripe 扣除：¥7.25
- 商家到账：¥100.00

## 支付流程

```
用户下单 → 选择 Stripe 支付 → 跳转 Stripe Checkout 页面
    → 用户完成支付 → Stripe 回调 Webhook
    → 插件验签 → 核心 OrderService::paid() → 订单完成
```

## 系统要求

- Xboard >= 1.0.0
- PHP >= 8.2
- `stripe/stripe-php` >= 7.36（Xboard 已自带）

## 开源协议

MIT License
