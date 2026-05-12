<?php

namespace Nermif\Tests;

use Nermif\RedisPurchaseManager;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisPurchaseManagerTest extends TestCase
{
    /** @var Redis */
    private $redis;

    /** @var RedisPurchaseManager */
    private $manager;

    private $testPrefix = '{test:purchase}:';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(15);

        $this->manager = new RedisPurchaseManager($this->redis, $this->testPrefix);
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

    public function testPurchaseSuccess(): void
    {
        $this->manager->initStocks(['SKU_P1' => 2], 3600);

        $res = $this->manager->purchase('SKU_P1', 'USER_P1', 1, 1990, 'ORDER_P1', 0);
        $this->assertTrue($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertEquals('SKU_P1', $res['sku']);
        $this->assertEquals('ORDER_P1', $res['order_id']);
        $this->assertEquals(1, $res['total_sales']);
        // remain 只在库存不足时返回；成功时用 getStock() 验证扣减后的库存更准确
        $this->assertNull($res['remain']);

        $stock = $this->manager->getStock('SKU_P1');
        $this->assertEquals(1, $stock['stock']);
    }

    public function testPurchaseIdempotent(): void
    {
        $this->manager->initStocks(['SKU_IDEM' => 10], 3600);

        $first = $this->manager->purchase('SKU_IDEM', 'USER_IDEM', 2, 1000, 'ORDER_IDEM', 0);
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $first['code']);

        $second = $this->manager->purchase('SKU_IDEM', 'USER_IDEM', 2, 1000, 'ORDER_IDEM', 0);
        $this->assertFalse($second['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_ALREADY_PROCESSED, $second['code']);
        $this->assertNull($second['total_sales']);
        $this->assertNull($second['remain']);
    }

    public function testPurchaseInsufficientStock(): void
    {
        $this->manager->initStocks(['SKU_LOW' => 2], 3600);

        $res = $this->manager->purchase('SKU_LOW', 'USER_LOW', 5, 1000, 'ORDER_LOW', 0);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_INSUFFICIENT, $res['code']);
        $this->assertEquals(2, $res['remain']);
    }

    public function testPurchaseLimitExceeded(): void
    {
        $this->manager->initStocks(['SKU_LIMIT' => 100], 3600);

        $r1 = $this->manager->purchase('SKU_LIMIT', 'USER_LIMIT', 2, 1000, 'ORDER_L1', 3);
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $r1['code']);

        $r2 = $this->manager->purchase('SKU_LIMIT', 'USER_LIMIT', 2, 1000, 'ORDER_L2', 3);
        $this->assertFalse($r2['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_LIMIT_EXCEEDED, $r2['code']);
        $this->assertEquals(1, $r2['remaining_limit']); // 已购2，限购3，剩余1
    }

    public function testCancelOrderRollbackSuccess(): void
    {
        $this->manager->initStocks(['SKU_CAN' => 2], 3600);

        $purchase = $this->manager->purchase('SKU_CAN', 'USER_CAN', 1, 1990, 'ORDER_CAN', 0);
        $this->assertTrue($purchase['success']);

        $stock1 = $this->manager->getStock('SKU_CAN');
        $this->assertEquals(1, $stock1['stock']);

        $cancel = $this->manager->cancelOrder('SKU_CAN', 1, 1990, 'ORDER_CAN');
        $this->assertTrue($cancel['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $cancel['code']);
        $this->assertEquals(2, $cancel['remain']);

        $stock2 = $this->manager->getStock('SKU_CAN');
        $this->assertEquals(2, $stock2['stock']);
    }

    public function testCancelOrderIdempotentAndBlockPurchase(): void
    {
        $this->manager->initStocks(['SKU_CAN2' => 2], 3600);

        $this->manager->purchase('SKU_CAN2', 'USER_CAN2', 1, 1990, 'ORDER_CAN2', 0);
        $stock1 = $this->manager->getStock('SKU_CAN2');
        $this->assertEquals(1, $stock1['stock']);

        $cancel1 = $this->manager->cancelOrder('SKU_CAN2', 1, 1990, 'ORDER_CAN2');
        $this->assertTrue($cancel1['success']);
        $this->assertEquals(2, $cancel1['remain']);

        // 再次取消：应当幂等，不重复回滚
        $cancel2 = $this->manager->cancelOrder('SKU_CAN2', 1, 1990, 'ORDER_CAN2');
        $this->assertFalse($cancel2['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_ORDER_CANCELED, $cancel2['code']);

        $stock2 = $this->manager->getStock('SKU_CAN2');
        $this->assertEquals(2, $stock2['stock']);

        // 取消后同一个 orderId 的重试扣减，应被拦截
        $purchase2 = $this->manager->purchase('SKU_CAN2', 'USER_CAN2', 1, 1990, 'ORDER_CAN2', 0);
        $this->assertFalse($purchase2['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_ORDER_CANCELED, $purchase2['code']);

        $stock3 = $this->manager->getStock('SKU_CAN2');
        $this->assertEquals(2, $stock3['stock']);
    }

    // -------------------------------------------------------------------------
    // 补充测试：覆盖 isSoldOut / monitor / repair / incrStock 及边界场景
    // -------------------------------------------------------------------------

    public function testIsSoldOut(): void
    {
        $this->manager->initStocks(['SKU_SOLD' => 1], 3600);
        $this->assertFalse($this->manager->isSoldOut('SKU_SOLD')['soldOut']);

        $this->manager->purchase('SKU_SOLD', 'USER_SOLD', 1, 1000, 'ORDER_SOLD', 0);
        $this->assertTrue($this->manager->isSoldOut('SKU_SOLD')['soldOut']);
    }

    public function testIsSoldOutNotExists(): void
    {
        $res = $this->manager->isSoldOut('SKU_GHOST');
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['soldOut']);
    }

    public function testMonitor(): void
    {
        $this->manager->initStocks(['SKU_MON' => 10], 3600);
        $res = $this->manager->monitor('SKU_MON');

        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['exists']);
        $this->assertEquals(10, $res['stock']);
        $this->assertGreaterThan(0, $res['ttl']);
        $this->assertFalse($res['is_sold_out']);
        $this->assertTrue($res['consistency']);
    }

    public function testMonitorNotExists(): void
    {
        $res = $this->manager->monitor('SKU_GHOST');
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['exists']);
        $this->assertEquals(0, $res['stock']);
        $this->assertEquals(-2, $res['ttl']);
        $this->assertFalse($res['is_sold_out']);
        $this->assertTrue($res['consistency']);
    }

    public function testRepairConsistent(): void
    {
        $this->manager->initStocks(['SKU_REP' => 10], 3600);
        $res = $this->manager->repair('SKU_REP');

        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['success']);
        $this->assertEquals(3, $res['repair_code']); // consistent
    }

    public function testRepairInconsistentSoldOutMarker(): void
    {
        $this->manager->initStocks(['SKU_REP2' => 10], 3600);
        // 手动设置错误的售罄标记
        $this->redis->set('{test:purchase}:SKU_REP2:soldout', 1);

        $res = $this->manager->repair('SKU_REP2');
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['repair_code']); // removed invalid soldout marker
    }

    public function testRepairNotExists(): void
    {
        $res = $this->manager->repair('SKU_GHOST');
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['success']);
        $this->assertEquals(0, $res['repair_code']); // both absent
    }

    public function testIncrStock(): void
    {
        $this->manager->initStocks(['SKU_INCR' => 5], 3600);
        $res = $this->manager->incrStock('SKU_INCR', 3);

        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertEquals(8, $res['remain']);

        $stock = $this->manager->getStock('SKU_INCR');
        $this->assertEquals(8, $stock['stock']);
    }

    public function testIncrStockNotExists(): void
    {
        $res = $this->manager->incrStock('SKU_GHOST', 5);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testPurchaseNotExists(): void
    {
        $res = $this->manager->purchase('SKU_GHOST', 'USER_GHOST', 1, 1000, 'ORDER_GHOST', 0);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_NOT_EXISTS, $res['code']);
    }

    public function testPurchaseInvalidQuantity(): void
    {
        $this->manager->initStocks(['SKU_INV' => 10], 3600);

        $res = $this->manager->purchase('SKU_INV', 'USER_INV', 0, 1000, 'ORDER_INV_QTY', 0);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_INVALID_QUANTITY, $res['code']);

        $res2 = $this->manager->purchase('SKU_INV', 'USER_INV', -1, 1000, 'ORDER_INV_QTY2', 0);
        $this->assertFalse($res2['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_INVALID_QUANTITY, $res2['code']);
    }

    public function testPurchaseInvalidAmount(): void
    {
        $this->manager->initStocks(['SKU_INV_AMT' => 10], 3600);

        $res = $this->manager->purchase('SKU_INV_AMT', 'USER_INV_AMT', 1, -100, 'ORDER_INV_AMT', 0);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_INVALID_AMOUNT, $res['code']);
    }

    public function testCancelOrderNotProcessed(): void
    {
        $res = $this->manager->cancelOrder('SKU_GHOST', 1, 1000, 'ORDER_GHOST_CAN');
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisPurchaseManager::CODE_ERR_ORDER_NOT_PROCESSED, $res['code']);
    }

    public function testGetStockNotExists(): void
    {
        $res = $this->manager->getStock('SKU_GHOST');
        $this->assertEquals(RedisPurchaseManager::CODE_SUCCESS, $res['code']);
        $this->assertNull($res['stock']);
        $this->assertFalse($res['soldOut']);
    }
}

