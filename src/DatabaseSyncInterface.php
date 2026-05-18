<?php

namespace Nermif;

/**
 * 数据库同步接口
 *
 * 定义 Redis 库存/销量数据同步到持久化数据库（如 MySQL）的契约。
 * 业务方实现此接口，将 Redis 中的实时数据定期或事件驱动地写入 DB。
 *
 * 使用场景：
 *   - 秒杀结束后，将 Redis 中的剩余库存、累计销量、累计销售额同步回 DB
 *   - 定时任务批量同步活跃 SKU 数据
 *   - 订单取消后回滚数据同步
 *
 * 注意事项：
 *   - 所有方法应尽量幂等（INSERT ... ON DUPLICATE KEY UPDATE 或 REPLACE INTO）
 *   - 实现方应自行处理 DB 连接异常，避免向上抛出影响 Redis 主流程
 */
interface DatabaseSyncInterface
{
    /**
     * 同步单个 SKU 的库存与销售数据到数据库
     *
     * @param string $sku         商品 SKU
     * @param int    $remain      当前剩余库存
     * @param int    $salesCount  累计销量
     * @param int    $salesAmount 累计销售额（单位：分）
     */
    public function syncStockAndSales(string $sku, int $remain, int $salesCount, int $salesAmount): void;
}