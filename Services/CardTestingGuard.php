<?php

namespace Plugin\StripeCheckout\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;

/**
 * Card testing（批量试卡）防护。
 *
 * 攻击特征（本站 2026-06-30 实测）：机器人批量注册账号（注册到下单间隔仅 2~4 秒），
 * 每个账号创建一笔最低价订单打开 Checkout 页面，轮流试 ~10 张盗刷卡后换号再来。
 * 每次卡尝试都会被 Radar 按次计费，失败也烧钱，账单被恶意累积。
 *
 * 防护层次（自上而下，任意一层命中即中断）：
 *  1. 账户封禁     —— 支付失败次数达到阈值后禁止创建新会话（新注册账户阈值更低）
 *  2. 全局熔断     —— 全站失败率异常时整体暂停 Stripe 通道并通知管理员
 *  3. 频率限制     —— 单账户 / 单 IP 每小时创建会话数上限
 *  4. 会话复用     —— 同一订单复用未过期的 Checkout Session，反复点击不再新建
 *  5. 失败驱动过期 —— 账户命中封禁时立即过期其名下所有未完成会话，阻断继续试卡
 *
 * 所有阈值 <= 0 视为关闭对应防护层。计数器基于站点共享 Cache（Redis），
 * web 与 horizon 容器天然同步。
 */
class CardTestingGuard
{
    private const P = 'stripe_checkout:guard:';

    /** 每账户会话跟踪列表的长度上限，防止异常账户把缓存条目撑大 */
    private const TRACK_LIMIT = 20;

    public function __construct(
        private int $userHourly,
        private int $ipHourly,
        private int $failThreshold,
        private int $failThresholdNew,
        private int $blockHours,
        private int $globalFailHourly,
        private int $pauseMinutes,
    ) {
    }

    /**
     * 创建会话前的准入检查：封禁 → 熔断。
     * 放在会话复用之前调用，被封账户连缓存的旧会话 URL 也拿不到。
     */
    public function assertAllowed(int $userId): void
    {
        if ($userId > 0 && Cache::has(self::P . "block:u:{$userId}")) {
            throw new ApiException('该账户的在线支付已被临时限制，请使用其他支付方式或联系客服', 403);
        }
        if ($this->isPaused()) {
            throw new ApiException('银行卡支付通道临时维护中，请稍后重试或使用其他支付方式', 503);
        }
    }

    /**
     * 频率限制检查（计数即消耗，先到先得，天然抗并发）。
     * 只在真正要创建新会话时调用；命中订单会话复用缓存不消耗额度。
     */
    public function assertWithinRateLimits(int $userId, ?string $ip): void
    {
        $hourBucket = date('YmdH');

        if ($this->userHourly > 0 && $userId > 0) {
            $n = $this->hit(self::P . "rl:u:{$userId}:{$hourBucket}", 3900);
            if ($n > $this->userHourly) {
                throw new ApiException('支付请求过于频繁，请稍后再试', 429);
            }
        }

        if ($this->ipHourly > 0 && $ip) {
            $n = $this->hit(self::P . 'rl:ip:' . md5($ip) . ":{$hourBucket}", 3900);
            if ($n > $this->ipHourly) {
                throw new ApiException('支付请求过于频繁，请稍后再试', 429);
            }
        }
    }

    /**
     * 订单会话复用：同一订单在会话有效期内重复发起支付，返回已有 Checkout URL。
     * 金额（如管理员调整了费率/汇率）变化时缓存失效，回落到新建。
     */
    public function cachedSessionUrl(string $tradeNo, int $chargeAmount): ?string
    {
        $entry = Cache::get(self::P . 'sess:o:' . $tradeNo);
        if (
            is_array($entry)
            && ($entry['amount'] ?? null) === $chargeAmount
            && ($entry['expires_at'] ?? 0) > time() + 120
            && !empty($entry['url'])
        ) {
            return $entry['url'];
        }
        return null;
    }

    public function rememberSession(string $tradeNo, int $userId, string $sessionId, string $url, int $chargeAmount, int $expiresAt): void
    {
        $ttl = max(60, $expiresAt - time() - 60);
        Cache::put(self::P . 'sess:o:' . $tradeNo, [
            'id'         => $sessionId,
            'url'        => $url,
            'amount'     => $chargeAmount,
            'expires_at' => $expiresAt,
        ], $ttl);

        if ($userId > 0) {
            $key  = self::P . "sess:u:{$userId}";
            $list = Cache::get($key, []);
            $list[] = ['id' => $sessionId, 'trade_no' => $tradeNo];
            Cache::put($key, array_slice($list, -self::TRACK_LIMIT), 86400);
        }
    }

    /**
     * 记录一次支付失败（webhook payment_intent.payment_failed 驱动）。
     *
     * @return array{fails:int, global:int, blocked:bool, blockedNow:bool, pausedNow:bool}
     */
    public function recordFailure(int $userId, bool $isNewAccount): array
    {
        $blocked = $blockedNow = $pausedNow = false;
        $fails = 0;

        if ($userId > 0) {
            $fails = $this->hit(self::P . "fails:u:{$userId}", 86400);
            $threshold = $isNewAccount ? $this->failThresholdNew : $this->failThreshold;
            if ($threshold > 0 && $fails >= $threshold) {
                $blocked = true;
                if (!Cache::has(self::P . "block:u:{$userId}")) {
                    $blockedNow = true;
                }
                // 已封禁期间继续收到失败事件时刷新封禁时长
                Cache::put(self::P . "block:u:{$userId}", ['fails' => $fails, 'at' => time()], max(1, $this->blockHours) * 3600);
            }
        }

        $global = $this->hit(self::P . 'fails:g:' . date('YmdH'), 3900);
        if ($this->globalFailHourly > 0 && $global >= $this->globalFailHourly && !$this->isPaused()) {
            Cache::put(self::P . 'paused', time(), max(1, $this->pauseMinutes) * 60);
            $pausedNow = true;
        }

        return [
            'fails'      => $fails,
            'global'     => $global,
            'blocked'    => $blocked,
            'blockedNow' => $blockedNow,
            'pausedNow'  => $pausedNow,
        ];
    }

    /** 支付成功后清零该账户的失败计数，避免偶发拒付的正常用户被累积误封。不解除已生效的封禁。 */
    public function recordSuccess(int $userId): void
    {
        if ($userId > 0) {
            Cache::forget(self::P . "fails:u:{$userId}");
        }
    }

    /**
     * 取出该账户名下被跟踪的会话（供封禁时逐个过期），并清空跟踪与订单复用缓存。
     *
     * @return array<array{id:string, trade_no:string}>
     */
    public function pullUserSessions(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $key  = self::P . "sess:u:{$userId}";
        $list = Cache::get($key, []);
        Cache::forget($key);
        foreach ($list as $item) {
            if (!empty($item['trade_no'])) {
                Cache::forget(self::P . 'sess:o:' . $item['trade_no']);
            }
        }
        return $list;
    }

    public function isPaused(): bool
    {
        return $this->globalFailHourly > 0 && Cache::has(self::P . 'paused');
    }

    /** 原子自增：add 先建带 TTL 的键（键已存在时是 no-op），increment 保持 TTL。 */
    private function hit(string $key, int $ttlSeconds): int
    {
        Cache::add($key, 0, $ttlSeconds);
        return (int) Cache::increment($key);
    }
}
