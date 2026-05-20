<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisStockSalesManager;
use Nermif\RedisStock;
use Nermif\RedisSales;
use Redis;
use Psr\Log\NullLogger;

class RedisStockSalesManagerTest extends TestCase
{
    private $redis;
    private $manager;
    private $testPrefix = '{test:facade}:';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(15);

        $this->manager = new RedisStockSalesManager($this->redis, $this->testPrefix, new NullLogger());

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->redis->close();
    }

    private function cleanup(): void
    {
        $keys = $this->redis->keys($this->testPrefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    private function assertSuccess(array $result): void
    {
        $this->assertTrue($result['success'], "操作应成功，但失败: {$result['message']}");
        $this->assertSame(RedisStockSalesManager::CODE_SUCCESS, $result['code']);
    }

    private function assertFailed(array $result, int $expectedCode): void
    {
        $this->assertFalse($result['success']);
        $this->assertSame($expectedCode, $result['code']);
    }

    // ===== Response Format =====

    public function testResponseFormat(): void
    {
        $result = $this->manager->initStocks(['SKU_FMT' => 10]);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
    }

    // ===== Purchase (must syncActiveSkus first) =====

    public function testPurchaseSuccessAndCancel(): void
    {
        $this->manager->initStocks(['SKU_PC' => 10]);
        $this->manager->syncActiveSkus(['SKU_PC']);
        $result = $this->manager->purchase('SKU_PC', 'USER_PC', 3, 3000, 'ORDER_PC', 5);
        $this->assertSuccess($result);
        $this->assertSame(3, $result['data']['total_sales']);
        $this->assertSame(7, $this->manager->getStock('SKU_PC')['data']['stock']);
    }

    public function testPurchaseInsufficient(): void
    {
        $this->manager->initStocks(['SKU_INSUF_PC' => 2]);
        $this->manager->syncActiveSkus(['SKU_INSUF_PC']);
        $result = $this->manager->purchase('SKU_INSUF_PC', 'USER_INSUF', 5, 5000, 'ORDER_INSUF_PC', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INSUFFICIENT);
        $this->assertStringContainsString('库存不足', $result['message']);
    }

    public function testPurchaseNotInit(): void
    {
        $this->manager->syncActiveSkus(['SKU_NOT_INIT']);
        $result = $this->manager->purchase('SKU_NOT_INIT', 'U1', 1, 1000, 'ORDER_NOT_INIT', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_NOT_EXISTS);
        $this->assertStringContainsString('未初始化', $result['message']);
    }

    public function testPurchaseLimitExceeded(): void
    {
        $this->manager->initStocks(['SKU_LIMIT_PC' => 10]);
        $this->manager->syncActiveSkus(['SKU_LIMIT_PC']);
        $r1 = $this->manager->purchase('SKU_LIMIT_PC', 'USER_LIM', 3, 3000, 'ORDER_LIM1', 3);
        $this->assertSuccess($r1);
        $r2 = $this->manager->purchase('SKU_LIMIT_PC', 'USER_LIM', 1, 1000, 'ORDER_LIM2', 3);
        $this->assertFailed($r2, RedisStockSalesManager::CODE_ERR_LIMIT_EXCEEDED);
    }

    public function testPurchaseIdempotent(): void
    {
        $this->manager->initStocks(['SKU_IDEM_PC' => 10]);
        $this->manager->syncActiveSkus(['SKU_IDEM_PC']);
        $r1 = $this->manager->purchase('SKU_IDEM_PC', 'U1', 2, 2000, 'ORDER_IDEM_PC', 0);
        $this->assertSuccess($r1);
        $r2 = $this->manager->purchase('SKU_IDEM_PC', 'U2', 5, 5000, 'ORDER_IDEM_PC', 0);
        $this->assertFailed($r2, RedisStockSalesManager::CODE_ERR_ALREADY_PROCESSED);
    }

    public function testPurchaseZeroQuantity(): void
    {
        $this->manager->initStocks(['SKU_ZERO_QTY' => 10]);
        $this->manager->syncActiveSkus(['SKU_ZERO_QTY']);
        $result = $this->manager->purchase('SKU_ZERO_QTY', 'U1', 0, 1000, 'ORDER_ZERO', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testPurchaseNegativeQuantity(): void
    {
        $this->manager->initStocks(['SKU_NEG' => 10]);
        $this->manager->syncActiveSkus(['SKU_NEG']);
        $result = $this->manager->purchase('SKU_NEG', 'U1', -1, 1000, 'ORDER_NEG', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testPurchaseNegativeAmount(): void
    {
        $this->manager->initStocks(['SKU_NEG_AMT' => 10]);
        $this->manager->syncActiveSkus(['SKU_NEG_AMT']);
        $result = $this->manager->purchase('SKU_NEG_AMT', 'U1', 1, -1000, 'ORDER_NEG_AMT', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_AMOUNT);
    }

    public function testPurchaseZeroAmount(): void
    {
        $this->manager->initStocks(['SKU_FREE_PC' => 10]);
        $this->manager->syncActiveSkus(['SKU_FREE_PC']);
        $result = $this->manager->purchase('SKU_FREE_PC', 'U1', 1, 0, 'ORDER_FREE_PC', 0);
        $this->assertSuccess($result);
    }

    public function testPurchaseWithLimitParamLarge(): void
    {
        $this->manager->initStocks(['SKU_BIG_LIMIT' => 100]);
        $this->manager->syncActiveSkus(['SKU_BIG_LIMIT']);
        $result = $this->manager->purchase('SKU_BIG_LIMIT', 'U1', 1, 1000, 'ORDER_BIG_LIMIT', 99);
        $this->assertSuccess($result);
        $skus = $this->manager->getStock('SKU_BIG_LIMIT');
        $this->assertSame(99, $skus['data']['stock']);
    }

    public function testPurchaseOrderCanceledRejected(): void
    {
        $this->manager->initStocks(['SKU_CAN_REJ' => 10]);
        $this->manager->syncActiveSkus(['SKU_CAN_REJ']);
        $cancelKey = $this->testPrefix . 'order:ORDER_CAN_REJ:canceled';
        $this->redis->setex($cancelKey, 300, '1');
        $result = $this->manager->purchase('SKU_CAN_REJ', 'U1', 1, 1000, 'ORDER_CAN_REJ', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_ORDER_CANCELED);
        $this->assertStringContainsString('已取消', $result['message']);
    }

    public function testPurchaseDeductToZeroSetsSoldout(): void
    {
        $this->manager->initStocks(['SKU_SOLD_PC' => 1]);
        $this->manager->syncActiveSkus(['SKU_SOLD_PC']);
        $this->manager->purchase('SKU_SOLD_PC', 'U1', 1, 1000, 'ORDER_SOLD_PC', 0);
        $result = $this->manager->isSoldOut('SKU_SOLD_PC');
        $this->assertTrue($result['data']['soldOut']);
    }

    // ===== Cancel =====

    public function testCancelSuccess(): void
    {
        $this->manager->initStocks(['SKU_C' => 10]);
        $this->manager->syncActiveSkus(['SKU_C']);
        $this->manager->purchase('SKU_C', 'U1', 3, 3000, 'ORDER_C', 0);
        $result = $this->manager->cancel('SKU_C', 3, 3000, 'ORDER_C');
        $this->assertSuccess($result);
        $this->assertSame(10, $result['data']['remain']);
    }

    public function testCancelAlreadyCanceled(): void
    {
        $this->manager->initStocks(['SKU_CA' => 5]);
        $this->manager->syncActiveSkus(['SKU_CA']);
        $this->manager->purchase('SKU_CA', 'U1', 1, 1000, 'ORDER_CA', 0);
        $this->manager->cancel('SKU_CA', 1, 1000, 'ORDER_CA');
        $result = $this->manager->cancel('SKU_CA', 1, 1000, 'ORDER_CA');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_ORDER_CANCELED);
    }

    public function testCancelNotProcessed(): void
    {
        $result = $this->manager->cancel('SKU_CP', 1, 1000, 'ORDER_NOT_EXIST');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_ORDER_NOT_PROCESSED);
    }

    public function testCancelNotInitialized(): void
    {
        $orderKey = $this->testPrefix . 'order:' . 'ORDER_NI_CANCEL';
        $this->redis->setex($orderKey, 300, '1');
        $result = $this->manager->cancel('SKU_NI', 1, 1000, 'ORDER_NI_CANCEL');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_NOT_EXISTS);
    }

    public function testCancelNegativeQuantity(): void
    {
        $result = $this->manager->cancel('SKU', -1, 1000, 'ORDER');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testCancelZeroQuantity(): void
    {
        $result = $this->manager->cancel('SKU', 0, 1000, 'ORDER');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testCancelNegativeAmount(): void
    {
        $result = $this->manager->cancel('SKU', 1, -1000, 'ORDER');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_AMOUNT);
    }

    public function testCancelInvalidSku(): void
    {
        $result = $this->manager->cancel('bad:sku', 1, 1000, 'ORDER');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testCancelRestoresSoldout(): void
    {
        $this->manager->initStocks(['SKU_RST' => 1]);
        $this->manager->syncActiveSkus(['SKU_RST']);
        $this->manager->purchase('SKU_RST', 'U1', 1, 1000, 'ORDER_RST', 0);
        $this->assertTrue($this->manager->isSoldOut('SKU_RST')['data']['soldOut']);
        $this->manager->cancel('SKU_RST', 1, 1000, 'ORDER_RST');
        $this->assertFalse($this->manager->isSoldOut('SKU_RST')['data']['soldOut']);
    }

    // ===== Param Validation =====

    public function testParamInvalidSku(): void
    {
        $result = $this->manager->purchase('bad:sku', 'U1', 1, 1000, 'O1', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testParamInvalidUserId(): void
    {
        $this->manager->initStocks(['SKU_P' => 10]);
        $this->manager->syncActiveSkus(['SKU_P']);
        $result = $this->manager->purchase('SKU_P', 'bad:user', 1, 1000, 'O1', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testParamEmptyOrderId(): void
    {
        $result = $this->manager->purchase('SKU', 'U1', 1, 1000, '', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testParamNegativeLimit(): void
    {
        $this->manager->initStocks(['SKU_NL' => 10]);
        $this->manager->syncActiveSkus(['SKU_NL']);
        $result = $this->manager->purchase('SKU_NL', 'U1', 1, 1000, 'O_NL', -1);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testParamNegativeLimitMessageMatch(): void
    {
        $this->manager->initStocks(['SKU_NLM' => 10]);
        $this->manager->syncActiveSkus(['SKU_NLM']);
        $result = $this->manager->purchase('SKU_NLM', 'U1', 1, 1000, 'O_NLM', -1);
        $this->assertStringContainsString('负数', $result['message']);
    }

    public function testPurchaseWithNoLimitZero(): void
    {
        $this->manager->initStocks(['SKU_ZL' => 100]);
        $this->manager->syncActiveSkus(['SKU_ZL']);
        $result = $this->manager->purchase('SKU_ZL', 'U1', 50, 50000, 'O_ZL', 0);
        $this->assertSuccess($result);
    }

    // ===== Init and Incr =====

    public function testInitStocksSuccess(): void
    {
        $result = $this->manager->initStocks(['SKU_INIT' => 50]);
        $this->assertSuccess($result);
        $this->assertSame(1, $result['data']['initialized_count']);
    }

    public function testInitStocksEmpty(): void
    {
        $result = $this->manager->initStocks([]);
        $this->assertSuccess($result);
        $this->assertSame(0, $result['data']['initialized_count']);
    }

    public function testInitStocksWithTTL(): void
    {
        $result = $this->manager->initStocks(['SKU_TTL' => 10], 60);
        $this->assertSuccess($result);
    }

    public function testIncrGhostSku(): void
    {
        $result = $this->manager->incrStock('GHOST_INCR', 10);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_NOT_EXISTS);
    }

    public function testInitThenIncr(): void
    {
        $this->manager->initStocks(['SKU_II' => 5]);
        $result = $this->manager->incrStock('SKU_II', 5);
        $this->assertSuccess($result);
        $getResult = $this->manager->getStock('SKU_II');
        $this->assertSame(10, $getResult['data']['stock']);
    }

    public function testIncrAfterDpl(): void
    {
        $this->manager->initStocks(['SKU_DI' => 10]);
        $this->manager->syncActiveSkus(['SKU_DI']);
        $this->manager->purchase('SKU_DI', 'U1', 10, 10000, 'ORDER_DI', 0);
        $this->assertTrue($this->manager->isSoldOut('SKU_DI')['data']['soldOut']);
        $this->manager->incrStock('SKU_DI', 5);
        $this->assertFalse($this->manager->isSoldOut('SKU_DI')['data']['soldOut']);
    }

    // ===== DecrStock Via Manager =====

    public function testDecrStockSuccess(): void
    {
        $this->manager->initStocks(['SKU_DECR' => 10]);
        $result = $this->manager->decrStock('SKU_DECR', 4);
        $this->assertSuccess($result);
        $this->assertSame(6, $result['data']['remain']);
    }

    public function testDecrStockInsufficient(): void
    {
        $this->manager->initStocks(['SKU_DECR_INF' => 2]);
        $result = $this->manager->decrStock('SKU_DECR_INF', 5);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INSUFFICIENT);
    }

    public function testDecrStockNotInitialized(): void
    {
        $result = $this->manager->decrStock('SKU_NO_INIT', 1);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_NOT_EXISTS);
    }

    public function testDecrStockInvalidSku(): void
    {
        $result = $this->manager->decrStock('bad:sku', 1);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testDecrStockZeroQuantity(): void
    {
        $result = $this->manager->decrStock('SKU', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testDecrStockNegativeQuantity(): void
    {
        $result = $this->manager->decrStock('SKU', -1);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testDecrStockWorksWhenInactive(): void
    {
        $this->manager->initStocks(['SKU_INACTIVE' => 5]);
        $result = $this->manager->decrStock('SKU_INACTIVE', 2);
        $this->assertSuccess($result);
    }

    // ===== IsSoldOut =====

    public function testIsSoldOutNotExistsReturnsFalse(): void
    {
        $result = $this->manager->isSoldOut('GHOST_SO');
        $this->assertSuccess($result);
        $this->assertFalse($result['data']['soldOut']);
    }

    public function testIsSoldOutReturnsTrueWhenStockZero(): void
    {
        $this->manager->initStocks(['SKU_SO' => 0]);
        $result = $this->manager->isSoldOut('SKU_SO');
        $this->assertTrue($result['data']['soldOut']);
    }

    public function testIsSoldOutInvalidSku(): void
    {
        $result = $this->manager->isSoldOut('bad:sku');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    // ===== GetStock =====

    public function testGetStockSuccess(): void
    {
        $this->manager->initStocks(['SKU_GS' => 20]);
        $result = $this->manager->getStock('SKU_GS');
        $this->assertSuccess($result);
        $this->assertSame(20, $result['data']['stock']);
        $this->assertArrayHasKey('soldOut', $result['data']);
    }

    public function testGetStockNotExists(): void
    {
        $result = $this->manager->getStock('SKU_MISS');
        $this->assertSuccess($result);
        $this->assertNull($result['data']['stock']);
        $this->assertFalse($result['data']['soldOut']);
    }

    public function testGetStockInvalidSku(): void
    {
        $result = $this->manager->getStock('bad:sku');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    // ===== Response Patterns =====

    public function testErrorResponsesAllHaveSuccessFalse(): void
    {
        $r1 = $this->manager->purchase('bad:sku', 'U1', 1, 1000, 'O1', 0);
        $this->assertFalse($r1['success']);

        $r2 = $this->manager->purchase('', 'U1', 1, 1000, 'O2', 0);
        $this->assertFalse($r2['success']);

        $r3 = $this->manager->cancel('bad:sku', 1, 1000, 'O3');
        $this->assertFalse($r3['success']);

        $r4 = $this->manager->cancel('SKU', 1, 1000, 'NO_ORDER');
        $this->assertFalse($r4['success']);

        $r5 = $this->manager->initStocks(['SKU_ERR' => 10]);
        $this->assertTrue($r5['success']);
    }

    // ===== Monitor and Repair =====

    public function testMonitorConsistencyCheck(): void
    {
        $this->manager->initStocks(['SKU_MON' => 10]);
        $result = $this->manager->monitor('SKU_MON');
        $this->assertSuccess($result);
        $this->assertTrue($result['data']['consistency']);
    }

    public function testMonitorNotExists(): void
    {
        $result = $this->manager->monitor('SKU_GHOST_MON');
        $this->assertSuccess($result);
        $this->assertFalse($result['data']['exists']);
        $this->assertTrue($result['data']['consistency']);
    }

    public function testMonitorInvalidSku(): void
    {
        $result = $this->manager->monitor('bad:sku');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testMonitorDetectsInvalidSoldout(): void
    {
        $this->manager->initStocks(['SKU_MON_BAD' => 10]);
        $soldOutKey = $this->testPrefix . 'SKU_MON_BAD:soldout';
        $this->redis->set($soldOutKey, 1);
        $result = $this->manager->monitor('SKU_MON_BAD');
        $this->assertFalse($result['data']['consistency']);
    }

    public function testRepairBothAbsent(): void
    {
        $result = $this->manager->repair('SKU_NOOP');
        $this->assertSuccess($result);
        $this->assertSame(0, $result['data']['repair_code']);
        $this->assertStringContainsString('both absent', $result['message']);
    }

    public function testRepairRemovesInvalidSoldout(): void
    {
        $this->manager->initStocks(['SKU_REP_M1' => 10]);
        $this->redis->set($this->testPrefix . 'SKU_REP_M1:soldout', 1);
        $result = $this->manager->repair('SKU_REP_M1');
        $this->assertSuccess($result);
        $this->assertSame(1, $result['data']['repair_code']);
        $this->assertFalse($this->manager->isSoldOut('SKU_REP_M1')['data']['soldOut']);
    }

    public function testRepairAddsMissingSoldout(): void
    {
        $this->manager->initStocks(['SKU_REP_M2' => 0]);
        $this->redis->del($this->testPrefix . 'SKU_REP_M2:soldout');
        $result = $this->manager->repair('SKU_REP_M2');
        $this->assertSuccess($result);
        $this->assertSame(2, $result['data']['repair_code']);
        $this->assertTrue($this->manager->isSoldOut('SKU_REP_M2')['data']['soldOut']);
    }

    public function testRepairInvalidSku(): void
    {
        $result = $this->manager->repair('bad:sku');
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    // ===== Active SKU Operations =====

    public function testIsSkuActiveNotSynced(): void
    {
        $active = $this->manager->getActiveSkus();
        $this->assertNotContains('ANY_SKU', $active['data']);
    }

    public function testSyncActiveSkus(): void
    {
        $this->manager->syncActiveSkus(['S1', 'S2']);
        $active = $this->manager->getActiveSkus();
        $this->assertContains('S1', $active['data']);
        $this->assertContains('S2', $active['data']);
    }

    public function testGetActiveSkusEmpty(): void
    {
        $result = $this->manager->getActiveSkus();
        $this->assertEmpty($result['data']);
    }

    public function testGetActiveSkusAfterSync(): void
    {
        $this->manager->syncActiveSkus(['A', 'B', 'C']);
        $result = $this->manager->getActiveSkus();
        $this->assertCount(3, $result['data']);
    }

    public function testAddActiveSkus(): void
    {
        $this->manager->syncActiveSkus(['BASE']);
        $this->manager->addActiveSkus(['NEW']);
        $active = $this->manager->getActiveSkus();
        $this->assertContains('NEW', $active['data']);
        $this->assertContains('BASE', $active['data']);
    }

    public function testAddActiveSkusEmpty(): void
    {
        $this->manager->syncActiveSkus(['E']);
        $this->manager->addActiveSkus([]);
        $active = $this->manager->getActiveSkus();
        $this->assertCount(1, $active['data']);
    }

    public function testRemoveActiveSku(): void
    {
        $this->manager->syncActiveSkus(['R1', 'R2']);
        $this->manager->removeActiveSku('R1');
        $active = $this->manager->getActiveSkus();
        $this->assertNotContains('R1', $active['data']);
        $this->assertContains('R2', $active['data']);
    }

    public function testPurchaseBlockedWhenNotActive(): void
    {
        $this->manager->initStocks(['SKU_BLOCKED' => 10]);
        $result = $this->manager->purchase('SKU_BLOCKED', 'U1', 1, 1000, 'O_BLOCKED', 0);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
        $this->assertStringContainsString('不可售', $result['message']);
    }

    public function testPurchaseWorksAfterSyncActive(): void
    {
        $this->manager->initStocks(['SKU_ACTIVE' => 10]);
        $this->manager->syncActiveSkus(['SKU_ACTIVE']);
        $result = $this->manager->purchase('SKU_ACTIVE', 'U1', 1, 1000, 'O_ACTIVE', 0);
        $this->assertSuccess($result);
    }

    public function testActiveSkuLifecycle(): void
    {
        $this->manager->initStocks(['SKU_LIFE' => 10]);
        $activeBefore = $this->manager->getActiveSkus();
        $this->assertNotContains('SKU_LIFE', $activeBefore['data']);
        $this->manager->syncActiveSkus(['SKU_LIFE']);
        $activeAfterSync = $this->manager->getActiveSkus();
        $this->assertContains('SKU_LIFE', $activeAfterSync['data']);
        $result = $this->manager->purchase('SKU_LIFE', 'U1', 1, 1000, 'O_LIFE', 0);
        $this->assertSuccess($result);
        $this->manager->removeActiveSku('SKU_LIFE');
        $activeAfterRemove = $this->manager->getActiveSkus();
        $this->assertNotContains('SKU_LIFE', $activeAfterRemove['data']);
    }

    // ===== Long IDs =====

    public function testVeryLongIds(): void
    {
        $longSku = str_repeat('X', 100);
        $longUser = str_repeat('U', 100);
        $longOrder = str_repeat('O', 64);
        $this->manager->initStocks([$longSku => 10]);
        $this->manager->syncActiveSkus([$longSku]);
        $result = $this->manager->purchase($longSku, $longUser, 1, 1000, $longOrder, 0);
        $this->assertSuccess($result);
    }

    // ===== initStocks throws exceptions =====

    public function testInitStocksWithInvalidValueReturnsError(): void
    {
        $result = $this->manager->initStocks(['SKU_INV' => 'abc']);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testInitStocksWithNegativeValueReturnsError(): void
    {
        $result = $this->manager->initStocks(['SKU_NEG' => -5]);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    public function testInitStocksWithNegativeTtlReturnsError(): void
    {
        $result = $this->manager->initStocks(['SKU_TTL_NEG' => 10], -10);
        $this->assertFailed($result, RedisStockSalesManager::CODE_ERR_INVALID_QUANTITY);
    }

    // ===== DecrMultiStocks is not exposed via manager but we can test the stock manager directly =====

    public function testPurchaseMultipleSkus(): void
    {
        $this->manager->initStocks(['SKU_M_A' => 10, 'SKU_M_B' => 5]);
        $this->manager->syncActiveSkus(['SKU_M_A', 'SKU_M_B']);
        $r1 = $this->manager->purchase('SKU_M_A', 'U1', 3, 3000, 'ORDER_M_A', 0);
        $this->assertSuccess($r1);
        $r2 = $this->manager->purchase('SKU_M_B', 'U1', 2, 2000, 'ORDER_M_B', 0);
        $this->assertSuccess($r2);
        $this->assertSame(7, $this->manager->getStock('SKU_M_A')['data']['stock']);
        $this->assertSame(3, $this->manager->getStock('SKU_M_B')['data']['stock']);
    }
}