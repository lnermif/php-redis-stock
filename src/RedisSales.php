<?php

namespace Nermif;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RedisSales extends AbstractRedisManager
{
    public const CODE_SUCCESS = RedisConstants::CODE_SUCCESS;
    public const CODE_ERR_LIMIT_EXCEEDED = RedisConstants::CODE_ERR_LIMIT_EXCEEDED;
    public const CODE_ERR_ALREADY_PROCESSED = RedisConstants::CODE_ERR_ALREADY_PROCESSED;
    public const CODE_ERR_INVALID_QUANTITY = RedisConstants::CODE_ERR_INVALID_QUANTITY;
    public const CODE_ERR_REDIS_UNAVAILABLE = RedisConstants::CODE_ERR_REDIS_UNAVAILABLE;
    public const CODE_ERR_INVALID_AMOUNT = RedisConstants::CODE_ERR_INVALID_AMOUNT;

    /**
     * 定义 Lua 脚本模板
     * 修改点：将所有 Redis Key 放入 KEYS 数组，严格兼容 Redis Cluster
     */
    private const LUA_SCRIPTS = [
        'record_purchase' => <<<'LUA'
        local user_bought_key = KEYS[1]
        local user_set_key = KEYS[2]
        local order_key = KEYS[3]
        local sales_count_key = KEYS[4]
        local sales_amount_key = KEYS[5]
        local leaderboard_count_key = KEYS[6]
        local leaderboard_amount_key = KEYS[7]
        
        local user_id = ARGV[1]
        local quantity = tonumber(ARGV[2])
        local amount = tonumber(ARGV[3])
        local limit_per_user = tonumber(ARGV[4])
        local sku = ARGV[5]
        
        local CODE_ALREADY_PROCESSED = tonumber(ARGV[6])
        local CODE_LIMIT_EXCEEDED = tonumber(ARGV[7])
        local CODE_SUCCESS = tonumber(ARGV[8])
        
        -- 1. 幂等性检查
        if redis.call('exists', order_key) == 1 then
            return {CODE_ALREADY_PROCESSED, 0}
        end
        
        -- 2. 限购检查
        if limit_per_user > 0 then
            local bought = redis.call('hget', user_bought_key, user_id)
            bought = bought and tonumber(bought) or 0
            if bought + quantity > limit_per_user then
                local remaining = limit_per_user - bought
                return {CODE_LIMIT_EXCEEDED, remaining}
            end
        end
        
        -- 3. 记录用户购买数量
        redis.call('hincrby', user_bought_key, user_id, quantity)
        redis.call('expire', user_bought_key, {{USER_RECORD_TTL}})
        
        -- 4. 记录用户购买过的 SKU 集合
        redis.call('sadd', user_set_key, sku)
        redis.call('expire', user_set_key, {{USER_RECORD_TTL}})
        
        -- 5. 增加总销量和总销售额
        redis.call('incrby', sales_count_key, quantity)
        redis.call('incrbyfloat', sales_amount_key, amount)
        
        -- 6. 更新排行榜
        redis.call('zincrby', leaderboard_count_key, quantity, sku)
        redis.call('zincrby', leaderboard_amount_key, amount, sku)
        
        -- 7. 写入订单防重标记
        redis.call('setex', order_key, {{ORDER_TTL}}, '1')
        
        local total_sales = redis.call('get', sales_count_key)
        return {CODE_SUCCESS, total_sales or 0}
LUA
    ];

    public function __construct(
        \Redis           $redis,
        string           $keyPrefix = '{product:stock}:',
        ?LoggerInterface $logger = null,
        ?int             $maxRetries = null
    )
    {
        parent::__construct($redis, $keyPrefix, $logger, $maxRetries);
    }

    protected function getLuaScripts(): array
    {
        return self::LUA_SCRIPTS;
    }

    protected function prepareScript(string $scriptName, string $script): string
    {
        return str_replace(
            [RedisConstants::LUA_USER_RECORD_TTL, RedisConstants::LUA_ORDER_TTL],
            [RedisConstants::DEFAULT_USER_RECORD_TTL, RedisConstants::DEFAULT_ORDER_TTL],
            $script
        );
    }

    /**
     * 添加销售记录
     * @param string $sku
     * @param string $userId
     * @param int $quantity
     * @param float $amount
     * @param string $orderId
     * @param int $limitPerUser
     * @return array
     */
    public function recordPurchase(
        string $sku,
        string $userId,
        int    $quantity,
        float  $amount,
        string $orderId,
        int    $limitPerUser = 0
    ): array
    {
        if ($quantity <= 0) {
            return ['code' => self::CODE_ERR_INVALID_QUANTITY, 'message' => '数量无效', 'total_sales' => null];
        }

        // --- 集群兼容核心逻辑 ---
        // 我们利用商品 SKU 作为 Hash Tag。
        // 假设 $this->keyPrefix 为 "{product:stock}:"
        // 所有的 Key 都会变成 "{product:stock}:SKU123:order:ORDER456" 等格式
        // 这样 Redis 只会根据 "product:stock" 来分配 Slot，确保所有 Key 在同一节点。
        $tag = $this->keyPrefix;

        // 1. 商品相关 Key
        $userBoughtKey  = $tag . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
        $salesCountKey  = $tag . $sku . RedisConstants::SALES_COUNT_SUFFIX;
        $salesAmountKey = $tag . $sku . RedisConstants::SALES_AMOUNT_SUFFIX;

        // 2. 外部关联 Key（强制注入 $tag 以解决 CROSSSLOT）
        $userSetKey     = $tag . 'user:' . $userId . ':purchased';
        $orderKey       = $tag . 'order:' . $orderId;

        // 3. 排行榜 Key（同样需要注入 $tag）
        $leaderCountKey = $tag . RedisConstants::LEADERBOARD_COUNT_SUFFIX;
        $leaderAmountKey= $tag . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX;

        $keys = [
            $userBoughtKey,   // KEYS[1]
            $userSetKey,      // KEYS[2]
            $orderKey,        // KEYS[3]
            $salesCountKey,   // KEYS[4]
            $salesAmountKey,  // KEYS[5]
            $leaderCountKey,  // KEYS[6]
            $leaderAmountKey  // KEYS[7]
        ];

        $args = [
            $userId,
            $quantity,
            $amount,
            $limitPerUser,
            $sku,
            (int)self::CODE_ERR_ALREADY_PROCESSED,
            (int)self::CODE_ERR_LIMIT_EXCEEDED,
            (int)self::CODE_SUCCESS
        ];

        try {
            $result = $this->execLuaWithRetry('record_purchase', $keys, $args);
            $code = (int)$result[0];
            $extra = $result[1] ?? 0;

            return [
                'code' => $code,
                'message' => $this->getMessageByCode($code, $extra),
                'total_sales' => ($code === self::CODE_SUCCESS) ? (int)$extra : null,
                'remaining_limit' => ($code === self::CODE_ERR_LIMIT_EXCEEDED) ? (int)$extra : null
            ];
        } catch (\Exception $e) {
            return [
                'code' => self::CODE_ERR_REDIS_UNAVAILABLE,
                'message' => 'Redis集群写入异常: ' . $e->getMessage(),
                'total_sales' => null
            ];
        }
    }

    /**
     * 根据错误码获取错误信息
     * @param int $code
     * @param $extra
     * @return string
     */
    private function getMessageByCode(int $code, $extra): string
    {
        switch ($code) {
            case self::CODE_SUCCESS:
                return '购买记录成功';
            case self::CODE_ERR_ALREADY_PROCESSED:
                return '订单已处理，请勿重复提交';
            case self::CODE_ERR_LIMIT_EXCEEDED:
                return "超过限购数量，还可购买 {$extra} 件";
            case self::CODE_ERR_INVALID_QUANTITY:
                return '购买数量无效';
            case self::CODE_ERR_INVALID_AMOUNT:
                return '金额无效';
            default:
                return '未知错误';
        }
    }

    /**
     * 获取用户购买记录
     * @param string $userId
     * @return array
     */
    public function getUserPurchases(string $userId): array
    {
        // 注意：查询时也必须带上 $this->keyPrefix 才能找到集群中的 Key
        $userSetKey = $this->keyPrefix . RedisConstants::USER_PURCHASED_SET_PREFIX . $userId . ':purchased';
        $skus = $this->redis->sMembers($userSetKey);

        if (!is_array($skus) || empty($skus)) {
            return [];
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($skus as $sku) {
            $hashKey = $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
            $pipe->hGet($hashKey, $userId);
        }

        $counts = $pipe->exec();

        // 2. 严格校验 Pipeline 结果
        if (!is_array($counts) || count($counts) !== count($skus)) {
            return [];
        }

        $result = [];
        foreach ($skus as $index => $sku) {
            $val = isset($counts[$index]) ? (int)$counts[$index] : 0;
            if ($val > 0) {
                $result[$sku] = $val;
            }
        }
        return $result;
    }

    /**
     * 获取商品总销量
     * @param string $sku
     * @return int
     */
    public function getSalesCount(string $sku): int
    {
        $key = $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX;
        $val = $this->redis->get($key);
        return $val === false ? 0 : (int)$val;
    }

    /**
     * 获取商品总销售额
     * @param string $sku
     * @return float
     */
    public function getSalesAmount(string $sku): float
    {
        $key = $this->keyPrefix . $sku . RedisConstants::SALES_AMOUNT_SUFFIX;
        $val = $this->redis->get($key);
        return $val === false ? 0.0 : (float)$val;
    }

    /**
     * 批量获取商品总销量
     * @param array $skus
     * @return array
     */
    public function getMultipleSalesCounts(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }
        $keys = array_map(function ($sku) {
            return $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX;
        }, $skus);
        $values = $this->redis->mget($keys);
        $result = [];
        foreach ($skus as $i => $sku) {
            $result[$sku] = ($values[$i] === false) ? 0 : (int)$values[$i];
        }
        return $result;
    }

    /**
     * 获取销量排行榜
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array
     */
    public function getSalesLeaderboard(int $start = 0, int $stop = 9, bool $withScores = true): array
    {
        $key = $this->keyPrefix . RedisConstants::LEADERBOARD_COUNT_SUFFIX;
        return $withScores ? $this->redis->zRevRange($key, $start, $stop, true) : $this->redis->zRevRange($key, $start, $stop);
    }

    /**
     * 获取销售额排行榜
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array
     */
    public function getAmountLeaderboard(int $start = 0, int $stop = 9, bool $withScores = true): array
    {
        $key = $this->keyPrefix . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX;
        return $withScores ? $this->redis->zRevRange($key, $start, $stop, true) : $this->redis->zRevRange($key, $start, $stop);
    }

    /**
     * 检查订单是否已处理
     * @param string $orderId
     * @return bool
     */
    public function isOrderProcessed(string $orderId): bool
    {
        $key = $this->keyPrefix . RedisConstants::ORDER_IDEMPOTENT_PREFIX . $orderId;
        return (bool)$this->redis->exists($key);
    }

    /**
     * 获取用户某 SKU 的购买数量
     * @param string $sku
     * @param string $userId
     * @return int
     */
    public function getUserPurchaseCount(string $sku, string $userId): int
    {
        $hashKey = $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
        $count = $this->redis->hGet($hashKey, $userId);
        return $count === false ? 0 : (int)$count;
    }

    /**
     * 获取用户某 SKU 的剩余限购数量
     * @param string $sku
     * @param string $userId
     * @param int $limit
     * @return int
     */
    public function getRemainingLimit(string $sku, string $userId, int $limit): int
    {
        if ($limit <= 0) {
            return PHP_INT_MAX; // 兼容 PHP 7.x
        }
        $bought = $this->getUserPurchaseCount($sku, $userId);
        return max(0, $limit - $bought);
    }

    /**
     * 清除某 SKU 的销量数据
     * @param string $sku
     * @return int
     */
    public function clearSalesData(string $sku): int
    {
        $keysToDelete = [
            $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX,
            $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX,
            $this->keyPrefix . $sku . RedisConstants::SALES_AMOUNT_SUFFIX,
        ];
        $deleted = 0;
        foreach ($keysToDelete as $key) {
            $deleted += $this->redis->del($key);
        }
        $this->redis->zRem($this->keyPrefix . RedisConstants::LEADERBOARD_COUNT_SUFFIX, $sku);
        $this->redis->zRem($this->keyPrefix . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX, $sku);
        $this->log(LogLevel::INFO, 'Sales data cleared', ['sku' => $sku, 'deleted' => $deleted]);
        return $deleted;
    }
}