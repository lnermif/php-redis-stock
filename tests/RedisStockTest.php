<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisStock;
use Redis;
use Psr\Log\NullLogger;

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

    /**
     * 清理测试环境，防止用例间数据干扰
     */
    private function cleanup(): void
    {
        $keys = $this->redis->keys($this->testPrefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    /**
     * 辅助断言：兼容不同 php-redis 版本的 exists 返回值（整数 vs 布尔值）
     */
    private function assertKeyExists(string $key, bool $expected): void
    {
        $exists = $this->redis->exists($key);
        $actual = is_int($exists) ? $exists > 0 : $exists;
        $this->assertEquals($expected, (bool)$actual, "Key [{$key}] 存在状态不符合预期");
    }

    // -------------------------------------------------------------------------
    // 1. 初始化与获取测试 (Init & Get)
    // -------------------------------------------------------------------------

    /**
     * 测试：初始化 0 库存
     * 预期：库存值为 0，且自动创建售罄标记（soldOut 为 true）
     */
    public function testInitStocksZeroQuantity()
    {
        $this->stockManager->initStocks(['ZERO' => 0]);
        $res = $this->stockManager->getStock('ZERO');
        $this->assertEquals(0, $res['stock']);
        $this->assertTrue($res['soldOut']);
    }

    /**
     * 测试：设置带 TTL 的库存
     * 预期：初始化后可获取，休眠超过 TTL 时间后，Redis 自动删除 Key，获取返回空
     */
    public function testInitStocksWithTTL()
    {
        $sku = 'TTL_SKU';
        $this->stockManager->initStocks([$sku => 10], 1);
        $this->assertEquals(10, $this->stockManager->getStock($sku)['stock']);
        sleep(2);
        $this->assertNull($this->stockManager->getStock($sku)['stock']);
    }

    /**
     * 测试：批量获取库存（包含存在和不存在的 SKU）
     * 预期：存在的返回具体数值，不存在或空字符串的返回 null
     */
    public function testGetStocksMix()
    {
        $this->stockManager->initStocks(['EXISTS' => 50]);
        $result = $this->stockManager->getStocks(['EXISTS', 'NOT_EXISTS', '']);
        $this->assertEquals(50, $result['EXISTS']);
        $this->assertNull($result['NOT_EXISTS']);
    }

    // -------------------------------------------------------------------------
    // 2. 边界拦截：针对不存在 SKU 的操作
    // -------------------------------------------------------------------------

    /**
     * 测试：扣减从未初始化的 SKU
     * 预期：返回 CODE_ERR_NOT_EXISTS，剩余库存返回 null
     */
    public function testDecrStockNotExists()
    {
        $res = $this->stockManager->decrStock('GHOST_SKU', 5);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertNull($res['remain']);
    }

    /**
     * 测试：补货（增加）从未初始化的 SKU
     * 预期：返回 CODE_ERR_NOT_EXISTS，禁止非初始化写入
     */
    public function testIncrStockNotExists()
    {
        $res = $this->stockManager->incrStock('GHOST_SKU', 10);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
    }

    // -------------------------------------------------------------------------
    // 3. 正常增减库存测试 (Incr & Decr)
    // -------------------------------------------------------------------------

    /**
     * 测试：库存不足时的扣减
     * 预期：返回 CODE_ERR_INSUFFICIENT，余额保持不变（不扣成负数）
     */
    public function testDecrStockInsufficient()
    {
        $sku = 'INSUF';
        $this->stockManager->initStocks([$sku => 5]);
        $res = $this->stockManager->decrStock($sku, 10);
        $this->assertEquals(RedisStock::CODE_ERR_INSUFFICIENT, $res['code']);
        $this->assertEquals(5, $res['remain']);
    }

    /**
     * 测试：正好扣减至 0
     * 预期：返回成功，余额为 0，且系统自动打上售罄标记
     */
    public function testDecrStockToZero()
    {
        $sku = 'TO_ZERO';
        $this->stockManager->initStocks([$sku => 1]);
        $this->stockManager->decrStock($sku, 1);
        $this->assertTrue($this->stockManager->isSoldOut($sku));
    }

    /**
     * 测试：补货清除售罄状态
     * 预期：原本 0 库存（已售罄），增加库存后售罄标记被移除
     */
    public function testIncrStockClearsSoldOut()
    {
        $sku = 'REFILL';
        $this->stockManager->initStocks([$sku => 0]);
        $this->assertTrue($this->stockManager->isSoldOut($sku));

        $this->stockManager->incrStock($sku, 10);
        $this->assertFalse($this->stockManager->isSoldOut($sku));
    }

    /**
     * 测试：无效的数量操作
     * 预期：传入 0 或负数时，直接返回 CODE_ERR_INVALID_QUANTITY
     */
    public function testInvalidQuantityOperations()
    {
        $sku = 'INVALID';
        $this->stockManager->initStocks([$sku => 10]);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->decrStock($sku, 0)['code']);
        $this->assertEquals(RedisStock::CODE_ERR_INVALID_QUANTITY, $this->stockManager->incrStock($sku, -1)['code']);
    }

    // -------------------------------------------------------------------------
    // 4. 批量操作与原子性 (Multi-Operations & Atomicity)
    // -------------------------------------------------------------------------

    /**
     * 测试：批量扣减的原子回滚
     * 预期：同时扣减 A 和 B，若 B 库存不足，则 A 的扣减也必须回滚，保持初始状态
     */
    public function testDecrMultiStocksAtomicRollback()
    {
        $this->stockManager->initStocks(['A' => 10, 'B' => 3]);
        $res = $this->stockManager->decrMultiStocks(['A' => 5, 'B' => 5]);

        $this->assertFalse($res['success']);
        $this->assertEquals('B', $res['sku']);
        $this->assertEquals(10, $this->stockManager->getStock('A')['stock']);
    }

    /**
     * 测试：批量扣减包含不存在的 SKU
     * 预期：由于其中一个 SKU 不存在，整个批量扣减操作不执行，已有库存不被扣减
     */
    public function testDecrMultiStocksWithNonExistent()
    {
        $this->stockManager->initStocks(['A' => 10]);
        $res = $this->stockManager->decrMultiStocks(['A' => 1, 'GHOST' => 1]);
        $this->assertFalse($res['success']);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
        $this->assertEquals(10, $this->stockManager->getStock('A')['stock']);
    }

    // -------------------------------------------------------------------------
    // 5. 监控与自愈测试 (Monitor & Repair)
    // -------------------------------------------------------------------------

    /**
     * 测试：检测无效的售罄标记
     * 预期：人工制造“有库存却有售罄标记”的冲突，monitor 应检测到不一致，repair 应移除错误标记
     */
    public function testMonitorDetectsInvalidSoldOutMarker()
    {
        $sku = 'M1';
        $this->stockManager->initStocks([$sku => 10]);
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);

        $res = $this->stockManager->monitor($sku);
        $this->assertFalse($res['consistency']);

        $repair = $this->stockManager->repair($sku);
        $this->assertEquals(1, $repair['code']); // Action 1: 移除无效标记
        $this->assertFalse($this->stockManager->isSoldOut($sku));
    }

    /**
     * 测试：修复缺失的售罄标记
     * 预期：人工制造“库存为 0 却没标记”的场景，repair 应补全标记（Action 2）
     */
    public function testRepairAddsMissingSoldOutMarker()
    {
        $sku = 'M2';
        $this->stockManager->initStocks([$sku => 0]);
        $this->redis->del($this->testPrefix . $sku . ':soldout');

        $res = $this->stockManager->repair($sku);
        $this->assertEquals(2, $res['code']);
        $this->assertTrue($this->stockManager->isSoldOut($sku));
    }

    /**
     * 测试：清理孤立的售罄标记
     * 预期：模拟主库存 Key 丢失但标记残留，repair 应彻底清理残留标记（Action 4）
     */
    public function testRepairCleansOrphanedMarker()
    {
        $sku = 'ORPHAN';
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);

        $res = $this->stockManager->repair($sku);
        $this->assertEquals(4, $res['code']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);
    }

    /**
     * 测试：修复的幂等性
     * 预期：对状态正常的 SKU 多次调用 repair，应始终返回一致状态（Action 3），不执行额外操作
     */
    public function testRepairIdempotency()
    {
        $sku = 'IDEM';
        $this->stockManager->initStocks([$sku => 10]);
        $this->stockManager->repair($sku);
        $res = $this->stockManager->repair($sku);
        $this->assertEquals(3, $res['code']);
    }

    // -------------------------------------------------------------------------
    // 6. 生命周期、特殊字符与并发模拟
    // -------------------------------------------------------------------------

    /**
     * 测试：删除库存后的生命周期
     * 预期：删除 SKU 后，主 Key 和标记均消失，且后续增减操作均因“不存在”而拦截
     */
    public function testDelStockLifecycle()
    {
        $sku = 'DEL';
        $this->stockManager->initStocks([$sku => 10]);
        $this->stockManager->delStock($sku);

        $this->assertNull($this->stockManager->getStock($sku)['stock']);
        $this->assertKeyExists($this->testPrefix . $sku . ':soldout', false);

        $res = $this->stockManager->incrStock($sku, 5);
        $this->assertEquals(RedisStock::CODE_ERR_NOT_EXISTS, $res['code']);
    }

    /**
     * 测试：SKU 包含特殊字符及 Hash Tag
     * 预期：系统应能正确处理包含大括号、井号等特殊字符的 SKU（确保集群 KeySlot 一致性）
     */
    public function testSpecialCharacters()
    {
        $sku = 'PROD:123#{HASH}';
        $this->stockManager->initStocks([$sku => 100]);
        $this->assertEquals(100, $this->stockManager->getStock($sku)['stock']);
    }

    /**
     * 测试：高频串行模拟
     * 预期：初始化 10 个库存，执行 15 次扣减，严格保证只有前 10 次成功，后 5 次失败，且最终库存为 0
     */
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
}