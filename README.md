# Stripe Checkout 一次性支付插件

为 [Xboard](https://github.com/cedar2025/Xboard) 增加 **Stripe Checkout 一次性支付**能力。

## 功能

- ✅ Stripe Checkout Session 一次性支付模式
- ✅ 多币种结算（USD / EUR / GBP / HKD / CNY 等）
- ✅ 自动汇率换算（站点 CNY 定价 → Stripe 外币结算）
- ✅ 手续费反推计算，由用户承担（固定 + 百分比）
- ✅ Webhook HMAC-SHA256 签名验证
- ✅ Event ID 幂等检查（防止并发/重试导致重复开通）
- ✅ 服务端金额校验（防止配置异常导致低价开通）
- ✅ 支持同步支付（信用卡）和异步支付（银行转账等）
- ✅ 3D Secure 自动适配（由 Stripe 风控引擎决定）
- ✅ Octane（Swoole / RoadRunner）运行时兼容
- ✅ 纯插件实现，不修改核心代码

## 系统要求

- Xboard >= 1.0.0
- PHP >= 8.2
- `stripe/stripe-php` >= 7.36（Xboard 已自带）
- 必须配置 Redis/Memcached 缓存驱动（用于事件幂等检查）

---

## 安装

```bash
cd /path/to/xboard
git clone https://github.com/Shannon-x/xboard-plugin-stripe-checkout.git plugins/StripeCheckout
```

进入 Xboard **管理后台 → 插件管理 → 安装 → 启用**。

---

## 配置

### 第一步：获取 Stripe 密钥

1. 登录 [Stripe Dashboard](https://dashboard.stripe.com/)
2. 进入 **Developers → API Keys**
3. 记录以下密钥：

| 密钥 | 前缀 | 用途 |
|------|------|------|
| Secret Key | `sk_live_` 或 `sk_test_` | 服务端 API 通信（**绝不暴露给前端**） |
| Publishable Key | `pk_live_` 或 `pk_test_` | 预留字段（本插件暂未使用） |

> ⚠️ **安全提示**：Secret Key 是最高权限密钥。切勿将其提交到 Git、写入前端代码或通过非加密渠道传输。

### 第二步：配置 Webhook

1. 进入 Stripe Dashboard → **Developers → Webhooks → Add endpoint**
2. 填写 Endpoint URL：

```
https://你的域名/api/v1/guest/payment/stripe-checkout-webhook/{uuid}
```

> 其中 `{uuid}` 是在 Xboard 后台保存支付方式后自动生成的 UUID。先完成第三步保存后，回来填写此 URL。

3. 选择监听事件（**仅勾选以下 3 个，不要多选**）：

```
checkout.session.completed
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
```

4. 点击 **Add endpoint** 创建
5. 进入新创建的 Webhook 详情页，点击 **Reveal signing secret**
6. 记录 Webhook Signing Secret（`whsec_` 开头）

> ⚠️ **安全提示**：只监听你需要的事件。监听不必要的事件会增加攻击面。

### 第三步：Xboard 后台配置

进入 **支付配置 → 添加支付方式 → 选择 StripeCheckout**，填写：

| 配置项 | 说明 | 示例 |
|--------|------|------|
| Secret Key | Stripe API 密钥 | `sk_live_...` |
| Publishable Key | Stripe 公钥（预留） | `pk_live_...` |
| Webhook 签名密钥 | Webhook 端点签名 | `whsec_...` |
| 结算货币 | Stripe 实际收款币种 | `usd` |
| 手续费百分比 | Stripe 百分比费率 | `2.9` |
| 固定手续费 | 结算货币最小单位 | `30`（$0.30） |
| 固定汇率 | 站点 CNY → 结算货币 | 留空/`0` 自动获取，或填 `7.3`（1 USD = 7.3 CNY） |

保存后将生成 UUID，用此 UUID 回到 Stripe Dashboard 完成 Webhook URL 配置。

### 第四步：验证 Webhook 连通性

在 Stripe Dashboard 的 Webhook 详情页，点击 **Send test webhook**，选择 `checkout.session.completed` 事件发送测试。

检查 Xboard 日志确认收到：

```bash
tail -f storage/logs/laravel.log | grep StripeCheckout
```

应看到类似输出：

```
[StripeCheckout] Webhook event: checkout.session.completed {"id":"evt_test_..."}
```

---

## 多币种配置示例

在 Xboard 后台创建**多个支付方式实例**，每个对应一种货币：

### Stripe USD（美元结算）

| 配置项 | 值 | 说明 |
|--------|-----|------|
| 结算货币 | `usd` | |
| 手续费百分比 | `2.9` | 美国 Stripe 标准费率 |
| 固定手续费 | `30` | $0.30 = 30 cents |
| 固定汇率 | 留空或 `0` | 自动获取实时 CNY → USD 汇率；也可手动填 `7.3` |

### Stripe EUR（欧元结算）

| 配置项 | 值 | 说明 |
|--------|-----|------|
| 结算货币 | `eur` | |
| 手续费百分比 | `1.4` | 欧洲经济区 Stripe 费率 |
| 固定手续费 | `25` | €0.25 = 25 cents |
| 固定汇率 | 留空或 `0` | 自动获取实时 CNY → EUR 汇率；也可手动填 `7.7` |

### Stripe CNY（人民币结算）

| 配置项 | 值 | 说明 |
|--------|-----|------|
| 结算货币 | `cny` | |
| 手续费百分比 | `3.4` | 香港/大中华区费率 |
| 固定手续费 | `350` | ¥3.50 = 350 分 |
| 固定汇率 | 留空或 `0` | 同币种自动按 1:1 处理 |

> ⚠️ **重要**：每个支付方式实例需要独立的 Webhook 端点（不同 UUID），在 Stripe Dashboard 分别配置。

---

## 手续费计算

**公式**：`用户实付 = (订单金额 + 固定手续费) ÷ (1 - 百分比费率)`

### 示例：站点 ¥100 套餐，Stripe 收 USD

```
输入：
  订单金额：10000 分 (¥100.00)
  汇率：自动获取，示例按 1 USD = 7.3 CNY
  手续费：2.9% + $0.30

计算：
  ① 汇率换算：ceil(10000 ÷ 7.3) = 1370 cents ($13.70)
  ② 手续费反推：(1370 + 30) ÷ (1 - 0.029) = 1442 cents ($14.42)

验证：
  Stripe 扣费：$14.42 × 2.9% + $0.30 = $0.72
  商家到账：$14.42 - $0.72 = $13.70 ≈ ¥100 ✓
```

---

## 支付流程

```
用户下单
  ↓
选择 Stripe 支付
  ↓
服务端创建 Checkout Session（含预期金额 metadata）
  ↓
跳转 Stripe 托管收银台（3DS 验证在此执行）
  ↓
用户完成支付
  ↓
Stripe 发送 Webhook → 插件验签
  ↓
幂等检查 → 金额校验 → OrderService::paid()
  ↓
订单完成，用户开通服务
```

---

## 安全机制

### 1. Webhook 签名验证

使用 Stripe SDK 的 `Webhook::constructEvent()` 进行 HMAC-SHA256 签名验证，内置 300 秒时间容差防重放攻击。签名密钥 (`whsec_`) 不可泄露。

### 2. 服务端金额校验

创建 Checkout Session 时，将计算后的预期收款金额写入 `metadata.expected_amount`。Webhook 回调时，验证 `session.amount_total >= expected_amount`。防止以下异常：

- 配置参数被意外修改导致低价
- Stripe API 返回异常数据

### 3. Event ID 幂等检查

每个 Stripe 事件 ID 在处理后写入缓存（7 天过期）。相同事件重复到达时直接返回 200，防止：

- Stripe 自动重试导致重复开通
- 极端并发下的 Race Condition

### 4. 3D Secure (3DS)

3DS 验证完全由 Stripe Checkout 页面在 Stripe 侧执行，不经过你的服务器。触发条件由 Stripe Radar 风控引擎和发卡行 SCA 规则决定，无法从插件侧绕过。

### 5. 防御纵深

| 层级 | 防护 |
|------|------|
| 传输层 | HTTPS（Stripe 强制） |
| 身份层 | Webhook HMAC 签名验证 |
| 金额层 | metadata 预期金额 vs 实际金额 |
| 状态层 | `ORDER_PENDING` 状态检查（双重：Controller + OrderService） |
| 幂等层 | Event ID 缓存去重 |
| 应用层 | 3DS 由 Stripe 侧强制执行 |

---

## 安全检查清单

部署前请逐项确认：

- [ ] 使用 `sk_live_` 密钥（非 `sk_test_`）
- [ ] Webhook 签名密钥 (`whsec_`) 已正确填写
- [ ] Webhook 只监听了 3 个必要事件
- [ ] 站点已启用 HTTPS
- [ ] 缓存驱动已配置为 Redis 或 Memcached（非 file/array）
- [ ] Stripe Dashboard 已开启 Radar 风控规则
- [ ] 汇率和手续费参数已验证正确
- [ ] 发送测试 Webhook 确认连通性
- [ ] 使用 Stripe 测试卡完成一笔完整支付流程

---

## 故障排查

### Webhook 返回 419

**原因**：路由被 CSRF 中间件拦截。
**解决**：确认 Xboard 插件加载器使用 `api` 中间件组（无 CSRF）加载 `routes/api.php`。

### Webhook 签名验证失败

**可能原因**：
1. `whsec_` 密钥填写错误
2. 服务器时钟偏差 > 300 秒 → 执行 `ntpdate -u pool.ntp.org`
3. 反向代理修改了请求 body → Nginx 确保 `proxy_pass` 原样转发

### 支付金额低于预期被拒绝

**日志特征**：`安全警告：支付金额低于预期，拒绝开通`
**原因**：创建订单时的配置参数与当前不同（通常是汇率被修改）。这是正常的安全防护，不是 bug。

### 事件已处理（跳过）

**日志特征**：`事件已处理，跳过`
**原因**：Stripe 重试发送了已处理的事件，幂等检查正常跳过。无需处理。

---

## 本地测试

使用 [Stripe CLI](https://stripe.com/docs/stripe-cli) 在本地测试 Webhook：

```bash
# 安装 Stripe CLI 后登录
stripe login

# 转发 Webhook 到本地
stripe listen --forward-to http://localhost:8000/api/v1/guest/payment/stripe-checkout-webhook/{uuid}

# 触发测试事件
stripe trigger checkout.session.completed
```

### Stripe 测试卡号

| 卡号 | 场景 |
|------|------|
| `4242 4242 4242 4242` | 支付成功 |
| `4000 0025 0000 3155` | 需要 3DS 验证 |
| `4000 0000 0000 9995` | 支付被拒 |

---

## 开源协议

MIT License
