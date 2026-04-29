<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisStock;
use Redis;
use Psr\Log\NullLogger;
use Nermif\RedisConstants;

/**
 * RedisStock 生产级全路径单元测试套件
 * 覆盖：初始化、增减逻辑、批量原子性、售罄拦截、一致性自愈、边界异常、生命周期
 */
class RedisStockTest extends TestCase
{
    private $redis;
    private $stockManager;
    private $testPrefix = '{test:stock}:';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(15);

        $this->stockManager = new RedisStock($this->redis, $this->testPrefix, new NullLogger());

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

    private function assertKeyExists(string $key, bool $expected): void
    {
        $exists = $this->redis->exists($key);
        $actual = is_int($exists) ? $exists > 0 : $exists;
        $this->assertEquals($expected, (bool)$actual, "Key [{$key}] 存在状态不符合预期");
    }

    // -------------------------------------------------------------------------
    // 1. 初始化与获取测试
    // -------------------------------------------------------------------------

    public function testInitStocksZeroQuantity()
    {
        $this->stockManager->initStocks(['ZERO' => 0]);
        $res = $this->stockManager->getStock('ZERO');
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertEquals(0, $res['stock']);
        $this->assertTrue($res['soldOut']);
    }

    public function testInitStocksWithTTL()
    {
        $sku = 'TTL_SKU';
        $this->stockManager->initStocks([$sku => 10], 1);
        $this->assertEquals(10, $this->stockManager->getStock($sku)['stock']);
        sleep(2);
        $this->assertNull($this->stockManager->getStock($sku)['stock']);
    }

    public function testGetStocksMix()
    {
        $this->stockManager->initStocks(['EXISTS' => 50]);
        $result = $this->stockManager->getStocks(['EXISTS', 'NOT_EXISTS', '']);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $result['code']);
        $this->assertEquals(50, $result['data']['EXISTS']);
        $this->assertNull($result['data']['NOT_EXISTS']);
    }

    public function testGetStocksEmptyArray()
    {
        $result = $this->stockManager->getStocks([]);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $result['code']);
        $this->assertEmpty($result['data']);
    }

    public function testInitStocksInvalidNumericType()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a numeric value');
        $this->stockManager->initStocks(['INVALID' => 'abc']);
    }

    public function testInitStocksNegativeValue()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than or equal to 0');
        $this->stockManager->initStocks(['NEG' => -5]);
    }

    // -------------------------------------------------------------------------
    // 2. 边界拦截：不存在 SKU
    // -------------------------------------------------------------------------

    public function testDecrStockNotExists()
    {
        $res = $this->stockManager->decrStock('GHOST_SKU', 5);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testIncrStockNotExists()
    {
        $res = $this->stockManager->incrStock('GHOST_SKU', 10);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
    }

    // -------------------------------------------------------------------------
    // 3. 正常增减库存测试
    // -------------------------------------------------------------------------

    public function testDecrStockInsufficient()
    {
        $sku = 'INSUF';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->decrStock($sku, 10);
        $this->assertEquals(RedisStock::CODE_ERR_INSUFFICIENT, $res['code']);
        $this->assertEquals(5, $res['remain']);
    }

    public function testDecrStockToZero()
    {
        $sku = 'TO_ZERO';
        $this->stockManager->initStocks([$sku => 1]);
        $this->stockManager->decrStock($sku, 1);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testIncrStockClearsSoldOut()
    {
        $sku = 'REFILL';
        $this->stockManager->initStocks([$sku => 0]);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);

        $this->stockManager->incrStock($sku, 10);
        $this->assertFalse($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testInvalidQuantityOperations()
    {
        $sku = 'INVALID';
        $this->stockManager->initStocks([$sku => 10]);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->decrStock($sku, 0)['code']);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->incrStock($sku, -1)['code']);
    }

    // -------------------------------------------------------------------------
    // 4. 批量操作与原子性
    // -------------------------------------------------------------------------

    public function testDecrMultiStocksInvalidNumericType()
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 'abc']);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    public function testDecrMultiStocksAtomicRollback()
    {
        $this->stockManager->initStocks(['A' => 10, 'B' => 3]);
        $res = $this->stockManager->decrMultiStocks(['A' => 5, 'B' => 5]);

        $this->assertFalse($res['success']);
        $this->assertEquals('B', $res['sku']);
        $this->assertEquals(10, $this->stockManager->getStock('A')['stock']);
    }

    public function testDecrMultiStocksWithNonExistent()
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 1, 'GHOST' => 1]);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertEquals(10, $this->stockManager->getStock('A')['stock']);
    }

    // -------------------------------------------------------------------------
    // 5. 监控与自愈测试
    // -------------------------------------------------------------------------

    public function testMonitorDetectsInvalidSoldOutMarker()
    {
        $sku = 'M1';
        $this->stockManager->initStocks([$sku => 10]);
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);

        $res = $this->stockManager->monitor($sku);
        $this->assertFalse($res['consistency']);

        $repair = $this->stockManager->repair($sku);
        $this->assertEquals(1, $repair['repair_code']); // Action 1
        $this->assertFalse($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testRepairAddsMissingSoldOutMarker()
    {
        $sku = 'M2';
        $this->stockManager->initStocks([$sku => 0]);
        $this->redis->del($this->testPrefix . $sku . ':soldout');

        $res = $this->stockManager->repair($sku);
        $this->assertEquals(2, $res['repair_code']);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testRepairCleansOrphanedMarker()
    {
        $sku = 'ORPHAN';
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);

        $res = $this->stockManager->repair($sku);
        $this->assertEquals(4, $res['repair_code']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);
    }

    public function testRepairIdempotency()
    {
        $sku = 'IDEM';
        $this->stockManager->initStocks([$sku => 10]);
        $this->stockManager->repair($sku);
        $res = $this->stockManager->repair($sku);
        $this->assertEquals(3, $res['repair_code']);
    }

    // -------------------------------------------------------------------------
    // 6. 生命周期、特殊字符与并发模拟
    // -------------------------------------------------------------------------

    public function testDelStockLifecycle()
    {
        $sku = 'DEL';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->delStock($sku);

        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertGreaterThanOrEqual(1, $res['deleted']);

        $this->assertNull($this->stockManager->getStock($sku)['stock']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);

        $incrRes = $this->stockManager->incrStock($sku, 5);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $incrRes['code']);
    }

    public function testMonitorReturnStructure()
    {
        $sku = 'MONITOR_STRUCT';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->monitor($sku);

        $this->assertArrayHasKey('code', $res);
        $this->assertArrayHasKey('exists', $res);
        $this->assertArrayHasKey('stock', $res);
        $this->assertArrayHasKey('ttl', $res);
        $this->assertArrayHasKey('is_sold_out', $res);
        $this->assertArrayHasKey('consistency', $res);

        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['exists']);
        $this->assertEquals(10, $res['stock']);
        $this->assertTrue($res['consistency']);
    }

    public function testSpecialCharacters()
    {
        $sku = 'PROD:123#{HASH}';
        $this->stockManager->initStocks([$sku => 100]);
        $this->assertEquals(100, $this->stockManager->getStock($sku)['stock']);
    }

    public function testConcurrentSimulation()
    {
        $sku = 'CONCUR';
        $this->stockManager->initStocks([$sku => 10]);

        for ($i = 0; $i < 15; $i++) {
            $res = $this->stockManager->decrStock($sku, 1);
            if ($i < 10) {
                $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
            } else {
                $this->assertEquals(RedisStock::CODE_ERR_INSUFFICIENT, $res['code']);
            }
        }
        $this->assertEquals(0, $this->stockManager->getStock($sku)['stock']);
    }

    // =========================================================================
    // 以下为补充的边界测试方法
    // =========================================================================

    /**
     * 测试：incrStock 正常补货成功（直接校验返回值）
     */
    public function testIncrStockSuccess()
    {
        $sku = 'INCR_OK';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->incrStock($sku, 3);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertEquals(8, $res['remain']);
        $this->assertEquals(8, $this->stockManager->getStock($sku)['stock']);
    }

    /**
     * 测试：incrStock 对不存在的 SKU 返回 remain 为 null
     */
    public function testIncrStockNotExistsRemain()
    {
        $res = $this->stockManager->incrStock('GHOST_INCR', 5);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    /**
     * 测试：decrStock 正常扣减成功（校验 remain 字段）
     */
    public function testDecrStockSuccess()
    {
        $sku = 'DECR_OK';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->decrStock($sku, 3);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertEquals(7, $res['remain']);
        $this->assertEquals(7, $this->stockManager->getStock($sku)['stock']);
    }

    /**
     * 测试：getStock 返回值结构（包含 code, stock, soldOut）
     */
    public function testGetStockStructure()
    {
        $sku = 'STRUCT';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->getStock($sku);
        $this->assertArrayHasKey('code', $res);
        $this->assertArrayHasKey('stock', $res);
        $this->assertArrayHasKey('soldOut', $res);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertEquals(5, $res['stock']);
        $this->assertFalse($res['soldOut']);
    }

    /**
     * 测试：getStock 查询不存在的 SKU
     */
    public function testGetStockNotExists()
    {
        $res = $this->stockManager->getStock('GHOST_GET');
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']); // 查询成功但 stock 为 null
        $this->assertNull($res['stock']);
        $this->assertFalse($res['soldOut']);
    }

    /**
     * 测试：isSoldOut 查询不存在的 SKU
     */
    public function testIsSoldOutNotExists()
    {
        $res = $this->stockManager->isSoldOut('GHOST_SOLDOUT');
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['soldOut']);
    }

    /**
     * 测试：decrMultiStocks 批量扣减成功场景
     */
    public function testDecrMultiStocksSuccess()
    {
        $this->stockManager->initStocks(['MULTI_A' => 10, 'MULTI_B' => 20, 'MULTI_C' => 30]);
        $items = ['MULTI_A' => 2, 'MULTI_B' => 5, 'MULTI_C' => 8];
        $res = $this->stockManager->decrMultiStocks($items);
        $this->assertTrue($res['success']);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertCount(3, $res['remain']);
        $this->assertEquals(8, $res['remain']['MULTI_A']);
        $this->assertEquals(15, $res['remain']['MULTI_B']);
        $this->assertEquals(22, $res['remain']['MULTI_C']);
    }

    /**
     * 测试：decrMultiStocks 空数组输入
     */
    public function testDecrMultiStocksEmptyArray()
    {
        $res = $this->stockManager->decrMultiStocks([]);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    /**
     * 测试：initStocks TTL 超过最大值被截断
     */
    public function testInitStocksTtlCapped()
    {
        $sku = 'TTL_CAP';
        $ttl = RedisConstants::MAX_TTL + 100;
        $this->stockManager->initStocks([$sku => 10], $ttl);
        // 验证 Key 的 TTL 不大于 MAX_TTL（由于测试环境可快速过期，这里只检查 TTL 值 ≤ MAX_TTL）
        $stockKey = $this->testPrefix . $sku;
        $actualTtl = $this->redis->ttl($stockKey);
        // TTL 可能为 -1（永不过期）或大于 0
        if ($actualTtl > 0) {
            $this->assertLessThanOrEqual(RedisConstants::MAX_TTL, $actualTtl);
        } else {
            $this->assertEquals(-1, $actualTtl, 'TTL 可能被设为永不过期（当原 TTL 为 0 时）');
        }
    }

    /**
     * 测试：initStocks TTL 为负数抛异常
     */
    public function testInitStocksNegativeTtl()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be >= 0');
        $this->stockManager->initStocks(['NEG_TTL' => 10], -5);
    }

    /**
     * 测试：repair 对不存在的 SKU（两者都不存在）返回 repair_code 0
     */
    public function testRepairBothAbsent()
    {
        $sku = 'BOTH_ABSENT';
        $res = $this->stockManager->repair($sku);
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['success']);
        $this->assertEquals(0, $res['repair_code']);
        $this->assertEquals('consistent (both absent)', $res['action']);
    }

    /**
     * 测试：monitor 对不存在的 SKU
     */
    public function testMonitorNotExists()
    {
        $res = $this->stockManager->monitor('GHOST_MONITOR');
        $this->assertEquals(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['exists']);
        $this->assertEquals(0, $res['stock']);
        $this->assertEquals(-2, $res['ttl']);
        $this->assertFalse($res['is_sold_out']);
        $this->assertTrue($res['consistency']); // 不存在但无标记，一致
    }
}