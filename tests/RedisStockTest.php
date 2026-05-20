<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisStock;
use Nermif\RedisConstants;
use Redis;
use Psr\Log\NullLogger;

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

    public function testInitStocksEmptyArray(): void
    {
        $result = $this->stockManager->initStocks([]);
        $this->assertSame(0, $result);
    }

    public function testInitStocksZeroQuantity(): void
    {
        $this->stockManager->initStocks(['ZERO' => 0]);
        $res = $this->stockManager->getStock('ZERO');
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertSame(0, $res['stock']);
        $this->assertTrue($res['soldOut']);
    }

    public function testInitStocksWithTTL(): void
    {
        $sku = 'TTL_SKU';
        $this->stockManager->initStocks([$sku => 10], 1);
        $this->assertSame(10, $this->stockManager->getStock($sku)['stock']);
        sleep(2);
        $this->assertNull($this->stockManager->getStock($sku)['stock']);
    }

    public function testInitStocksIdempotent(): void
    {
        $sku = 'IDEM_INIT';
        $this->stockManager->initStocks([$sku => 10]);
        $this->stockManager->initStocks([$sku => 99]);
        $res = $this->stockManager->getStock($sku);
        $this->assertSame(10, $res['stock']);
    }

    public function testInitStocksInvalidNumericType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a numeric value');
        $this->stockManager->initStocks(['INVALID' => 'abc']);
    }

    public function testInitStocksNegativeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than or equal to 0');
        $this->stockManager->initStocks(['NEG' => -5]);
    }

    public function testInitStocksNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be >= 0');
        $this->stockManager->initStocks(['NEG_TTL' => 10], -5);
    }

    public function testInitStocksTtlCapped(): void
    {
        $sku = 'TTL_CAP';
        $ttl = RedisConstants::MAX_TTL + 100;
        $this->stockManager->initStocks([$sku => 10], $ttl);
        $stockKey = $this->testPrefix . $sku;
        $actualTtl = $this->redis->ttl($stockKey);
        if ($actualTtl > 0) {
            $this->assertLessThanOrEqual(RedisConstants::MAX_TTL, $actualTtl);
        } else {
            $this->assertSame(-1, $actualTtl);
        }
    }

    public function testGetStocksMix(): void
    {
        $this->stockManager->initStocks(['EXISTS' => 50]);
        $result = $this->stockManager->getStocks(['EXISTS', 'NOT_EXISTS', 'MISSING']);
        $this->assertSame(RedisStock::CODE_SUCCESS, $result['code']);
        $this->assertSame(50, $result['data']['EXISTS']);
        $this->assertNull($result['data']['NOT_EXISTS']);
        $this->assertNull($result['data']['MISSING']);
    }

    public function testGetStocksEmptyArray(): void
    {
        $result = $this->stockManager->getStocks([]);
        $this->assertSame(RedisStock::CODE_SUCCESS, $result['code']);
        $this->assertEmpty($result['data']);
    }

    public function testGetStocksInvalidSkuReturnsError(): void
    {
        $result = $this->stockManager->getStocks(['VALID', 'sku:bad']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertEmpty($result['data']);
    }

    public function testGetStockStructure(): void
    {
        $sku = 'STRUCT';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->getStock($sku);
        $this->assertArrayHasKey('code', $res);
        $this->assertArrayHasKey('stock', $res);
        $this->assertArrayHasKey('soldOut', $res);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertSame(5, $res['stock']);
        $this->assertFalse($res['soldOut']);
    }

    public function testGetStockNotExists(): void
    {
        $res = $this->stockManager->getStock('GHOST_GET');
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertNull($res['stock']);
        $this->assertFalse($res['soldOut']);
    }

    public function testGetStockInvalidSku(): void
    {
        $res = $this->stockManager->getStock('sku:bad');
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertNull($res['stock']);
        $this->assertFalse($res['soldOut']);
    }

    public function testIsSoldOutNotExists(): void
    {
        $res = $this->stockManager->isSoldOut('GHOST_SOLDOUT');
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['soldOut']);
    }

    public function testIsSoldOutInvalidSku(): void
    {
        $res = $this->stockManager->isSoldOut('bad:sku');
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertFalse($res['soldOut']);
    }

    public function testIsSoldOutTrueWhenStockZero(): void
    {
        $sku = 'SOLD_OUT_Y';
        $this->stockManager->initStocks([$sku => 0]);
        $res = $this->stockManager->isSoldOut($sku);
        $this->assertTrue($res['soldOut']);
    }

    public function testDecrStockNotExists(): void
    {
        $res = $this->stockManager->decrStock('GHOST_SKU', 5);
        $this->assertSame(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testDecrStockSuccess(): void
    {
        $sku = 'DECR_OK';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->decrStock($sku, 3);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertSame(7, $res['remain']);
        $this->assertSame(7, $this->stockManager->getStock($sku)['stock']);
    }

    public function testDecrStockInsufficient(): void
    {
        $sku = 'INSUF';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->decrStock($sku, 10);
        $this->assertSame(RedisStock::CODE_ERR_INSUFFICIENT, $res['code']);
        $this->assertSame(5, $res['remain']);
    }

    public function testDecrStockToZero(): void
    {
        $sku = 'TO_ZERO';
        $this->stockManager->initStocks([$sku => 1]);
        $this->stockManager->decrStock($sku, 1);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testDecrStockInvalidQuantity(): void
    {
        $sku = 'INVALID';
        $this->stockManager->initStocks([$sku => 10]);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->decrStock($sku, 0)['code']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->decrStock($sku, -1)['code']);
    }

    public function testDecrStockInvalidSku(): void
    {
        $res = $this->stockManager->decrStock('bad:sku', 1);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testIncrStockNotExists(): void
    {
        $res = $this->stockManager->incrStock('GHOST_SKU', 10);
        $this->assertSame(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testIncrStockSuccess(): void
    {
        $sku = 'INCR_OK';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->incrStock($sku, 3);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertSame(8, $res['remain']);
        $this->assertSame(8, $this->stockManager->getStock($sku)['stock']);
    }

    public function testIncrStockClearsSoldOut(): void
    {
        $sku = 'REFILL';
        $this->stockManager->initStocks([$sku => 0]);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);
        $this->stockManager->incrStock($sku, 10);
        $this->assertFalse($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testIncrStockInvalidQuantity(): void
    {
        $sku = 'INCR_INV';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->incrStock($sku, -1);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testIncrStockInvalidSku(): void
    {
        $res = $this->stockManager->incrStock('bad:sku', 5);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertNull($res['remain']);
    }

    public function testDecrMultiStocksSuccess(): void
    {
        $this->stockManager->initStocks(['MULTI_A' => 10, 'MULTI_B' => 20, 'MULTI_C' => 30]);
        $items = ['MULTI_A' => 2, 'MULTI_B' => 5, 'MULTI_C' => 8];
        $res = $this->stockManager->decrMultiStocks($items);
        $this->assertTrue($res['success']);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertCount(3, $res['remain']);
        $this->assertSame(8, $res['remain']['MULTI_A']);
        $this->assertSame(15, $res['remain']['MULTI_B']);
        $this->assertSame(22, $res['remain']['MULTI_C']);
    }

    public function testDecrMultiStocksAtomicRollback(): void
    {
        $this->stockManager->initStocks(['A' => 10, 'B' => 3]);
        $res = $this->stockManager->decrMultiStocks(['A' => 5, 'B' => 5]);
        $this->assertFalse($res['success']);
        $this->assertSame('B', $res['sku']);
        $this->assertSame(10, $this->stockManager->getStock('A')['stock']);
    }

    public function testDecrMultiStocksWithNonExistent(): void
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 1, 'GHOST' => 1]);
        $this->assertFalse($res['success']);
        $this->assertSame(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertSame(10, $this->stockManager->getStock('A')['stock']);
    }

    public function testDecrMultiStocksEmptyArray(): void
    {
        $res = $this->stockManager->decrMultiStocks([]);
        $this->assertFalse($res['success']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    public function testDecrMultiStocksInvalidNumericType(): void
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 'abc']);
        $this->assertFalse($res['success']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    public function testDecrMultiStocksWithInvalidSku(): void
    {
        $res = $this->stockManager->decrMultiStocks(['bad:sku' => 1]);
        $this->assertFalse($res['success']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    public function testDecrMultiStocksWithZeroQuantity(): void
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 0]);
        $this->assertFalse($res['success']);
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
    }

    public function testMonitorReturnStructure(): void
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
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['exists']);
        $this->assertSame(10, $res['stock']);
        $this->assertTrue($res['consistency']);
    }

    public function testMonitorNotExists(): void
    {
        $res = $this->stockManager->monitor('GHOST_MONITOR');
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertFalse($res['exists']);
        $this->assertSame(0, $res['stock']);
        $this->assertSame(-2, $res['ttl']);
        $this->assertFalse($res['is_sold_out']);
        $this->assertTrue($res['consistency']);
    }

    public function testMonitorInvalidSku(): void
    {
        $res = $this->stockManager->monitor('bad:sku');
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertFalse($res['consistency']);
    }

    public function testMonitorDetectsInvalidSoldOutMarker(): void
    {
        $sku = 'M1';
        $this->stockManager->initStocks([$sku => 10]);
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);
        $res = $this->stockManager->monitor($sku);
        $this->assertFalse($res['consistency']);
    }

    public function testMonitorDetectsMissingSoldOutMarker(): void
    {
        $sku = 'MISS_M';
        $this->stockManager->initStocks([$sku => 0]);
        $this->redis->del($this->testPrefix . $sku . ':soldout');
        $res = $this->stockManager->monitor($sku);
        $this->assertFalse($res['consistency']);
    }

    public function testRepairBothAbsent(): void
    {
        $sku = 'BOTH_ABSENT';
        $res = $this->stockManager->repair($sku);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertTrue($res['success']);
        $this->assertSame(0, $res['repair_code']);
        $this->assertStringContainsString('both absent', $res['action']);
    }

    public function testRepairRemovesInvalidSoldoutMarker(): void
    {
        $sku = 'M1';
        $this->stockManager->initStocks([$sku => 10]);
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);
        $repair = $this->stockManager->repair($sku);
        $this->assertSame(1, $repair['repair_code']);
        $this->assertFalse($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testRepairAddsMissingSoldOutMarker(): void
    {
        $sku = 'M2';
        $this->stockManager->initStocks([$sku => 0]);
        $this->redis->del($this->testPrefix . $sku . ':soldout');
        $res = $this->stockManager->repair($sku);
        $this->assertSame(2, $res['repair_code']);
        $this->assertTrue($this->stockManager->isSoldOut($sku)['soldOut']);
    }

    public function testRepairIdempotency(): void
    {
        $sku = 'IDEM';
        $this->stockManager->initStocks([$sku => 10]);
        $this->stockManager->repair($sku);
        $res = $this->stockManager->repair($sku);
        $this->assertSame(3, $res['repair_code']);
    }

    public function testRepairCleansOrphanedMarker(): void
    {
        $sku = 'ORPHAN';
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);
        $res = $this->stockManager->repair($sku);
        $this->assertSame(4, $res['repair_code']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);
    }

    public function testRepairInvalidSku(): void
    {
        $res = $this->stockManager->repair('bad:sku');
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertFalse($res['success']);
    }

    public function testDelStockLifecycle(): void
    {
        $sku = 'DEL';
        $this->stockManager->initStocks([$sku => 10]);
        $res = $this->stockManager->delStock($sku);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertGreaterThanOrEqual(1, $res['deleted']);
        $this->assertNull($this->stockManager->getStock($sku)['stock']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);
        $incrRes = $this->stockManager->incrStock($sku, 5);
        $this->assertSame(RedisStock::CODE_ERR_NOT_EXISTS, $incrRes['code']);
    }

    public function testDelStockInvalidSku(): void
    {
        $res = $this->stockManager->delStock('bad:sku');
        $this->assertSame(RedisStock::CODE_ERR_INVALID_QUANTITY, $res['code']);
        $this->assertSame(0, $res['deleted']);
    }

    public function testDelStockAlreadyDeleted(): void
    {
        $sku = 'ALREADY_GONE';
        $res = $this->stockManager->delStock($sku);
        $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
        $this->assertSame(0, $res['deleted']);
    }

    public function testSpecialCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->stockManager->initStocks(['PROD:123#{HASH}' => 100]);
    }

    public function testConcurrentSimulation(): void
    {
        $sku = 'CONCUR';
        $this->stockManager->initStocks([$sku => 10]);
        for ($i = 0; $i < 15; $i++) {
            $res = $this->stockManager->decrStock($sku, 1);
            if ($i < 10) {
                $this->assertSame(RedisStock::CODE_SUCCESS, $res['code']);
            } else {
                $this->assertSame(RedisStock::CODE_ERR_INSUFFICIENT, $res['code']);
            }
        }
        $this->assertSame(0, $this->stockManager->getStock($sku)['stock']);
    }

    public function testIsSkuActiveReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->stockManager->isSkuActive('UNKNOWN_SKU'));
    }

    public function testSyncAndGetActiveSkus(): void
    {
        $this->assertEmpty($this->stockManager->getActiveSkus());
        $skus = ['SKU_A', 'SKU_B', 'SKU_C'];
        $this->stockManager->syncActiveSkus($skus);
        $active = $this->stockManager->getActiveSkus();
        sort($active);
        sort($skus);
        $this->assertEquals($skus, $active);
        $this->assertTrue($this->stockManager->isSkuActive('SKU_A'));
        $this->assertTrue($this->stockManager->isSkuActive('SKU_B'));
        $this->assertTrue($this->stockManager->isSkuActive('SKU_C'));
    }

    public function testSyncActiveSkusOverwritesPreviousSet(): void
    {
        $this->stockManager->syncActiveSkus(['OLD_A', 'OLD_B']);
        $this->stockManager->syncActiveSkus(['NEW_X', 'NEW_Y']);
        $active = $this->stockManager->getActiveSkus();
        $this->assertContains('NEW_X', $active);
        $this->assertContains('NEW_Y', $active);
        $this->assertNotContains('OLD_A', $active);
        $this->assertNotContains('OLD_B', $active);
    }

    public function testSyncActiveSkusWithEmptyArrayClearsSet(): void
    {
        $this->stockManager->syncActiveSkus(['TMP']);
        $this->assertNotEmpty($this->stockManager->getActiveSkus());
        $this->stockManager->syncActiveSkus([]);
        $this->assertEmpty($this->stockManager->getActiveSkus());
    }

    public function testRemoveActiveSku(): void
    {
        $this->stockManager->syncActiveSkus(['R1', 'R2', 'R3']);
        $this->stockManager->removeActiveSku('R2');
        $this->assertTrue($this->stockManager->isSkuActive('R1'));
        $this->assertFalse($this->stockManager->isSkuActive('R2'));
        $this->assertTrue($this->stockManager->isSkuActive('R3'));
        $this->stockManager->removeActiveSku('NOT_THERE');
        $this->assertTrue($this->stockManager->isSkuActive('R1'));
    }

    public function testRemoveActiveSkuOnEmptySetDoesNotFail(): void
    {
        $this->stockManager->removeActiveSku('GHOST');
        $this->assertFalse($this->stockManager->isSkuActive('GHOST'));
    }

    public function testAddActiveSkusIncremental(): void
    {
        $this->stockManager->syncActiveSkus(['BASE_A', 'BASE_B']);
        $this->stockManager->addActiveSkus(['NEW_C']);
        $active = $this->stockManager->getActiveSkus();
        $this->assertContains('BASE_A', $active);
        $this->assertContains('BASE_B', $active);
        $this->assertContains('NEW_C', $active);
        $this->assertCount(3, $active);
    }

    public function testAddActiveSkusEmptyDoesNothing(): void
    {
        $this->stockManager->syncActiveSkus(['EXIST']);
        $this->stockManager->addActiveSkus([]);
        $active = $this->stockManager->getActiveSkus();
        $this->assertCount(1, $active);
    }

    public function testAddActiveSkusMultipleNew(): void
    {
        $this->stockManager->addActiveSkus(['X', 'Y', 'Z']);
        $active = $this->stockManager->getActiveSkus();
        $this->assertContains('X', $active);
        $this->assertContains('Y', $active);
        $this->assertContains('Z', $active);
    }

    public function testInitStocksWithZeroTtlNeverExpires(): void
    {
        $sku = 'NO_TTL';
        $this->stockManager->initStocks([$sku => 5], 0);
        $stockKey = $this->testPrefix . $sku;
        $this->assertSame(-1, $this->redis->ttl($stockKey));
    }

    public function testDecrToZeroSetsSoldoutWithInheritedTtl(): void
    {
        $sku = 'TTL_SOLD';
        $this->stockManager->initStocks([$sku => 1], 300);
        $this->stockManager->decrStock($sku, 1);
        $soldOutKey = $this->testPrefix . $sku . ':soldout';
        $ttl = $this->redis->ttl($soldOutKey);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }
}