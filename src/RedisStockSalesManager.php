<?php

namespace Nermif;

use Psr\Log\LoggerInterface;

/**
 * Redis 统一购买管理器（Facade）
 *
 * 封装 RedisStock 与 RedisSales，提供原子化的购买与取消流程，
 * 同时暴露常用的库存查询、监控与修复能力。
 *
 * 所有对外方法均返回统一结构：
 * [
 *   'success' => bool,      // 操作是否成功（code === StockSalesCodes::CODE_SUCCESS）
 *   'code'    => int,       // 业务状态码（参考 StockSalesCodes 接口常量）
 *   'message' => string,    // 人类可读的描述信息
 *   'data'    => mixed|null // 该操作特有的业务数据，失败时可能为 null
 * ]
 */
class RedisStockSalesManager implements StockSalesCodes
{
    use IdSanitizer;

    /** @var RedisStock */
    private $stockManager;

    /** @var RedisSales */
    private $salesManager;

    /**
     * @param \Redis $redis 已连接的 Redis 实例
     * @param string $keyPrefix Key 前缀，强烈建议包含 Hash Tag（如 "{product:stock}:"）
     * @param LoggerInterface|null $logger PSR-3 日志记录器
     * @param int|null $maxRetries 最大重试次数，null 则使用默认值
     */
    public function __construct(
        \Redis           $redis,
        string           $keyPrefix = '{product:stock}:',
        ?LoggerInterface $logger = null,
        ?int             $maxRetries = null
    )
    {
        // 验证连接状态（兼容不同版本 PhpRedis）
        try {
            $redis->ping();
        } catch (\RedisException $e) {
            throw new \InvalidArgumentException('Redis 实例未连接或不可用：' . $e->getMessage());
        }
        $this->stockManager = new RedisStock($redis, $keyPrefix, $logger, $maxRetries);
        $this->salesManager = new RedisSales($redis, $keyPrefix, $logger, $maxRetries);
    }

    // ---------- 统一响应构造 ----------

    /**
     * 构造统一返回结构
     *
     * @param int $code 业务状态码
     * @param string $message 描述信息
     * @param mixed|null $data 业务数据
     * @return array
     */
    private function response(int $code, string $message, $data = null): array
    {
        return [
            'success' => $code === self::CODE_SUCCESS,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }

    // ---------- 核心操作 ----------

    /**
     * 购买下单（原子扣减库存 + 记录销售数据）
     *
     * @param string $sku 商品 SKU
     * @param string $userId 用户 ID
     * @param int $quantity 购买数量
     * @param int $amount 金额（单位：分，如 1999 表示 19.99 元）
     * @param string $orderId 订单 ID（幂等键）
     * @param int $limitPerUser 用户限购数量，0 表示不限购
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => [
     *       'sku'             => string,        // 请求的 SKU
     *       'user_id'         => string,        // 用户 ID
     *       'order_id'        => string,        // 订单 ID
     *       'total_sales'     => int|null,      // 成功时为当前总销量
     *       'remain'          => int|null,      // 库存不足时返回剩余库存，其他情况可能为 null
     *       'remaining_limit' => int|null,      // 超过限购时返回还可购买数量，其他情况为 null
     *   ]
     * ]
     *
     * 可能的错误码（参考 StockSalesCodes）：
     *   CODE_SUCCESS / CODE_ERR_INSUFFICIENT / CODE_ERR_NOT_EXISTS
     *   CODE_ERR_LIMIT_EXCEEDED / CODE_ERR_ALREADY_PROCESSED
     *   CODE_ERR_INVALID_QUANTITY / CODE_ERR_INVALID_AMOUNT
     *   CODE_ERR_ORDER_CANCELED / CODE_ERR_REDIS_UNAVAILABLE
     */
    public function purchase(
        string $sku,
        string $userId,
        int    $quantity,
        int    $amount,
        string $orderId,
        int    $limitPerUser = 0
    ): array
    {
        // 参数快速失败校验
        if ($orderId === '') {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, '订单ID不能为空');
        }
        if ($limitPerUser < 0) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, '限购数量不能为负数');
        }
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        if (!$this->isValidId($userId)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, '用户ID包含非法字符');
        }
        if (!$this->isSkuActive($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, '该商品当前不可售');
        }

        $result = $this->salesManager->recordPurchaseWithStock(
            $sku, $userId, $quantity, $amount, $orderId, $limitPerUser
        );

        $code = (int)($result['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE);
        $message = (string)($result['message'] ?? '');

        $data = [
            'sku' => $sku,
            'user_id' => $userId,
            'order_id' => $orderId,
            'total_sales' => $result['total_sales'] ?? null,
            'remain' => $result['remain'] ?? null,
            'remaining_limit' => $result['remaining_limit'] ?? null,
        ];

        return $this->response($code, $message, $data);
    }

    /**
     * 取消订单（原子回滚库存 + 回退销售数据）
     *
     * ⚠️ 重要约束：
     *    $quantity 和 $amount 必须与下单时使用的值完全一致，
     *    否则会导致库存和销售数据产生不可逆的偏差。
     *    建议调用方从订单系统获取原始下单参数后调用本方法。
     *
     * @param string $sku 商品 SKU
     * @param int $quantity 取消数量（需与下单数量一致）
     * @param int $amount 取消金额（单位：分，需与下单金额一致）
     * @param string $orderId 订单 ID
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => [
     *       'sku'      => string,
     *       'order_id' => string,
     *       'remain'   => int|null,  // 回滚后剩余库存；若为 CODE_ERR_ORDER_CANCELED 也返回当前库存
     *   ]
     * ]
     *
     * 可能的错误码：CODE_SUCCESS / CODE_ERR_ORDER_CANCELED / CODE_ERR_ORDER_NOT_PROCESSED / CODE_ERR_NOT_EXISTS / CODE_ERR_INVALID_QUANTITY / CODE_ERR_INVALID_AMOUNT / CODE_ERR_REDIS_UNAVAILABLE
     */
    public function cancel(string $sku, int $quantity, int $amount, string $orderId): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $result = $this->salesManager->cancelOrderWithStock($sku, $quantity, $amount, $orderId);
        $code = (int)($result['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE);
        $message = (string)($result['message'] ?? '');

        $data = [
            'sku' => $sku,
            'order_id' => $orderId,
            'remain' => $result['remain'] ?? null,
        ];

        return $this->response($code, $message, $data);
    }

    // ---------- 辅助操作 ----------

    /**
     * 批量初始化库存（幂等，已存在的 SKU 不会覆盖）
     *
     * @param array $stocks 关联数组，格式 ['sku' => 库存数量, ...]；数量必须 >= 0
     * @param int $ttl 库存过期时间（秒），0 表示永不过期，上限由 RedisConstants::MAX_TTL 控制
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => ['initialized_count' => int]  // 实际被初始化的 SKU 数量
     * ]
     */
    public function initStocks(array $stocks, int $ttl = 0): array
    {
        try {
            $count = $this->stockManager->initStocks($stocks, $ttl);
            return $this->response(self::CODE_SUCCESS, '初始化成功', ['initialized_count' => $count]);
        } catch (\InvalidArgumentException $e) {
            // 参数不合法（如 TTL < 0、库存为负值等）
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, $e->getMessage());
        } catch (\Exception $e) {
            // Redis 不可用、脚本加载失败等运行时错误
            return $this->response(self::CODE_ERR_REDIS_UNAVAILABLE, '初始化失败：' . $e->getMessage());
        }
    }

    /**
     * 增加库存（补货/退款场景）
     *
     * @param string $sku 商品 SKU
     * @param int $quantity 增加数量（> 0）
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => ['remain' => int|null] // 增加后的库存；若库存未初始化则返回 null
     * ]
     */
    public function addStock(string $sku, int $quantity): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $res = $this->stockManager->incrStock($sku, $quantity);
        $code = $res['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE;
        $message = ($code === self::CODE_SUCCESS) ? '增加库存成功' : '增加库存失败';

        return $this->response($code, $message, [
            'remain' => $res['remain'] ?? null,
        ]);
    }

    /**
     * 查询单个商品库存（含售罄状态）
     *
     * @param string $sku 商品 SKU
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => [
     *       'stock'   => int|null, // null 表示库存未初始化
     *       'soldOut' => bool,     // 是否已标记售罄
     *   ]
     * ]
     */
    public function getStock(string $sku): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $res = $this->stockManager->getStock($sku);
        $code = $res['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE;
        $message = ($code === self::CODE_SUCCESS) ? 'OK' : '查询失败';

        return $this->response($code, $message, [
            'stock' => $res['stock'] ?? null,
            'soldOut' => $res['soldOut'] ?? false,
        ]);
    }

    /**
     * 快速判断是否售罄（轻量查询，可用于网关拦截）
     *
     * @param string $sku 商品 SKU
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => ['soldOut' => bool]
     * ]
     */
    public function isSoldOut(string $sku): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $res = $this->stockManager->isSoldOut($sku);
        $code = $res['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE;
        $message = ($code === self::CODE_SUCCESS) ? 'OK' : '查询失败';

        return $this->response($code, $message, [
            'soldOut' => $res['soldOut'] ?? false,
        ]);
    }

    /**
     * 监控库存与售罄标记的一致性
     *
     * @param string $sku 商品 SKU
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string,
     *   'data'    => [
     *       'exists'      => bool,  // 库存 Key 是否存在
     *       'stock'       => int,   // 当前库存值（不存在时为 0）
     *       'ttl'         => int,   // 库存 Key 的剩余生存时间（秒），-2 表示不存在
     *       'is_sold_out' => bool,  // 售罄标记是否存在
     *       'consistency' => bool,  // 库存数量与售罄标记是否一致
     *   ]
     * ]
     */
    public function monitor(string $sku): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $res = $this->stockManager->monitor($sku);
        $code = $res['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE;
        $message = ($code === self::CODE_SUCCESS) ? 'OK' : '监控失败';

        return $this->response($code, $message, [
            'exists' => $res['exists'] ?? false,
            'stock' => $res['stock'] ?? 0,
            'ttl' => $res['ttl'] ?? -2,
            'is_sold_out' => $res['is_sold_out'] ?? false,
            'consistency' => $res['consistency'] ?? false,
        ]);
    }

    /**
     * 自动修复库存与售罄标记的不一致状态（幂等）
     *
     * @param string $sku 商品 SKU
     *
     * @return array [
     *   'success' => bool,
     *   'code'    => int,
     *   'message' => string, // 描述修复动作的文字
     *   'data'    => [
     *       'repair_code' => int|null // 修复动作码：
     *           0 = 两者均不存在（正常）
     *           1 = 删除了无效的售罄标记
     *           2 = 补全了缺失的售罄标记
     *           3 = 状态一致无需处理
     *           4 = 清理了孤立的售罄标记
     *   ]
     * ]
     */
    public function repair(string $sku): array
    {
        if (!$this->isValidId($sku)) {
            return $this->response(self::CODE_ERR_INVALID_QUANTITY, 'SKU 包含非法字符');
        }
        $res = $this->stockManager->repair($sku);
        $code = $res['code'] ?? self::CODE_ERR_REDIS_UNAVAILABLE;
        $message = $res['action'] ?? '修复失败';

        return $this->response($code, $message, [
            'repair_code' => $res['repair_code'] ?? -1,
        ]);
    }

    /**
     * 检查 SKU 是否处于活跃（可售）状态
     */
    private function isSkuActive(string $sku): bool
    {
        return $this->stockManager->isSkuActive($sku);
    }

    /**
     * 全量同步活跃 SKU 列表
     *
     * @param array $skus 字符串 SKU 列表
     * @return array ['success' => bool, 'code' => int, 'message' => string, 'data' => null]
     */
    public function syncActiveSkus(array $skus): array
    {
        try {
            $this->stockManager->syncActiveSkus($skus);
            return $this->response(self::CODE_SUCCESS, '同步成功');
        } catch (\RuntimeException $e) {
            return $this->response(self::CODE_ERR_REDIS_UNAVAILABLE, '同步失败：' . $e->getMessage());
        }
    }

    /**
     * 获取当前所有活跃 SKU
     *
     * @return array ['success' => bool, 'code' => int, 'message' => string, 'data' => string[]]
     */
    public function getActiveSkus(): array
    {
        try {
            $skus = $this->stockManager->getActiveSkus();
            return $this->response(self::CODE_SUCCESS, 'OK', $skus);
        } catch (\RuntimeException $e) {
            return $this->response(self::CODE_ERR_REDIS_UNAVAILABLE, '查询失败：' . $e->getMessage());
        }
    }

    /**
     * 将指定 SKU 移出活跃集合（使其不可售）
     *
     * @param string $sku
     * @return array ['success' => bool, 'code' => int, 'message' => string, 'data' => null]
     */
    public function removeActiveSku(string $sku): array
    {
        try {
            $this->stockManager->removeActiveSku($sku);
            return $this->response(self::CODE_SUCCESS, '移除成功');
        } catch (\RuntimeException $e) {
            return $this->response(self::CODE_ERR_REDIS_UNAVAILABLE, '移除失败：' . $e->getMessage());
        }
    }
}