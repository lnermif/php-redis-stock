<?php

namespace Nermif;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Redis → 数据库同步服务
 *
 * 从 Redis 读取当前库存和销售数据，通过 DatabaseSyncInterface 写回持久化数据库。
 * 适用于秒杀结束后、定时任务、订单取消等场景下的数据同步。
 *
 * 使用方式：
 *   - 事件驱动：在 purchase/cancel 成功后立即调用 syncBySku()
 *   - 定时任务：通过 syncAllActive() 批量同步所有活跃 SKU
 *   - 手动触发：通过 syncMultiple() 同步指定 SKU 列表
 */
class RedisToDatabaseSync
{
    /** @var RedisStock */
    private $stockManager;

    /** @var RedisSales */
    private $salesManager;

    /** @var DatabaseSyncInterface */
    private $dbSync;

    /** @var LoggerInterface|NullLogger */
    private $logger;

    /**
     * @param RedisStock              $stockManager 库存管理器
     * @param RedisSales              $salesManager 销售管理器
     * @param DatabaseSyncInterface   $dbSync       DB 同步实现
     * @param LoggerInterface|null    $logger       PSR-3 日志记录器
     */
    public function __construct(
        RedisStock             $stockManager,
        RedisSales             $salesManager,
        DatabaseSyncInterface  $dbSync,
        ?LoggerInterface       $logger = null
    )
    {
        $this->stockManager = $stockManager;
        $this->salesManager = $salesManager;
        $this->dbSync = $dbSync;
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * 同步单个 SKU 的库存与销售数据
     *
     * 从 Redis 读取该 SKU 的剩余库存、累计销量、累计销售额，
     * 然后通过 DatabaseSyncInterface 写入数据库。
     *
     * @param string $sku 商品 SKU
     * @return bool 同步是否成功
     */
    public function syncBySku(string $sku): bool
    {
        try {
            $stockResult = $this->stockManager->getStock($sku);
            if ($stockResult['code'] !== StockSalesCodes::CODE_SUCCESS) {
                $this->logger->warning('RedisToDatabaseSync: failed to read stock from Redis', [
                    'sku' => $sku,
                    'code' => $stockResult['code'],
                ]);
                return false;
            }

            $salesCountResult = $this->salesManager->getSalesCount($sku);
            $salesAmountResult = $this->salesManager->getSalesAmount($sku);

            $remain = $stockResult['stock'] ?? 0;
            $salesCount = $salesCountResult['data'] ?? 0;
            $salesAmount = $salesAmountResult['data'] ?? 0;

            $this->dbSync->syncStockAndSales($sku, (int)$remain, (int)$salesCount, (int)$salesAmount);

            $this->logger->info('RedisToDatabaseSync: synced SKU to DB', [
                'sku' => $sku,
                'remain' => $remain,
                'salesCount' => $salesCount,
                'salesAmount' => $salesAmount,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('RedisToDatabaseSync: syncBySku failed', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 批量同步多个 SKU
     *
     * @param string[] $skus SKU 列表
     * @return array ['success' => int, 'failed' => int, 'failed_skus' => string[]]
     */
    public function syncMultiple(array $skus): array
    {
        $successCount = 0;
        $failedCount = 0;
        $failedSkus = [];

        foreach ($skus as $sku) {
            if ($this->syncBySku($sku)) {
                $successCount++;
            } else {
                $failedCount++;
                $failedSkus[] = $sku;
            }
        }

        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'failed_skus' => $failedSkus,
        ];
    }

    /**
     * 同步所有活跃 SKU 的数据到数据库
     *
     * 适用于定时任务全量同步场景。
     *
     * @return array ['success' => int, 'failed' => int, 'failed_skus' => string[]]
     */
    public function syncAllActive(): array
    {
        try {
            $activeSkus = $this->stockManager->getActiveSkus();
            if (empty($activeSkus)) {
                return ['success' => 0, 'failed' => 0, 'failed_skus' => []];
            }

            return $this->syncMultiple($activeSkus);
        } catch (\Exception $e) {
            $this->logger->error('RedisToDatabaseSync: syncAllActive failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => 0, 'failed' => 0, 'failed_skus' => []];
        }
    }

    /**
     * 仅同步库存数据（轻量模式，不含销量）
     *
     * @param string $sku 商品 SKU
     * @return bool
     */
    public function syncStockOnly(string $sku): bool
    {
        try {
            $stockResult = $this->stockManager->getStock($sku);
            if ($stockResult['code'] !== StockSalesCodes::CODE_SUCCESS) {
                return false;
            }

            $remain = (int)($stockResult['stock'] ?? 0);
            $this->dbSync->syncStockAndSales($sku, $remain, 0, 0);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('RedisToDatabaseSync: syncStockOnly failed', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}