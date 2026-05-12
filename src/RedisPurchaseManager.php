<?php

namespace Nermif;

use Psr\Log\LoggerInterface;

/**
 * Redis 统一购买管理器（Facade）。
 *
 * 外部只需要调用 purchase() 完成：
 * 1) 扣减库存（Lua 原子）
 * 2) 记录购买/销量/排行榜（Lua 原子）
 *
 * 这样调用方不需要自己再拆分调用 RedisStock / RedisSales 的多个步骤。
 */
class RedisPurchaseManager
{
    // -------------------------------------------------------------------------
    // 对外统一错误码（复用 RedisSales / RedisStock 的定义）
    // -------------------------------------------------------------------------
    public const CODE_SUCCESS = RedisSales::CODE_SUCCESS;
    public const CODE_ERR_INSUFFICIENT = RedisStock::CODE_ERR_INSUFFICIENT;
    public const CODE_ERR_NOT_EXISTS = RedisStock::CODE_ERR_NOT_EXISTS;
    public const CODE_ERR_INVALID_QUANTITY = RedisStock::CODE_ERR_INVALID_QUANTITY;
    public const CODE_ERR_REDIS_UNAVAILABLE = RedisStock::CODE_ERR_REDIS_UNAVAILABLE;

    public const CODE_ERR_LIMIT_EXCEEDED = RedisSales::CODE_ERR_LIMIT_EXCEEDED;
    public const CODE_ERR_ALREADY_PROCESSED = RedisSales::CODE_ERR_ALREADY_PROCESSED;
    public const CODE_ERR_INVALID_AMOUNT = RedisSales::CODE_ERR_INVALID_AMOUNT;
    public const CODE_ERR_ORDER_CANCELED = RedisSales::CODE_ERR_ORDER_CANCELED;
    public const CODE_ERR_ORDER_NOT_PROCESSED = RedisSales::CODE_ERR_ORDER_NOT_PROCESSED;

    /** @var RedisStock */
    private $stockManager;

    /** @var RedisSales */
    private $salesManager;

    /**
     * @param \Redis $redis 需要已 connect 的 Redis 实例
     * @param string $keyPrefix 必须尽量包含 Hash Tag，如 "{product:stock}:"
     * @param LoggerInterface|null $logger PSR-3 日志记录器
     * @param int|null $maxRetries 最大重试次数，null 使用默认值
     */
    public function __construct(
        \Redis $redis,
        string $keyPrefix = '{product:stock}:',
        ?LoggerInterface $logger = null,
        ?int $maxRetries = null
    ) {
        $this->stockManager = new RedisStock($redis, $keyPrefix, $logger, $maxRetries);
        $this->salesManager = new RedisSales($redis, $keyPrefix, $logger, $maxRetries);
    }

    /**
     * 活动开始前预热库存（幂等）。
     *
     * @param array $stocks 关联数组 ['sku' => 库存数量]
     * @param int $ttl 过期时间（秒），0 表示永不过期
     * @return int 成功初始化（此前不存在）的 SKU 数量
     */
    public function initStocks(array $stocks, int $ttl = 0): int
    {
        return $this->stockManager->initStocks($stocks, $ttl);
    }

    /**
     * 购买（扣减库存 + 记录销售）原子操作。
     *
     * 金额单位：分（例如 99.90 元 => 9990 分）
     *
     * @param string $sku 商品 SKU
     * @param string $userId 用户 ID
     * @param int $quantity 购买数量
     * @param int $amount 金额（单位：分）
     * @param string $orderId 订单 ID（用于幂等）
     * @param int $limitPerUser 限购数量，0 表示无限制
     * @return array [
     *   'code' => int,
     *   'success' => bool,
     *   'message' => string,
     *   'total_sales' => int|null,
     *   'remain' => int|null,               // 当库存不足时：剩余可用库存
     *   'remaining_limit' => int|null,     // 当限购超限时：剩余可购买数量
     *   'sku' => string,
     *   'user_id' => string,
     *   'order_id' => string
     * ]
     */
    public function purchase(
        string $sku,
        string $userId,
        int $quantity,
        int $amount,
        string $orderId,
        int $limitPerUser = 0
    ): array {
        $result = $this->salesManager->recordPurchaseWithStock(
            $sku,
            $userId,
            $quantity,
            $amount,
            $orderId,
            $limitPerUser
        );

        $code = (int)($result['code'] ?? 0);

        return [
            'code' => $code,
            'success' => $code === self::CODE_SUCCESS,
            'message' => (string)($result['message'] ?? ''),
            'total_sales' => $result['total_sales'] ?? null,
            'remain' => $result['remain'] ?? null,
            'remaining_limit' => $result['remaining_limit'] ?? null,
            'sku' => $sku,
            'user_id' => $userId,
            'order_id' => $orderId,
        ];
    }

    /**
     * 取消订单
     * 订单取消回滚（幂等 + 并发安全拦截重复回滚/重复扣减）
     *
     * 说明：
     * - 该接口会回滚库存和销售数据（销量、销售额、排行榜）。
     * - 并发下会使用取消标记（cancel marker）拦截重复扣减。
     *
     * @param string $sku 商品 SKU
     * @param int $quantity 取消数量（与下单扣减数量一致）
     * @param int $amount 取消金额（单位：分，与下单金额一致）
     * @param string $orderId 订单 ID（与下单一致）
     * @return array ['code' => int, 'success' => bool, 'message' => string, 'remain' => int|null, 'sku' => string, 'order_id' => string]
     */
    public function cancelOrder(string $sku, int $quantity, int $amount, string $orderId): array
    {
        $result = $this->salesManager->cancelOrderWithStock($sku, $quantity, $amount, $orderId);
        $code = (int)($result['code'] ?? 0);

        return [
            'code' => $code,
            'success' => $code === self::CODE_SUCCESS,
            'message' => (string)($result['message'] ?? ''),
            'remain' => $result['remain'] ?? null,
            'sku' => $sku,
            'order_id' => $orderId,
        ];
    }

    // -------------------------------------------------------------------------
    // 下面这些是“状态/运维”或“库存回滚”的常用能力（可按需暴露给外部）
    // -------------------------------------------------------------------------

    /**
     * 查询商品库存。
     *
     * @param string $sku 商品 SKU
     * @return array ['code' => int, 'stock' => int|null, 'soldOut' => bool]
     */
    public function getStock(string $sku): array
    {
        return $this->stockManager->getStock($sku);
    }

    /**
     * 检查商品是否售罄。
     *
     * @param string $sku 商品 SKU
     * @return array ['code' => int, 'soldOut' => bool]
     */
    public function isSoldOut(string $sku): array
    {
        return $this->stockManager->isSoldOut($sku);
    }

    /**
     * 监控商品库存健康状态。
     *
     * @param string $sku 商品 SKU
     * @return array ['code' => int, 'exists' => bool, 'stock' => int, 'ttl' => int, 'is_sold_out' => bool, 'consistency' => bool]
     */
    public function monitor(string $sku): array
    {
        return $this->stockManager->monitor($sku);
    }

    /**
     * 修复库存
     * 修复库存与销售数据不一致（以实际库存为准校正销售数据）。
     *
     * @param string $sku 商品 SKU
     * @return array ['code' => int, 'success' => bool, 'action' => string, 'repair_code' => int]
     */
    public function repair(string $sku): array
    {
        return $this->stockManager->repair($sku);
    }

    /**
     * 增加库存
     * 订单取消/补货回滚：给库存加回去。
     *
     * @param string $sku 商品 SKU
     * @param int $quantity 增加数量
     * @return array ['code' => int, 'remain' => int|null]
     */
    public function incrStock(string $sku, int $quantity): array
    {
        return $this->stockManager->incrStock($sku, $quantity);
    }
}

