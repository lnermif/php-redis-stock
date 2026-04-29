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
    public const CODE_ERR_INSUFFICIENT = RedisConstants::CODE_ERR_INSUFFICIENT;
    public const CODE_ERR_NOT_EXISTS = RedisConstants::CODE_ERR_NOT_EXISTS;

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
        redis.call('incrby', sales_amount_key, amount)
        
        -- 6. 更新排行榜
        redis.call('zincrby', leaderboard_count_key, quantity, sku)
        redis.call('zincrby', leaderboard_amount_key, amount, sku)
        
        -- 7. 写入订单防重标记
        redis.call('setex', order_key, {{ORDER_TTL}}, '1')
        
        local total_sales = redis.call('get', sales_count_key)
        return {CODE_SUCCESS, total_sales or 0}
LUA,
        'record_purchase_with_stock' => <<<'LUA'
        local stock_key = KEYS[1]
        local soldout_key = KEYS[2]
        local user_bought_key = KEYS[3]
        local user_set_key = KEYS[4]
        local order_key = KEYS[5]
        local sales_count_key = KEYS[6]
        local sales_amount_key = KEYS[7]
        local leaderboard_count_key = KEYS[8]
        local leaderboard_amount_key = KEYS[9]
        
        local user_id = ARGV[1]
        local quantity = tonumber(ARGV[2])
        local amount = tonumber(ARGV[3])
        local limit_per_user = tonumber(ARGV[4])
        local sku = ARGV[5]
        
        local CODE_ALREADY_PROCESSED = tonumber(ARGV[6])
        local CODE_LIMIT_EXCEEDED = tonumber(ARGV[7])
        local CODE_SUCCESS = tonumber(ARGV[8])
        local CODE_ERR_INSUFFICIENT = tonumber(ARGV[9])
        local CODE_ERR_NOT_EXISTS = tonumber(ARGV[10])
        
        -- 1. 幂等性检查
        if redis.call('exists', order_key) == 1 then
            return {CODE_ALREADY_PROCESSED, 0}
        end
        
        -- 2. 库存检查
        local stock = redis.call('get', stock_key)
        if stock == false then
            return {CODE_ERR_NOT_EXISTS, 0}
        end
        stock = tonumber(stock)
        if stock < quantity then
            return {CODE_ERR_INSUFFICIENT, stock}
        end
        
        -- 3. 限购检查
        if limit_per_user > 0 then
            local bought = redis.call('hget', user_bought_key, user_id)
            bought = bought and tonumber(bought) or 0
            if bought + quantity > limit_per_user then
                local remaining = limit_per_user - bought
                return {CODE_LIMIT_EXCEEDED, remaining}
            end
        end
        
        -- 4. 扣减库存
        local remain = redis.call('decrby', stock_key, quantity)
        if remain == 0 then
            local stock_ttl = redis.call('ttl', stock_key)
            if stock_ttl > 0 then
                redis.call('setex', soldout_key, stock_ttl, 1)
            elseif stock_ttl == -1 then
                redis.call('set', soldout_key, 1)
            end
        end
        
        -- 5. 记录用户购买数量
        redis.call('hincrby', user_bought_key, user_id, quantity)
        redis.call('expire', user_bought_key, {{USER_RECORD_TTL}})
        
        -- 6. 记录用户购买过的 SKU 集合
        redis.call('sadd', user_set_key, sku)
        redis.call('expire', user_set_key, {{USER_RECORD_TTL}})
        
        -- 7. 增加总销量和总销售额
        redis.call('incrby', sales_count_key, quantity)
        redis.call('incrby', sales_amount_key, amount)
        
        -- 8. 更新排行榜
        redis.call('zincrby', leaderboard_count_key, quantity, sku)
        redis.call('zincrby', leaderboard_amount_key, amount, sku)
        
        -- 9. 写入订单防重标记
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
        return parent::prepareScript($scriptName, $script);
    }

    protected function getPlaceholders(): array
    {
        return [
            RedisConstants::LUA_USER_RECORD_TTL => RedisConstants::DEFAULT_USER_RECORD_TTL,
            RedisConstants::LUA_ORDER_TTL => RedisConstants::DEFAULT_ORDER_TTL,
        ];
    }

    /**
     * 添加销售记录（不扣减库存）
     *
     * @param string $sku 商品SKU
     * @param string $userId 用户ID
     * @param int $quantity 购买数量
     * @param int $amount 金额（单位：分）
     * @param string $orderId 订单ID
     * @param int $limitPerUser 限购数量，0表示无限制
     * @return array
     */
    public function recordPurchase(
        string $sku,
        string $userId,
        int    $quantity,
        int    $amount,        // 改为 int，单位：分
        string $orderId,
        int    $limitPerUser = 0
    ): array
    {
        if ($quantity <= 0) {
            return ['code' => self::CODE_ERR_INVALID_QUANTITY, 'message' => '数量无效', 'total_sales' => null];
        }
        if ($amount < 0) {
            return ['code' => self::CODE_ERR_INVALID_AMOUNT, 'message' => '金额无效', 'total_sales' => null];
        }

        // 直接使用 $amount（分），无需转换
        $amountInCents = $amount;

        // --- 集群兼容核心逻辑 ---
        // 我们利用商品 SKU 作为 Hash Tag。
        // 假设 $this->keyPrefix 为 "{product:stock}:"
        // 所有的 Key 都会变成 "{product:stock}:SKU123:order:ORDER456" 等格式
        // 这样 Redis 只会根据 "product:stock" 来分配 Slot，确保所有 Key 在同一节点。
        $tag = $this->keyPrefix;

        // 1. 商品相关 Key
        $userBoughtKey = $tag . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
        $salesCountKey = $tag . $sku . RedisConstants::SALES_COUNT_SUFFIX;
        $salesAmountKey = $tag . $sku . RedisConstants::SALES_AMOUNT_SUFFIX;

        // 2. 外部关联 Key（强制注入 $tag 以解决 CROSSSLOT）
        $userSetKey = $tag . 'user:' . $userId . ':purchased';
        $orderKey = $tag . 'order:' . $orderId;

        // 3. 排行榜 Key（同样需要注入 $tag）
        $leaderCountKey = $tag . RedisConstants::LEADERBOARD_COUNT_SUFFIX;
        $leaderAmountKey = $tag . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX;

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
            $amountInCents,
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
            case self::CODE_ERR_INSUFFICIENT:
                return "库存不足，剩余 {$extra} 件";
            case self::CODE_ERR_NOT_EXISTS:
                return '商品库存未初始化';
            default:
                return '未知错误';
        }
    }

    /**
     * 原子性扣减库存并记录销售（推荐用于秒杀场景）
     * 将库存扣减和销售记录放在同一个 Lua 脚本中，确保数据一致性
     *
     * @param string $sku 商品SKU
     * @param string $userId 用户ID
     * @param int $quantity 购买数量
     * @param float $amount 金额（单位：分）
     * @param string $orderId 订单ID
     * @param int $limitPerUser 限购数量，0表示无限制
     * @return array
     */
    public function recordPurchaseWithStock(
        string $sku,
        string $userId,
        int    $quantity,
        float  $amount,
        string $orderId,
        int    $limitPerUser = 0
    ): array
    {
        if ($quantity <= 0) {
            return ['code' => self::CODE_ERR_INVALID_QUANTITY, 'message' => '数量无效', 'remain' => null];
        }
        if (!is_numeric($amount) || $amount < 0) {
            return ['code' => self::CODE_ERR_INVALID_AMOUNT, 'message' => '金额无效', 'remain' => null];
        }
        $amountInCents = $amount;

        $tag = $this->keyPrefix;

        $stockKey = $tag . $sku;
        $soldOutKey = $tag . $sku . RedisConstants::SOLD_OUT_SUFFIX;
        $userBoughtKey = $tag . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
        $salesCountKey = $tag . $sku . RedisConstants::SALES_COUNT_SUFFIX;
        $salesAmountKey = $tag . $sku . RedisConstants::SALES_AMOUNT_SUFFIX;
        $userSetKey = $tag . 'user:' . $userId . ':purchased';
        $orderKey = $tag . 'order:' . $orderId;
        $leaderCountKey = $tag . RedisConstants::LEADERBOARD_COUNT_SUFFIX;
        $leaderAmountKey = $tag . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX;

        $keys = [
            $stockKey,           // KEYS[1]
            $soldOutKey,         // KEYS[2]
            $userBoughtKey,      // KEYS[3]
            $userSetKey,         // KEYS[4]
            $orderKey,           // KEYS[5]
            $salesCountKey,      // KEYS[6]
            $salesAmountKey,     // KEYS[7]
            $leaderCountKey,     // KEYS[8]
            $leaderAmountKey     // KEYS[9]
        ];

        $args = [
            $userId,
            $quantity,
            $amountInCents,
            $limitPerUser,
            $sku,
            (int)self::CODE_ERR_ALREADY_PROCESSED,
            (int)self::CODE_ERR_LIMIT_EXCEEDED,
            (int)self::CODE_SUCCESS,
            (int)self::CODE_ERR_INSUFFICIENT,
            (int)self::CODE_ERR_NOT_EXISTS
        ];

        try {
            $result = $this->execLuaWithRetry('record_purchase_with_stock', $keys, $args);
            $code = (int)$result[0];
            $extra = $result[1] ?? 0;

            return [
                'code' => $code,
                'message' => $this->getMessageByCode($code, $extra),
                'total_sales' => ($code === self::CODE_SUCCESS) ? (int)$extra : null,
                'remain' => ($code === self::CODE_ERR_INSUFFICIENT) ? (int)$extra : null,
                'remaining_limit' => ($code === self::CODE_ERR_LIMIT_EXCEEDED) ? (int)$extra : null
            ];
        } catch (\Exception $e) {
            return [
                'code' => self::CODE_ERR_REDIS_UNAVAILABLE,
                'message' => 'Redis集群写入异常: ' . $e->getMessage(),
                'remain' => null
            ];
        }
    }

    /**
     * 获取用户购买记录
     * @param string $userId
     * @return array ['code' => int, 'data' => ['sku' => int]]
     */
    public function getUserPurchases(string $userId): array
    {
        try {
            $userSetKey = $this->keyPrefix . RedisConstants::USER_PURCHASED_SET_PREFIX . $userId . ':purchased';
            $skus = $this->readWithRetry(function ($redis) use ($userSetKey) {
                return $redis->sMembers($userSetKey);
            });

            if (!is_array($skus) || empty($skus)) {
                return ['code' => self::CODE_SUCCESS, 'data' => []];
            }

            $counts = $this->pipelineWithRetry(function ($redis) use ($skus, $userId) {
                $pipe = $redis->multi(\Redis::PIPELINE);
                foreach ($skus as $sku) {
                    $hashKey = $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
                    $pipe->hGet($hashKey, $userId);
                }
                return $pipe->exec();
            });

            if (!is_array($counts) || count($counts) !== count($skus)) {
                $this->log(LogLevel::ERROR, 'Pipeline exec returned invalid results for user purchases');
                return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => []];
            }

            $result = [];
            foreach ($skus as $index => $sku) {
                $val = isset($counts[$index]) ? (int)$counts[$index] : 0;
                if ($val > 0) {
                    $result[$sku] = $val;
                }
            }
            return ['code' => self::CODE_SUCCESS, 'data' => $result];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => []];
        }
    }

    /**
     * 获取商品总销量
     * @param string $sku
     * @return array ['code' => int, 'data' => int]
     */
    public function getSalesCount(string $sku): array
    {
        try {
            $key = $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX;
            $val = $this->readWithRetry(function ($redis) use ($key) {
                return $redis->get($key);
            });
            return ['code' => self::CODE_SUCCESS, 'data' => $val === false ? 0 : (int)$val];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => 0];
        }
    }

    /**
     * 获取商品总销售额（单位：分）
     * @param string $sku
     * @return array ['code' => int, 'data' => int] data 单位：分
     */
    public function getSalesAmount(string $sku): array
    {
        try {
            $key = $this->keyPrefix . $sku . RedisConstants::SALES_AMOUNT_SUFFIX;
            $val = $this->readWithRetry(function ($redis) use ($key) {
                return $redis->get($key);
            });
            return ['code' => self::CODE_SUCCESS, 'data' => $val === false ? 0 : (int)$val];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => 0];
        }
    }

    /**
     * 批量获取商品总销量
     * @param array $skus
     * @return array ['code' => int, 'data' => ['sku' => int]]
     */
    public function getMultipleSalesCounts(array $skus): array
    {
        if (empty($skus)) {
            return ['code' => self::CODE_SUCCESS, 'data' => []];
        }
        try {
            $keys = array_map(function ($sku) {
                return $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX;
            }, $skus);
            $values = $this->readWithRetry(function ($redis) use ($keys) {
                return $redis->mget($keys);
            });
            $result = [];
            foreach ($skus as $i => $sku) {
                $result[$sku] = ($values[$i] === false) ? 0 : (int)$values[$i];
            }
            return ['code' => self::CODE_SUCCESS, 'data' => $result];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => []];
        }
    }

    /**
     * 获取销量排行榜
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array ['code' => int, 'data' => array]
     */
    public function getSalesLeaderboard(int $start = 0, int $stop = 9, bool $withScores = true): array
    {
        try {
            $key = $this->keyPrefix . RedisConstants::LEADERBOARD_COUNT_SUFFIX;
            $data = $this->readWithRetry(function ($redis) use ($key, $start, $stop, $withScores) {
                return $withScores ? $redis->zRevRange($key, $start, $stop, true) : $redis->zRevRange($key, $start, $stop);
            });
            return ['code' => self::CODE_SUCCESS, 'data' => $data];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => []];
        }
    }

    /**
     * 获取销售额排行榜
     * @param int $start
     * @param int $stop
     * @param bool $withScores
     * @return array ['code' => int, 'data' => array] 若 withScores=true，分数单位为分
     */
    public function getAmountLeaderboard(int $start = 0, int $stop = 9, bool $withScores = true): array
    {
        try {
            $key = $this->keyPrefix . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX;
            $data = $this->readWithRetry(function ($redis) use ($key, $start, $stop, $withScores) {
                return $withScores ? $redis->zRevRange($key, $start, $stop, true) : $redis->zRevRange($key, $start, $stop);
            });
            // 分数已经是分，直接返回，不再转换
            return ['code' => self::CODE_SUCCESS, 'data' => $data];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => []];
        }
    }

    /**
     * 检查订单是否已处理
     * @param string $orderId
     * @return array ['code' => int, 'data' => bool]
     */
    public function isOrderProcessed(string $orderId): array
    {
        try {
            $key = $this->keyPrefix . RedisConstants::ORDER_IDEMPOTENT_PREFIX . $orderId;
            $result = $this->readWithRetry(function ($redis) use ($key) {
                return $redis->exists($key);
            });
            return ['code' => self::CODE_SUCCESS, 'data' => (bool)$result];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => false];
        }
    }

    /**
     * 获取用户某 SKU 的购买数量
     * @param string $sku
     * @param string $userId
     * @return array ['code' => int, 'data' => int]
     */
    public function getUserPurchaseCount(string $sku, string $userId): array
    {
        try {
            $hashKey = $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX;
            $count = $this->readWithRetry(function ($redis) use ($hashKey, $userId) {
                return $redis->hGet($hashKey, $userId);
            });
            return ['code' => self::CODE_SUCCESS, 'data' => $count === false ? 0 : (int)$count];
        } catch (\RuntimeException $e) {
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'data' => 0];
        }
    }

    /**
     * 获取用户某 SKU 的剩余限购数量
     * @param string $sku
     * @param string $userId
     * @param int $limit
     * @return array ['code' => int, 'data' => int]
     */
    public function getRemainingLimit(string $sku, string $userId, int $limit): array
    {
        if ($limit <= 0) {
            return ['code' => self::CODE_SUCCESS, 'data' => PHP_INT_MAX];
        }
        $boughtResult = $this->getUserPurchaseCount($sku, $userId);
        if ($boughtResult['code'] !== self::CODE_SUCCESS) {
            return $boughtResult;
        }
        $bought = $boughtResult['data'];
        return ['code' => self::CODE_SUCCESS, 'data' => max(0, $limit - $bought)];
    }

    /**
     * 清除某 SKU 的销量数据
     * @param string $sku
     * @return array ['code' => int, 'deleted' => int]
     */
    public function clearSalesData(string $sku): array
    {
        try {
            $keysToDelete = [
                $this->keyPrefix . $sku . RedisConstants::USER_BOUGHT_HASH_SUFFIX,
                $this->keyPrefix . $sku . RedisConstants::SALES_COUNT_SUFFIX,
                $this->keyPrefix . $sku . RedisConstants::SALES_AMOUNT_SUFFIX,
            ];
            $deleted = 0;
            foreach ($keysToDelete as $key) {
                $deleted += $this->writeWithRetry(function ($redis) use ($key) {
                    return $redis->del($key);
                });
            }
            $this->writeWithRetry(function ($redis) use ($sku) {
                $redis->zRem($this->keyPrefix . RedisConstants::LEADERBOARD_COUNT_SUFFIX, $sku);
                $redis->zRem($this->keyPrefix . RedisConstants::LEADERBOARD_AMOUNT_SUFFIX, $sku);
            });
            $this->log(LogLevel::INFO, 'Sales data cleared', ['sku' => $sku, 'deleted' => $deleted]);
            return ['code' => self::CODE_SUCCESS, 'deleted' => $deleted];
        } catch (\RuntimeException $e) {
            $this->log(LogLevel::ERROR, 'Clear sales data failed', ['sku' => $sku, 'error' => $e->getMessage()]);
            return ['code' => self::CODE_ERR_REDIS_UNAVAILABLE, 'deleted' => 0];
        }
    }
}