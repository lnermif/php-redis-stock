<?php

namespace Nermif;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Redis库存管理
 *
 *   - 原子性扣减，杜绝超卖
 *   - Redis 集群兼容（使用 Hash Tag）
 *   - 售罄标记快速拦截无效请求
 *   - 支持库存 TTL 自动过期
 *   - 瞬态故障自动重试（指数退避）
 *   - 可注入日志记录器便于监控
 *
 * 并发安全性：
 *   - 所有写操作均通过 Redis Lua 脚本原子执行
 *   - 使用 Hash Tag（如 {seckill:stock}:）确保集群模式下 Key 落在同一 Slot
 */
class RedisStock extends AbstractRedisManager
{
    // -------------------------------------------------------------------------
    // 返回码常量（业务层可根据这些常量判断操作结果）
    // -------------------------------------------------------------------------
    public const CODE_SUCCESS = RedisConstants::CODE_SUCCESS;
    public const CODE_ERR_INSUFFICIENT = RedisConstants::CODE_ERR_INSUFFICIENT;   // 库存不足
    public const CODE_ERR_NOT_EXISTS = RedisConstants::CODE_ERR_NOT_EXISTS;   // 库存未初始化
    public const CODE_ERR_INVALID_QUANTITY = RedisConstants::CODE_ERR_INVALID_QUANTITY;   // 数量非法（≤0）
    public const CODE_ERR_REDIS_UNAVAILABLE = RedisConstants::CODE_ERR_REDIS_UNAVAILABLE;  // Redis 不可用


    /**
     * 定义 Lua 脚本模板
     * 注意：为了保持降级时的兼容性，内部不使用 PHP 变量，
     * 而是保持纯粹的 Lua 语法。
     */
    private const LUA_SCRIPTS = [
        'init' => <<<LUA
                local ttl = tonumber(ARGV[#ARGV]) or 0
                local count = 0
                local soldout_suffix = '{{SOLD_OUT_SUFFIX}}'
                for i = 1, #KEYS do
                    local key = KEYS[i]
                    local qty = tonumber(ARGV[i]) or 0
                    if redis.call('exists', key) == 0 then
                        redis.call('set', key, qty)
                        redis.call('del', key .. soldout_suffix)
                        if qty <= 0 then
                            redis.call('set', key .. soldout_suffix, 1)
                            if ttl > 0 then
                                redis.call('expire', key .. soldout_suffix, ttl)
                            end
                        end
                        if ttl > 0 then
                            redis.call('expire', key, ttl)
                        end
                        count = count + 1
                    end
                end
                return count
LUA,
        'decr' => <<<LUA
                local key = KEYS[1]
                local soldout_key = KEYS[2]
                local qty = tonumber(ARGV[1])
                local stock = redis.call('get', key)
                if stock == false then
                    return {tonumber(ARGV[2]), 0}
                end
                stock = tonumber(stock)
                if stock < qty then
                    return {tonumber(ARGV[3]), stock}
                end
                local remain = redis.call('decrby', key, qty)
                if remain == 0 then
                    local stock_ttl = redis.call('ttl', key)
                    if stock_ttl > 0 then
                        redis.call('setex', soldout_key, stock_ttl, 1)
                    elseif stock_ttl == -1 then
                        redis.call('set', soldout_key, 1)
                    end
                end
                return {tonumber(ARGV[4]), remain}
LUA,
        'incr' => <<<LUA
                local key = KEYS[1]
                local soldout_key = KEYS[2]
                local qty = tonumber(ARGV[1])
                if redis.call('exists', key) == 0 then
                    return tonumber(ARGV[2])
                end
                local remain = redis.call('incrby', key, qty)
                if remain > 0 then
                    redis.call('del', soldout_key)
                end
                return remain
LUA,
        'decr_multi' => <<<LUA
                local soldout_suffix = '{{SOLD_OUT_SUFFIX}}'
                -- 第一遍：全量校验
                for i = 1, #KEYS do
                    local key = KEYS[i]
                    local qty = tonumber(ARGV[i])
                    local stock = redis.call('get', key)
                    if stock == false then
                        return {tonumber(ARGV[#ARGV - 1]), i, 0}
                    end
                    stock = tonumber(stock)
                    if stock < qty then
                        return {tonumber(ARGV[#ARGV]), i, stock}
                    end
                end
                local remains = {}
                for i = 1, #KEYS do
                    local key = KEYS[i]
                    local qty = tonumber(ARGV[i])
                    local remain = redis.call('decrby', key, qty)
                    remains[i] = remain
                    if remain == 0 then
                        local stock_ttl = redis.call('ttl', key)
                        if stock_ttl > 0 then
                            redis.call('setex', key .. soldout_suffix, stock_ttl, 1)
                        elseif stock_ttl == -1 then
                            redis.call('set', key .. soldout_suffix, 1)
                        end
                    end
                end
                return remains
LUA,
        'repair' => <<<LUA
        local key = KEYS[1]
        local soldout_key = KEYS[2]
        
        local stock_val = redis.call('get', key)
        local has_soldout = redis.call('exists', soldout_key)
        
        -- 场景 1：主库存 Key 彻底消失了
        if stock_val == false then
            if has_soldout == 1 then
                -- 孤立的售罄标记，清理掉（防止主 Key 消失后标记残留）
                redis.call('del', soldout_key)
                return 4 
            end
            return 0 -- 全部不存在，正常状态
        end
        
        local stock = tonumber(stock_val)
        local stock_ttl = redis.call('ttl', key)
        
        -- 场景 2：有库存但有售罄标记
        if stock > 0 and has_soldout == 1 then
            redis.call('del', soldout_key)
            return 1
        end
        
        -- 场景 3：无库存但无售罄标记
        if stock <= 0 and has_soldout == 0 then
            if stock_ttl > 0 then
                redis.call('setex', soldout_key, stock_ttl, 1)
                return 2
            elseif stock_ttl == -1 then
                redis.call('set', soldout_key, 1)
                return 2
            end
        end
        
        return 3 -- 状态一致
LUA,
    ];

    /**
     * 构造函数
     *
     * @param \Redis $redis Redis 连接实例（需已 connect）
     * @param string $keyPrefix Key 前缀，建议使用 Hash Tag，如 '{product:stock}:'
     * @param LoggerInterface|null $logger PSR-3 日志实例
     *
     * 注意：如果业务涉及 decrMultiStocks（批量扣减），所有参与扣减的 SKU 必须共享相同的 Hash Tag（例如 {order}:），否则 Redis 集群会抛出 CROSSSLOT 错误
     */
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
            RedisConstants::LUA_PLACEHOLDER_SOLD_OUT_SUFFIX,
            RedisConstants::SOLD_OUT_SUFFIX,
            $script
        );
    }

    /**
     * 获取主库存 Key
     *
     * @param string $sku SKU
     * @return string
     */
    private function getStockKey(string $sku): string
    {
        return $this->keyPrefix . $sku;
    }

    /**
     * 获取售罄标记 Key
     *
     * @param string $sku
     * @return string
     */
    private function getSoldOutKey(string $sku): string
    {
        // 这里也可以直接调用 getStockKey 再拼接，减少 prefix 的访问
        return $this->getStockKey($sku) . RedisConstants::SOLD_OUT_SUFFIX;
    }

    /**
     * 初始化库存（支持 TTL）
     * 防止重复并发覆盖、幂等性保护
     *
     * @param array $stocks 关联数组 ['sku' => 库存数量]
     * @param int $ttl 过期时间（秒），0 表示永不过期
     * @return int 成功初始化（此前不存在）的 SKU 数量
     */
    public function initStocks(array $stocks, int $ttl = 0): int
    {
        if (empty($stocks)) {
            return 0;
        }

        $keys = [];
        $args = [];
        foreach ($stocks as $sku => $stock) {
            if ($stock < 0) {
                throw new \InvalidArgumentException('Stock must be greater than or equal to 0');
            }
            $keys[] = $this->keyPrefix . $sku;
            $args[] = max(0, (int)$stock);
        }
        $args[] = max(0, $ttl);  // TTL 作为最后一个参数

        $count = $this->execLuaWithRetry('init', $keys, $args);
        $this->log(LogLevel::INFO, 'Stocks initialized', [
            'count' => $count,
            'ttl' => $ttl
        ]);
        return (int)$count;
    }

    /**
     * 获取单个商品库存及售罄状态（带重试）
     *
     * @param string $sku
     * @return array ['stock' => int|null, 'soldOut' => bool]
     */
    public function getStock(string $sku): array
    {
        $stockKey = $this->keyPrefix . $sku;
        $soldOutKey = $stockKey . RedisConstants::SOLD_OUT_SUFFIX;

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                $pipe = $this->redis->multi(\Redis::PIPELINE);
                $pipe->get($stockKey);
                $pipe->exists($soldOutKey);
                $results = $pipe->exec();

                if ($results === false || count($results) < 2) {
                    throw new \RedisException('Pipeline exec returned invalid results');
                }

                $stock = ($results[0] === false) ? null : (int)$results[0];
                $soldOut = (bool)($results[1] ?? 0);
                return ['stock' => $stock, 'soldOut' => $soldOut];
            } catch (\RedisException $e) {
                $lastException = $e;
                if (!$this->isTransientError($e)) {
                    break;
                }
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    $sleepMicro = (int)pow(2, $attempt - 1) * RedisConstants::RETRY_BASE_DELAY_MICROSECONDS;
                    usleep($sleepMicro);
                    $this->log(LogLevel::WARNING, 'getStock transient error, retrying', [
                        'sku' => $sku,
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMicro / 1000,
                        'exception' => $e
                    ]);
                }
            }
        }

        $this->log(LogLevel::ERROR, 'getStock failed after retries', [
            'sku' => $sku,
            'error' => $lastException->getMessage(),
            'exception' => $lastException
        ]);
        throw new \RuntimeException('获取库存失败', self::CODE_ERR_REDIS_UNAVAILABLE, $lastException);
    }

    /**
     * 批量获取库存（仅数量，不包含售罄状态）
     *
     * @param array $skus
     * @return array ['sku' => int|null]
     */
    public function getStocks(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }
        $keys = array_map(function ($sku) {
            return $this->keyPrefix . $sku;
        }, $skus);
        $values = $this->redis->mget($keys);
        $result = [];
        foreach ($skus as $i => $sku) {
            $result[$sku] = ($values[$i] === false) ? null : (int)$values[$i];
        }
        return $result;
    }

    /**
     * 检查是否已售罄（轻量级查询，可用于网关快速拦截）
     *
     * @param string $sku
     * @return bool
     */
    public function isSoldOut(string $sku): bool
    {
        return (bool)$this->redis->exists($this->getSoldOutKey($sku));
    }

    /**
     * 扣减库存（原子操作）
     *
     * @param string $sku
     * @param int $quantity
     * @return array [
     *     'code'   => int,     // 操作结果码
     *     'remain' => int|null // 剩余库存（当 code != CODE_ERR_NOT_EXISTS 时有值）
     * ]
     */
    public function decrStock(string $sku, int $quantity): array
    {
        if ($quantity <= 0) {
            $current = $this->getStock($sku)['stock'];
            return [
                'code' => self::CODE_ERR_INVALID_QUANTITY,
                'remain' => $current
            ];
        }

        $keys = [$this->getStockKey($sku), $this->getSoldOutKey($sku)];
        $args = [
            $quantity,
            self::CODE_ERR_NOT_EXISTS,
            self::CODE_ERR_INSUFFICIENT,
            self::CODE_SUCCESS
        ];

        $res = $this->execLuaWithRetry('decr', $keys, $args);
        $code = (int)$res[0];
        $remain = $res[1] ?? 0;

        $this->log(
            $code === self::CODE_SUCCESS ? LogLevel::INFO : LogLevel::WARNING,
            'Stock decrement attempt',
            ['sku' => $sku, 'quantity' => $quantity, 'code' => $code, 'remain' => $remain]
        );

        return [
            'code' => $code,
            'remain' => $code === self::CODE_ERR_NOT_EXISTS ? null : $remain
        ];
    }

    /**
     * 增加库存（原子操作，如退款/补货）
     *
     * @param string $sku
     * @param int $quantity
     * @return array [
     *     'code'   => int,
     *     'remain' => int|null
     * ]
     */
    public function incrStock(string $sku, int $quantity): array
    {
        if ($quantity <= 0) {
            $current = $this->getStock($sku)['stock'];
            return [
                'code' => self::CODE_ERR_INVALID_QUANTITY,
                'remain' => $current
            ];
        }

        $keys = [$this->getStockKey($sku), $this->getSoldOutKey($sku)];
        $args = [
            $quantity,
            self::CODE_ERR_NOT_EXISTS
        ];

        $remain = $this->execLuaWithRetry('incr', $keys, $args);

        if ($remain === self::CODE_ERR_NOT_EXISTS) {
            $this->log(LogLevel::WARNING, 'Stock increment failed: not exists', ['sku' => $sku]);
            return [
                'code' => self::CODE_ERR_NOT_EXISTS,
                'remain' => null
            ];
        }

        $this->log(LogLevel::INFO, 'Stock incremented', ['sku' => $sku, 'quantity' => $quantity, 'remain' => $remain]);
        return [
            'code' => self::CODE_SUCCESS,
            'remain' => $remain
        ];
    }

    /**
     * 批量扣减库存
     * 原子性保证：利用 Lua 脚本实现伪事务（要么全部成功，要么全部失败）
     * @param array $items 关联数组 ['sku' => 数量]
     * @return array 成功：['success'=>true, 'code'=>CODE_SUCCESS, 'remain'=>[...]]
     *               失败：['success'=>false, 'code'=>错误码, 'sku'=>失败规格, 'required'=>需求量, 'available'=>可用量]
     */
    public function decrMultiStocks(array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'code' => self::CODE_ERR_INVALID_QUANTITY];
        }

        $skuList = array_keys($items);  // 固定顺序，用于失败时定位 SKU
        $keys = [];
        $args = [];
        foreach ($skuList as $sku) {
            $keys[] = $this->getStockKey($sku);
            $args[] = max(0, (int)$items[$sku]);
        }

        // 附加参数：NOT_EXISTS 码、INSUFFICIENT 码
        $args[] = self::CODE_ERR_NOT_EXISTS;
        $args[] = self::CODE_ERR_INSUFFICIENT;

        $result = $this->execLuaWithRetry('decr_multi', $keys, $args);

        if (isset($result[0]) && $result[0] < 0) {
            $failedIndex = $result[1];
            $failedSku = $skuList[$failedIndex - 1] ?? 'unknown';
            $available = $result[2] ?? 0;
            $required = $items[$failedSku] ?? 0;

            $this->log(LogLevel::WARNING, 'Multi decr failed', [
                'failed_sku' => $failedSku,
                'code' => $result[0],
                'required' => $required,
                'available' => $available
            ]);

            return [
                'success' => false,
                'code' => (int)$result[0],
                'sku' => $failedSku,
                'required' => $required,
                'available' => $available
            ];
        }

        if (count($result) !== count($skuList)) {
            throw new \RuntimeException('Lua result length mismatch');
        }

        // 成功：构造剩余库存映射
        $remain = array_combine($skuList, $result);
        $this->log(LogLevel::INFO, 'Multi decr success', ['items' => $items, 'remain' => $remain]);

        return [
            'success' => true,
            'code' => self::CODE_SUCCESS,
            'remain' => $remain
        ];
    }

    /**
     * 删除库存及相关售罄标记（危险操作，一般仅用于测试或重置）
     *
     * @param string $sku
     * @return int 删除的 Key 数量
     */
    public function delStock(string $sku): int
    {
        $keys = [$this->getStockKey($sku), $this->getSoldOutKey($sku)];
        $deleted = $this->redis->del($keys);
        $this->log(LogLevel::INFO, 'Stock deleted', ['sku' => $sku, 'deleted' => $deleted]);
        return $deleted;
    }

    /**
     * 监控特定规格的状态一致性
     * @param string $sku
     * @return array
     */
    public function monitor(string $sku): array
    {
        $stockKey = $this->getStockKey($sku);
        $soldOutKey = $this->getSoldOutKey($sku);

        try {
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            $pipe->get($stockKey);
            $pipe->ttl($stockKey);
            $pipe->exists($soldOutKey);
            $res = $pipe->exec();

            if ($res === false) {
                throw new \RedisException('Pipeline exec failed');
            }

            $stockValue = $res[0];
            $ttl = (int)$res[1];
            $hasSoldOut = (bool)$res[2];
            $exists = ($stockValue !== false);
            $stock = $exists ? (int)$stockValue : 0;

            // 修正后的 monitor 一致性判断
            $consistency = true;
            if (!$exists) {
                // 场景：库存 Key 没了，但售罄标记还在（孤立标记）
                if ($hasSoldOut) {
                    $consistency = false;
                }
            } else {
                // 场景：库存还在，但标记状态对不上
                if (($stock > 0 && $hasSoldOut) || ($stock <= 0 && !$hasSoldOut)) {
                    $consistency = false;
                }
            }

            return [
                'exists' => $exists,
                'stock' => $stock,
                'ttl' => $ttl,
                'is_sold_out' => $hasSoldOut,
                'consistency' => $consistency,
            ];
        } catch (\RedisException $e) {
            $this->log(LogLevel::ERROR, "Monitor failed", ['sku' => $sku, 'exception' => $e]);
            throw $e;
        }
    }

    /**
     * 原子修复不一致的状态
     * 此方法是幂等的，可由监控脚本或异步任务重复调用。
     * 修复场景包括：孤立标记清除、缺失标记补全、库存与标记状态同步。
     *
     * @param string $sku
     * @return array [success => bool, action => string]
     */
    public function repair(string $sku): array
    {
        $keys = [$this->getStockKey($sku), $this->getSoldOutKey($sku)];

        try {
            $res = (int)$this->execLuaWithRetry('repair', $keys, []);

            $actions = [
                0 => 'consistent (both absent)',
                1 => 'fixed (removed invalid soldout marker)',
                2 => 'fixed (added missing soldout marker)',
                3 => 'consistent (ok)',
                4 => 'fixed (cleaned orphaned soldout marker)'
            ];

            $actionText = $actions[$res] ?? 'unknown';
            if ($res === 1 || $res === 2 || $res === 4) {
                $this->log(LogLevel::NOTICE, "Stock state repaired", ['sku' => $sku, 'action' => $actionText]);
            }

            return [
                'success' => true,
                'action' => $actionText,
                'code' => $res
            ];
        } catch (\Exception $e) {
            $this->log(LogLevel::ERROR, "Repair failed", ['sku' => $sku, 'exception' => $e]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}