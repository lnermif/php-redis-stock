<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisStockSalesManager;
use Nermif\StockSalesCodes;
use Nermif\RedisStock;
use Redis;
use Psr\Log\NullLogger;

class RedisStockSalesManagerTest extends TestCase
{
    private $redis;
    private $manager;
    private $testPrefix = '{test:manager}:';

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

    // -------------------------------------------------------------------------
    // 1. 返回格式规范验证
    // -------------------------------------------------------------------------

    public function testResponseFormat(): void
    {
        $sku = 'FORMAT_SKU';
        $this->manager->initStocks([$sku => 10]);

        $result = $this->manager->purchase($sku, 'U1', 1, 100, 'ORDER_RESP');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);

        $this->assertIsBool($result['success']);
        $this->assertIsInt($result['code']);
        $this->assertIsString($result['message']);
        $this->assertIsArray($result['data']);

        // 成功时 data 应包含必要字段
        if ($result['success']) {
            $this->assertArrayHasKey('sku', $result['data']);
            $this->assertArrayHasKey('user_id', $result['data']);
            $this->assertArrayHasKey('order_id', $result['data']);
            $this->assertArrayHasKey('total_sales', $result['data']);
        }
    }

    // -------------------------------------------------------------------------
    // 2. 基本购买与取消流程
    // -------------------------------------------------------------------------

    public function testPurchaseSuccessAndCancel(): void
    {
        $sku = 'SKU_BUY';
        $userId = 'USER_BUY';
        $orderId = 'ORDER_BUY_001';

        $this->manager->initStocks([$sku => 10]);

        // 购买成功
        $purchase = $this->manager->purchase($sku, $userId, 2, 1999, $orderId);
        $this->assertTrue($purchase['success']);
        $this->assertEquals(StockSalesCodes::CODE_SUCCESS, $purchase['code']);
        $this->assertEquals(2, $purchase['data']['total_sales']);
        $this->assertEquals($sku, $purchase['data']['sku']);

        // 验证库存减少
        $stock = $this->manager->getStock($sku);
        $this->assertEquals(8, $stock['data']['stock']);

        // 取消订单
        $cancel = $this->manager->cancel($sku, 2, 1999, $orderId);
        $this->assertTrue($cancel['success']);
        $this->assertEquals(StockSalesCodes::CODE_SUCCESS, $cancel['code']);

        // 库存回滚
        $stockAfter = $this->manager->getStock($sku);
        $this->assertEquals(10, $stockAfter['data']['stock']);
    }

    public function testPurchaseInsufficientStock(): void
    {
        $sku = 'SKU_LOW';
        $this->manager->initStocks([$sku => 3]);

        $result = $this->manager->purchase($sku, 'U1', 5, 500, 'ORDER_LOW');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INSUFFICIENT, $result['code']);
        $this->assertEquals(3, $result['data']['remain']);
    }

    public function testPurchaseNotInitialized(): void
    {
        $result = $this->manager->purchase('SKU_NO_INIT', 'U1', 1, 100, 'ORDER_NO_INIT');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_NOT_EXISTS, $result['code']);
    }

    public function testPurchaseLimitExceeded(): void
    {
        $sku = 'SKU_LIMIT';
        $userId = 'USER_LIMIT';
        $limit = 2;

        $this->manager->initStocks([$sku => 10]);

        // 第一单成功
        $result1 = $this->manager->purchase($sku, $userId, 2, 200, 'ORDER_L1', $limit);
        $this->assertTrue($result1['success']);

        // 第二单超过限购
        $result2 = $this->manager->purchase($sku, $userId, 1, 100, 'ORDER_L2', $limit);
        $this->assertFalse($result2['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_LIMIT_EXCEEDED, $result2['code']);
        $this->assertEquals(0, $result2['data']['remaining_limit']);
    }

    public function testPurchaseIdempotency(): void
    {
        $sku = 'SKU_IDEM';
        $orderId = 'ORDER_IDEM';

        $this->manager->initStocks([$sku => 5]);

        $result1 = $this->manager->purchase($sku, 'U1', 1, 100, $orderId);
        $this->assertTrue($result1['success']);

        $result2 = $this->manager->purchase($sku, 'U2', 2, 200, $orderId);
        $this->assertFalse($result2['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_ALREADY_PROCESSED, $result2['code']);
    }

    public function testCancelOrderAlreadyCanceled(): void
    {
        $sku = 'SKU_CANCEL';
        $orderId = 'ORDER_CANCEL';

        $this->manager->initStocks([$sku => 5]);
        $this->manager->purchase($sku, 'U1', 2, 200, $orderId);
        $this->manager->cancel($sku, 2, 200, $orderId);

        // 再次取消
        $cancelAgain = $this->manager->cancel($sku, 2, 200, $orderId);
        $this->assertFalse($cancelAgain['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_ORDER_CANCELED, $cancelAgain['code']);
    }

    public function testCancelNonExistentOrder(): void
    {
        $result = $this->manager->cancel('SKU_ANY', 1, 100, 'ORDER_NOT_EXIST');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_ORDER_NOT_PROCESSED, $result['code']);
    }

    // -------------------------------------------------------------------------
    // 3. 参数校验
    // -------------------------------------------------------------------------

    public function testPurchaseInvalidSku(): void
    {
        $result = $this->manager->purchase('sku:bad', 'U1', 1, 100, 'O1');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('SKU 包含非法字符', $result['message']);
    }

    public function testPurchaseInvalidUserId(): void
    {
        $result = $this->manager->purchase('SKU_OK', 'user:bad', 1, 100, 'O1');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('用户ID包含非法字符', $result['message']);
    }

    public function testPurchaseEmptyOrderId(): void
    {
        $this->manager->initStocks(['SKU_EMPTY' => 10]);
        $result = $this->manager->purchase('SKU_EMPTY', 'U1', 1, 100, '');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('订单ID不能为空', $result['message']);
    }

    public function testPurchaseNegativeLimit(): void
    {
        $this->manager->initStocks(['SKU_NEG' => 10]);
        $result = $this->manager->purchase('SKU_NEG', 'U1', 1, 100, 'O_NEG', -1);
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('限购数量不能为负数', $result['message']);
    }

    public function testPurchaseZeroQuantity(): void
    {
        $this->manager->initStocks(['SKU_ZQ' => 10]);
        $result = $this->manager->purchase('SKU_ZQ', 'U1', 0, 100, 'O_ZQ');
        // 底层应返回数量无效
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testPurchaseNegativeAmount(): void
    {
        $this->manager->initStocks(['SKU_NEGAMT' => 10]);
        $result = $this->manager->purchase('SKU_NEGAMT', 'U1', 1, -100, 'O_NEGAMT');
        $this->assertFalse($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_INVALID_AMOUNT, $result['code']);
    }

    // -------------------------------------------------------------------------
    // 4. 辅助操作测试
    // -------------------------------------------------------------------------

    public function testInitStocksAndAddStock(): void
    {
        $sku = 'SKU_INIT';
        $initResult = $this->manager->initStocks([$sku => 10]);
        $this->assertEquals(1, $initResult['data']['initialized_count']);

        $stock = $this->manager->getStock($sku);
        $this->assertEquals(10, $stock['data']['stock']);

        $addResult = $this->manager->addStock($sku, 5);
        $this->assertTrue($addResult['success']);
        $this->assertEquals(15, $addResult['data']['remain']);

        // 对未初始化的 SKU 补货
        $addGhost = $this->manager->addStock('SKU_GHOST', 5);
        $this->assertFalse($addGhost['success']);
        $this->assertEquals(StockSalesCodes::CODE_ERR_NOT_EXISTS, $addGhost['code']);
    }

    public function testIsSoldOut(): void
    {
        $sku = 'SKU_SOLDOUT';
        $this->manager->initStocks([$sku => 0]);
        $result = $this->manager->isSoldOut($sku);
        $this->assertTrue($result['data']['soldOut']);

        $this->manager->addStock($sku, 1);
        $result2 = $this->manager->isSoldOut($sku);
        $this->assertFalse($result2['data']['soldOut']);
    }

    public function testMonitor(): void
    {
        $sku = 'SKU_MONITOR';
        $this->manager->initStocks([$sku => 5]);
        $res = $this->manager->monitor($sku);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['data']['consistency']);
        $this->assertEquals(5, $res['data']['stock']);
    }

    public function testRepair(): void
    {
        $sku = 'SKU_REPAIR';
        $this->manager->initStocks([$sku => 5]);
        // 手动破坏一致性
        $this->redis->set($this->testPrefix . $sku . ':soldout', 1);

        $repair = $this->manager->repair($sku);
        $this->assertEquals(1, $repair['data']['repair_code']);
        $this->assertStringContainsString('removed invalid soldout marker', $repair['message']);
    }

    // -------------------------------------------------------------------------
    // 5. 统一返回结构错误场景
    // -------------------------------------------------------------------------

    public function testAllErrorResponsesHaveSuccessFalse(): void
    {
        $sku = 'ERR_TEST';
        $this->manager->initStocks([$sku => 2]);

        $methods = [
            'purchase' => ['ERR_TEST', 'U1', 5, 100, 'O1'],
            'addStock' => ['GHOST', 5],
            'cancel' => ['ERR_TEST', 1, 100, 'O_NO'],
        ];

        // purchase 库存不足，必然是 success=false
        $purchaseRes = $this->manager->purchase($sku, 'U1', 5, 100, 'O_INS');
        $this->assertFalse($purchaseRes['success']);

        // addStock 未初始化
        $addRes = $this->manager->addStock('GHOST', 5);
        $this->assertFalse($addRes['success']);

        // cancel 未处理订单
        $cancelRes = $this->manager->cancel($sku, 1, 100, 'ORDER_NOT_DONE');
        $this->assertFalse($cancelRes['success']);
    }

    /**
     * 测试超长合法 ID（1000 字符）是否能正常购买
     */
    public function testPurchaseWithVeryLongIds(): void
    {
        $longSku = str_repeat('A', 1000);
        $longUserId = str_repeat('U', 1000);
        $longOrderId = str_repeat('O', 1000);

        // 确保库存存在
        $this->manager->initStocks([$longSku => 5]);

        $result = $this->manager->purchase($longSku, $longUserId, 1, 100, $longOrderId);
        $this->assertTrue($result['success']);
        $this->assertEquals(StockSalesCodes::CODE_SUCCESS, $result['code']);
        // 可选：验证 data 中的 SKU 等字段与原值一致
        $this->assertEquals($longSku, $result['data']['sku']);
    }
}