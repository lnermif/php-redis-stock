<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisSales;
use Redis;
use Psr\Log\NullLogger;

/**
 * RedisSales 生产级全路径单元测试套件
 * 覆盖：购买记录、限购逻辑、幂等性、销售额统计、排行榜、数据清理、边界异常
 */
class RedisSalesTest extends TestCase
{
    private $redis;
    private $salesManager;
    private $testPrefix = '{test:sales}:';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(15);

        $this->salesManager = new RedisSales($this->redis, $this->testPrefix, new NullLogger());

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
    // 1. 购买记录基础测试
    // -------------------------------------------------------------------------

    public function testRecordPurchaseSuccess()
    {
        $sku = 'SKU001';
        $userId = 'USER001';
        $orderId = 'ORDER001';

        $result = $this->salesManager->recordPurchase($sku, $userId, 2, 9990, $orderId);

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(2, $result['total_sales']);
        $this->assertNull($result['remaining_limit']);
    }

    public function testRecordPurchaseAccumulative()
    {
        $sku = 'SKU002';

        $this->salesManager->recordPurchase($sku, 'USER001', 1, 5000, 'ORDER001');
        $result = $this->salesManager->recordPurchase($sku, 'USER002', 3, 15000, 'ORDER002');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(4, $result['total_sales']);

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertEquals(4, $countResult['data']);
    }

    public function testRecordPurchaseZeroAmount()
    {
        $sku = 'FREE_ITEM';
        $result = $this->salesManager->recordPurchase($sku, 'USER001', 1, 0, 'ORDER_FREE');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $amountResult = $this->salesManager->getSalesAmount($sku);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $amountResult['code']);
        $this->assertEquals(0, $amountResult['data']);
    }

    // -------------------------------------------------------------------------
    // 2. 参数验证与边界情况
    // -------------------------------------------------------------------------

    public function testRecordPurchaseZeroQuantity()
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', 0, 1000, 'ORDER003');
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('数量无效', $result['message']);
    }

    public function testRecordPurchaseNegativeQuantity()
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', -1, 1000, 'ORDER003');
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testRecordPurchaseNegativeAmount()
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', 1, -1000, 'ORDER003');
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_AMOUNT, $result['code']);
        $this->assertStringContainsString('金额无效', $result['message']); // 修改断言
    }

    public function testRecordPurchaseSmallAmount()
    {
        $sku = 'SKU_TINY';
        $this->salesManager->recordPurchase($sku, 'USER001', 1, 1, 'ORDER_TINY1');
        $this->salesManager->recordPurchase($sku, 'USER002', 1, 2, 'ORDER_TINY2');

        $amountResult = $this->salesManager->getSalesAmount($sku);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $amountResult['code']);
        $this->assertEquals(3, $amountResult['data']); // 改为整数 3 分
    }

    public function testRecordPurchaseLargeAmount()
    {
        $sku = 'SKU_EXPENSIVE';
        $result = $this->salesManager->recordPurchase($sku, 'USER001', 1, 99999999, 'ORDER_EXP');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $amountResult = $this->salesManager->getSalesAmount($sku);
        $this->assertEquals(99999999, $amountResult['data']);
    }

    public function testRecordPurchaseLargeQuantity()
    {
        $sku = 'SKU_BULK';
        $result = $this->salesManager->recordPurchase($sku, 'USER001', 10000, 10000000, 'ORDER_BULK');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(10000, $result['total_sales']);
    }

    // -------------------------------------------------------------------------
    // 3. 幂等性测试
    // -------------------------------------------------------------------------

    public function testRecordPurchaseDuplicateOrderId()
    {
        $sku = 'SKU_IDEM';
        $orderId = 'ORDER_IDEM_001';

        $result1 = $this->salesManager->recordPurchase($sku, 'USER001', 1, 1000, $orderId);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result1['code']);

        $result2 = $this->salesManager->recordPurchase($sku, 'USER002', 5, 5000, $orderId);
        $this->assertEquals(RedisSales::CODE_ERR_ALREADY_PROCESSED, $result2['code']);
        $this->assertStringContainsString('订单已处理', $result2['message']);

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(1, $countResult['data']);
    }

    public function testRecordPurchaseDifferentOrderId()
    {
        $sku = 'SKU_DIFF';

        $result1 = $this->salesManager->recordPurchase($sku, 'USER001', 1, 1000, 'ORDER_A');
        $result2 = $this->salesManager->recordPurchase($sku, 'USER001', 1, 1000, 'ORDER_B');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result1['code']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result2['code']);

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(2, $countResult['data']);
    }

    public function testRecordPurchaseAfterOrderExpire()
    {
        $sku = 'SKU_EXPIRE';
        $orderId = 'ORDER_EXPIRE_001';

        $orderKey = $this->testPrefix . 'order:' . $orderId;
        $this->redis->setex($orderKey, 1, '1');
        sleep(2);

        $result = $this->salesManager->recordPurchase($sku, 'USER001', 1, 1000, $orderId);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
    }

    // -------------------------------------------------------------------------
    // 4. 限购逻辑测试
    // -------------------------------------------------------------------------

    public function testRecordPurchaseWithinLimit()
    {
        $sku = 'SKU_LIMIT';
        $limitPerUser = 5;

        $result = $this->salesManager->recordPurchase($sku, 'USER001', 3, 3000, 'ORDER_LIM1', $limitPerUser);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
    }

    public function testRecordPurchaseExactlyAtLimit()
    {
        $sku = 'SKU_EXACT';
        $limitPerUser = 5;

        $this->salesManager->recordPurchase($sku, 'USER001', 3, 3000, 'ORDER_EX1', $limitPerUser);
        $result = $this->salesManager->recordPurchase($sku, 'USER001', 2, 2000, 'ORDER_EX2', $limitPerUser);

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $countResult = $this->salesManager->getUserPurchaseCount($sku, 'USER001');
        $this->assertEquals(5, $countResult['data']);
    }

    public function testRecordPurchaseExceedLimit()
    {
        $sku = 'SKU_OVER';
        $limitPerUser = 5;

        $this->salesManager->recordPurchase($sku, 'USER001', 3, 3000, 'ORDER_OV1', $limitPerUser);
        $result = $this->salesManager->recordPurchase($sku, 'USER001', 3, 3000, 'ORDER_OV2', $limitPerUser);

        $this->assertEquals(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result['code']);
        $this->assertEquals(2, $result['remaining_limit']);
        $this->assertStringContainsString('还可购买 2 件', $result['message']);
    }

    public function testRecordPurchaseFirstTimeExceedLimit()
    {
        $sku = 'SKU_FIRST';
        $limitPerUser = 3;

        $result = $this->salesManager->recordPurchase($sku, 'USER001', 5, 5000, 'ORDER_FIRST', $limitPerUser);

        $this->assertEquals(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result['code']);
        $this->assertEquals(3, $result['remaining_limit']);
    }

    public function testRecordPurchaseNoLimit()
    {
        $sku = 'SKU_NOLIMIT';

        $result1 = $this->salesManager->recordPurchase($sku, 'USER001', 100, 100000, 'ORDER_NL1', 0);
        $result2 = $this->salesManager->recordPurchase($sku, 'USER001', 200, 200000, 'ORDER_NL2', 0);

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result1['code']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result2['code']);

        $countResult = $this->salesManager->getUserPurchaseCount($sku, 'USER001');
        $this->assertEquals(300, $countResult['data']);
    }

    public function testRecordPurchaseLimitPerUserIndependent()
    {
        $sku = 'SKU_INDEP';
        $limitPerUser = 2;

        $this->salesManager->recordPurchase($sku, 'USER_A', 2, 2000, 'ORDER_IA1', $limitPerUser);
        $resultA = $this->salesManager->recordPurchase($sku, 'USER_A', 1, 1000, 'ORDER_IA2', $limitPerUser);
        $resultB = $this->salesManager->recordPurchase($sku, 'USER_B', 2, 2000, 'ORDER_IB1', $limitPerUser);

        $this->assertEquals(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $resultA['code']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $resultB['code']);
    }

    // -------------------------------------------------------------------------
    // 5. 用户购买记录查询
    // -------------------------------------------------------------------------

    public function testGetUserPurchases()
    {
        $this->salesManager->recordPurchase('SKU_A', 'USER_Q', 2, 2000, 'ORDER_Q1');
        $this->salesManager->recordPurchase('SKU_B', 'USER_Q', 3, 3000, 'ORDER_Q2');
        $this->salesManager->recordPurchase('SKU_C', 'USER_Q', 1, 1000, 'ORDER_Q3');

        $result = $this->salesManager->getUserPurchases('USER_Q');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $purchases = $result['data'];

        $this->assertCount(3, $purchases);
        $this->assertEquals(2, $purchases['SKU_A']);
        $this->assertEquals(3, $purchases['SKU_B']);
        $this->assertEquals(1, $purchases['SKU_C']);
    }

    public function testGetUserPurchasesNewUser()
    {
        $result = $this->salesManager->getUserPurchases('NEW_USER');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
        $this->assertEmpty($result['data']);
    }

    public function testGetUserPurchasesSameSkuMultiple()
    {
        $this->salesManager->recordPurchase('SKU_X', 'USER_X', 2, 2000, 'ORDER_X1');
        $this->salesManager->recordPurchase('SKU_X', 'USER_X', 3, 3000, 'ORDER_X2');

        $result = $this->salesManager->getUserPurchases('USER_X');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $purchases = $result['data'];

        $this->assertCount(1, $purchases);
        $this->assertEquals(5, $purchases['SKU_X']);
    }

    public function testGetUserPurchaseCount()
    {
        $this->salesManager->recordPurchase('SKU_CNT', 'USER_CNT', 5, 5000, 'ORDER_CNT1');

        $countResult = $this->salesManager->getUserPurchaseCount('SKU_CNT', 'USER_CNT');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertEquals(5, $countResult['data']);
    }

    public function testGetUserPurchaseCountNotPurchased()
    {
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_NONE', 'USER_NONE');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertEquals(0, $countResult['data']);
    }

    // -------------------------------------------------------------------------
    // 6. 剩余限购数量查询
    // -------------------------------------------------------------------------

    public function testGetRemainingLimit()
    {
        $this->salesManager->recordPurchase('SKU_REM', 'USER_REM', 3, 3000, 'ORDER_REM1');

        $remainingResult = $this->salesManager->getRemainingLimit('SKU_REM', 'USER_REM', 10);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertEquals(7, $remainingResult['data']);
    }

    public function testGetRemainingLimitReached()
    {
        $this->salesManager->recordPurchase('SKU_FULL', 'USER_FULL', 5, 5000, 'ORDER_FULL1');

        $remainingResult = $this->salesManager->getRemainingLimit('SKU_FULL', 'USER_FULL', 5);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertEquals(0, $remainingResult['data']);
    }

    public function testGetRemainingLimitNoLimit()
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_ANY', 'USER_ANY', 0);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertEquals(PHP_INT_MAX, $remainingResult['data']);
    }

    public function testGetRemainingLimitNegativeLimit()
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_ANY', 'USER_ANY', -1);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertEquals(PHP_INT_MAX, $remainingResult['data']);
    }

    // -------------------------------------------------------------------------
    // 7. 销售统计查询
    // -------------------------------------------------------------------------

    public function testGetSalesCount()
    {
        $this->salesManager->recordPurchase('SKU_STAT', 'U1', 2, 2000, 'O1');
        $this->salesManager->recordPurchase('SKU_STAT', 'U2', 3, 3000, 'O2');

        $countResult = $this->salesManager->getSalesCount('SKU_STAT');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertEquals(5, $countResult['data']);
    }

    public function testGetSalesCountNotExists()
    {
        $countResult = $this->salesManager->getSalesCount('SKU_GHOST');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertEquals(0, $countResult['data']);
    }

    public function testGetSalesAmount()
    {
        $this->salesManager->recordPurchase('SKU_AMT', 'U1', 1, 9990, 'O1');
        $this->salesManager->recordPurchase('SKU_AMT', 'U2', 2, 19980, 'O2');

        $amountResult = $this->salesManager->getSalesAmount('SKU_AMT');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $amountResult['code']);
        $this->assertEquals(29970, $amountResult['data']); // 9990 + 19980 = 29970
    }

    public function testGetSalesAmountNotExists()
    {
        $amountResult = $this->salesManager->getSalesAmount('SKU_GHOST');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $amountResult['code']);
        $this->assertEquals(0, $amountResult['data']);
    }

    public function testGetMultipleSalesCounts()
    {
        $this->salesManager->recordPurchase('SKU_M1', 'U1', 5, 5000, 'O1');
        $this->salesManager->recordPurchase('SKU_M2', 'U1', 3, 3000, 'O2');

        $countsResult = $this->salesManager->getMultipleSalesCounts(['SKU_M1', 'SKU_M2', 'SKU_M3']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countsResult['code']);
        $counts = $countsResult['data'];

        $this->assertEquals(5, $counts['SKU_M1']);
        $this->assertEquals(3, $counts['SKU_M2']);
        $this->assertEquals(0, $counts['SKU_M3']);
    }

    public function testGetMultipleSalesCountsEmpty()
    {
        $countsResult = $this->salesManager->getMultipleSalesCounts([]);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $countsResult['code']);
        $this->assertIsArray($countsResult['data']);
        $this->assertEmpty($countsResult['data']);
    }

    // -------------------------------------------------------------------------
    // 8. 排行榜测试
    // -------------------------------------------------------------------------

    public function testGetSalesLeaderboard()
    {
        $this->salesManager->recordPurchase('SKU_TOP1', 'U1', 10, 10000, 'O1');
        $this->salesManager->recordPurchase('SKU_TOP2', 'U1', 5, 5000, 'O2');
        $this->salesManager->recordPurchase('SKU_TOP3', 'U1', 8, 8000, 'O3');

        $leaderboardResult = $this->salesManager->getSalesLeaderboard(0, 9, true);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $leaderboardResult['code']);
        $leaderboard = $leaderboardResult['data'];

        $this->assertIsArray($leaderboard);
        $this->assertGreaterThanOrEqual(3, count($leaderboard));
        $firstSku = array_key_first($leaderboard);
        $this->assertEquals('SKU_TOP1', $firstSku);
        $this->assertEquals(10, $leaderboard[$firstSku]);
    }

    public function testGetAmountLeaderboard()
    {
        $this->salesManager->recordPurchase('SKU_RICH1', 'U1', 1, 100000, 'O1');
        $this->salesManager->recordPurchase('SKU_RICH2', 'U1', 10, 5000, 'O2');
        $this->salesManager->recordPurchase('SKU_RICH3', 'U1', 5, 10000, 'O3');

        $leaderboardResult = $this->salesManager->getAmountLeaderboard(0, 9, true);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $leaderboardResult['code']);
        $leaderboard = $leaderboardResult['data'];

        $this->assertIsArray($leaderboard);
        $firstSku = array_key_first($leaderboard);
        $this->assertEquals('SKU_RICH1', $firstSku);
        $this->assertEquals(100000, $leaderboard[$firstSku]); // 分，而不是元
    }

    public function testLeaderboardPagination()
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->salesManager->recordPurchase("SKU_{$i}", 'U1', $i, $i * 1000, "O_{$i}");
        }

        $top5Result = $this->salesManager->getSalesLeaderboard(0, 4, false);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $top5Result['code']);
        $top5 = $top5Result['data'];
        $this->assertCount(5, $top5);

        $next5Result = $this->salesManager->getSalesLeaderboard(5, 9, false);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $next5Result['code']);
        $next5 = $next5Result['data'];
        $this->assertCount(5, $next5);
    }

    public function testLeaderboardWithoutScores()
    {
        $this->salesManager->recordPurchase('SKU_A', 'U1', 5, 5000, 'O1');
        $this->salesManager->recordPurchase('SKU_B', 'U1', 3, 3000, 'O2');

        $leaderboardResult = $this->salesManager->getSalesLeaderboard(0, 9, false);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $leaderboardResult['code']);
        $leaderboard = $leaderboardResult['data'];

        $this->assertIsArray($leaderboard);
        $this->assertEquals('SKU_A', $leaderboard[0]);
        $this->assertEquals('SKU_B', $leaderboard[1]);
    }

    public function testEmptyLeaderboard()
    {
        $leaderboardResult = $this->salesManager->getSalesLeaderboard();
        $this->assertEquals(RedisSales::CODE_SUCCESS, $leaderboardResult['code']);
        $this->assertIsArray($leaderboardResult['data']);
        $this->assertEmpty($leaderboardResult['data']);
    }

    // -------------------------------------------------------------------------
    // 9. 订单状态检查
    // -------------------------------------------------------------------------

    public function testIsOrderProcessed()
    {
        $this->salesManager->recordPurchase('SKU_CHK', 'U1', 1, 1000, 'ORDER_CHK');

        $result = $this->salesManager->isOrderProcessed('ORDER_CHK');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertTrue($result['data']);
    }

    public function testIsOrderNotProcessed()
    {
        $result = $this->salesManager->isOrderProcessed('ORDER_NONEXIST');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertFalse($result['data']);
    }

    // -------------------------------------------------------------------------
    // 10. 数据清理测试
    // -------------------------------------------------------------------------

    public function testClearSalesData()
    {
        $sku = 'SKU_CLEAR';
        $this->salesManager->recordPurchase($sku, 'U1', 5, 5000, 'O_CLR1');
        $this->salesManager->recordPurchase($sku, 'U2', 3, 3000, 'O_CLR2');

        $result = $this->salesManager->clearSalesData($sku);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertGreaterThan(0, $result['deleted']);

        $this->assertEquals(0, $this->salesManager->getSalesCount($sku)['data']);
        $this->assertEquals(0, $this->salesManager->getSalesAmount($sku)['data']);
        $this->assertEquals(0, $this->salesManager->getUserPurchaseCount($sku, 'U1')['data']);

        $leaderboardResult = $this->salesManager->getSalesLeaderboard(0, 100, false);
        $this->assertNotContains($sku, $leaderboardResult['data']);
    }

    public function testClearSalesDataNotExists()
    {
        $result = $this->salesManager->clearSalesData('SKU_GHOST');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(0, $result['deleted']);
    }

    // -------------------------------------------------------------------------
    // 11. 特殊字符与边界场景
    // -------------------------------------------------------------------------

    public function testSpecialCharactersInSku()
    {
        $sku = 'PROD:123#{TEST}';
        $result = $this->salesManager->recordPurchase($sku, 'USER_SPEC', 1, 1000, 'ORDER_SPEC');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(1, $countResult['data']);
    }

    public function testSpecialCharactersInUserId()
    {
        $userId = 'user:test#123';
        $result = $this->salesManager->recordPurchase('SKU_USR', $userId, 1, 1000, 'ORDER_USR');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $purchasesResult = $this->salesManager->getUserPurchases($userId);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $purchasesResult['code']);
        $this->assertCount(1, $purchasesResult['data']);
    }

    public function testSpecialCharactersInOrderId()
    {
        $orderId = 'ORD:2024#TEST_001';
        $result = $this->salesManager->recordPurchase('SKU_ORD', 'U1', 1, 1000, $orderId);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $orderResult = $this->salesManager->isOrderProcessed($orderId);
        $this->assertTrue($orderResult['data']);
    }

    public function testEmptyStringParameters()
    {
        $result = $this->salesManager->recordPurchase('', 'U1', 1, 1000, 'ORDER_EMPTY');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $result2 = $this->salesManager->recordPurchase('SKU_EMPTY', '', 1, 1000, 'ORDER_EMPTY2');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result2['code']);

        $result3 = $this->salesManager->recordPurchase('SKU_EMPTY', 'U1', 1, 1000, '');
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result3['code']);
    }

    public function testLongStrings()
    {
        $longSku = str_repeat('A', 100);
        $longUserId = str_repeat('U', 100);
        $longOrderId = str_repeat('O', 100);

        $result = $this->salesManager->recordPurchase($longSku, $longUserId, 1, 1000, $longOrderId);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
    }

    // -------------------------------------------------------------------------
    // 12. 并发安全性模拟
    // -------------------------------------------------------------------------

    public function testHighFrequencyPurchaseBySameUser()
    {
        $sku = 'SKU_FREQ';
        $limitPerUser = 10;
        $successCount = 0;

        for ($i = 0; $i < 15; $i++) {
            $result = $this->salesManager->recordPurchase($sku, 'USER_FREQ', 1, 1000, "ORDER_FREQ_{$i}", $limitPerUser);
            if ($result['code'] === RedisSales::CODE_SUCCESS) {
                $successCount++;
            }
        }

        $this->assertEquals(10, $successCount);
        $countResult = $this->salesManager->getUserPurchaseCount($sku, 'USER_FREQ');
        $this->assertEquals(10, $countResult['data']);
    }

    public function testMultipleUsersPurchaseSameSku()
    {
        $sku = 'SKU_MULTI';
        $limitPerUser = 3;

        for ($u = 1; $u <= 5; $u++) {
            for ($i = 0; $i < 5; $i++) {
                $this->salesManager->recordPurchase($sku, "USER_{$u}", 1, 1000, "ORDER_M_{$u}_{$i}", $limitPerUser);
            }
        }

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(15, $countResult['data']);
    }

    public function testMassOrderIdempotency()
    {
        $sku = 'SKU_MASS';

        for ($i = 0; $i < 100; $i++) {
            $this->salesManager->recordPurchase($sku, "USER_{$i}", 1, 1000, "ORDER_MASS_{$i}");
        }

        $duplicateCount = 0;
        for ($i = 0; $i < 100; $i++) {
            $result = $this->salesManager->recordPurchase($sku, "USER_{$i}", 1, 1000, "ORDER_MASS_{$i}");
            if ($result['code'] === RedisSales::CODE_ERR_ALREADY_PROCESSED) {
                $duplicateCount++;
            }
        }

        $this->assertEquals(100, $duplicateCount);
        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals(100, $countResult['data']);
    }

    // -------------------------------------------------------------------------
    // 13. 数据类型与精度测试
    // -------------------------------------------------------------------------

    public function testAmountPrecisionAccumulation()
    {
        $sku = 'SKU_PREC';

        for ($i = 0; $i < 100; $i++) {
            $this->salesManager->recordPurchase($sku, "USER_P{$i}", 1, 1, "ORDER_P{$i}");
        }

        $amountResult = $this->salesManager->getSalesAmount($sku);
        $this->assertEquals(100, $amountResult['data']); // 100 分
    }

    public function testIntegerBoundaryValues()
    {
        $sku = 'SKU_INT_MAX';
        $largeQuantity = 1000000;
        $result = $this->salesManager->recordPurchase($sku, 'USER_BIG', $largeQuantity, 1000000000, 'ORDER_BIG');

        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);

        $countResult = $this->salesManager->getSalesCount($sku);
        $this->assertEquals($largeQuantity, $countResult['data']);
    }

    // =========================================================================
    // 以下为补充的边界测试方法（已删除精度校验，保持整数分）
    // =========================================================================

    /**
     * 测试：recordPurchaseWithStock 库存不足联动
     */
    public function testRecordPurchaseWithStockInsufficient()
    {
        $sku = 'STOCK_INSUF';
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 2]);

        $result = $this->salesManager->recordPurchaseWithStock($sku, 'USER_INS', 5, 5000, 'ORDER_INS', 0);
        $this->assertEquals(RedisSales::CODE_ERR_INSUFFICIENT, $result['code']);
        $this->assertEquals(2, $result['remain']);
        $this->assertStringContainsString('库存不足，剩余 2 件', $result['message']);
    }

    /**
     * 测试：recordPurchaseWithStock 库存未初始化
     */
    public function testRecordPurchaseWithStockNotExists()
    {
        $result = $this->salesManager->recordPurchaseWithStock('SKU_MISS', 'U1', 1, 1000, 'ORDER_MISS', 0);
        $this->assertEquals(RedisSales::CODE_ERR_NOT_EXISTS, $result['code']);
        $this->assertStringContainsString('商品库存未初始化', $result['message']);
    }

    /**
     * 测试：getSalesLeaderboard 负数 start/end 参数
     */
    public function testSalesLeaderboardNegativeStart()
    {
        $this->salesManager->recordPurchase('SKU_NEG', 'U1', 5, 5000, 'ORDER_NEG');
        $result = $this->salesManager->getSalesLeaderboard(-5, 5, false);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
    }

    /**
     * 测试：getRemainingLimit 用户未购买过（limit > 0）
     */
    public function testGetRemainingLimitNeverBought()
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_NEW', 'NEW_USER', 10);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertEquals(10, $remainingResult['data']);
    }

    /**
     * 测试：getUserPurchaseCount 返回值结构校验
     */
    public function testGetUserPurchaseCountStructure()
    {
        $this->salesManager->recordPurchase('SKU_STRUCT', 'USER_STRUCT', 3, 3000, 'ORDER_STRUCT');
        $result = $this->salesManager->getUserPurchaseCount('SKU_STRUCT', 'USER_STRUCT');
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(3, $result['data']);
    }

    /**
     * 测试：getMultipleSalesCounts 单个 SKU
     */
    public function testGetMultipleSalesCountsSingleSku()
    {
        $this->salesManager->recordPurchase('SKU_SINGLE', 'U1', 7, 7000, 'ORDER_SINGLE');
        $result = $this->salesManager->getMultipleSalesCounts(['SKU_SINGLE']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals(7, $result['data']['SKU_SINGLE']);
    }

    /**
     * 测试：clearSalesData 后验证排行榜确实移除
     */
    public function testClearSalesDataRemovesFromLeaderboard()
    {
        $sku = 'SKU_LEADER';
        $this->salesManager->recordPurchase($sku, 'U1', 5, 5000, 'ORDER_L1');
        $this->salesManager->clearSalesData($sku);

        $leaderboardResult = $this->salesManager->getSalesLeaderboard(0, 100, false);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $leaderboardResult['code']);
        $this->assertNotContains($sku, $leaderboardResult['data']);

        $amountLeaderboardResult = $this->salesManager->getAmountLeaderboard(0, 100, false);
        $this->assertNotContains($sku, $amountLeaderboardResult['data']);
    }

    /**
     * 测试：首次超过限购时 remaining_limit 计算
     */
    public function testRecordPurchaseFirstTimeExceedLimitRemaining()
    {
        $sku = 'FIRST_OVER';
        $limit = 3;
        $result = $this->salesManager->recordPurchase($sku, 'USER_FIRST', 5, 5000, 'ORDER_FIRST_OVER', $limit);
        $this->assertEquals(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result['code']);
        $this->assertEquals(3, $result['remaining_limit']);
    }

    /**
     * 测试：recordPurchaseWithStock 正常扣减库存并记录销售成功
     */
    public function testRecordPurchaseWithStockSuccess()
    {
        $sku = 'STOCK_OK';
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 10]);

        $result = $this->salesManager->recordPurchaseWithStock($sku, 'USER_OK', 3, 2997, 'ORDER_OK', 0);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertEquals(3, $result['total_sales']);
        $this->assertNull($result['remain']);
        $this->assertNull($result['remaining_limit']);

        $stockRes = $stockManager->getStock($sku);
        $this->assertEquals(7, $stockRes['stock']);
        $amountRes = $this->salesManager->getSalesAmount($sku);
        $this->assertEquals(2997, $amountRes['data']);
    }

    // =========================================================================
    // 补充的测试用例（覆盖高、中优先级缺失项）
    // =========================================================================

    /**
     * 测试：recordPurchaseWithStock 数量为 0
     * 预期：返回 CODE_ERR_INVALID_QUANTITY
     */
    public function testRecordPurchaseWithStockZeroQuantity()
    {
        $sku = 'STOCK_ZERO_QTY';
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 10]);

        $result = $this->salesManager->recordPurchaseWithStock($sku, 'USER_ZERO', 0, 1000, 'ORDER_ZERO_QTY', 0);
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('数量无效', $result['message']);
        $this->assertNull($result['remain']);
    }

    /**
     * 测试：recordPurchaseWithStock 数量为负数
     * 预期：返回 CODE_ERR_INVALID_QUANTITY
     */
    public function testRecordPurchaseWithStockNegativeQuantity()
    {
        $sku = 'STOCK_NEG_QTY';
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 10]);

        $result = $this->salesManager->recordPurchaseWithStock($sku, 'USER_NEG', -1, 1000, 'ORDER_NEG_QTY', 0);
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('数量无效', $result['message']);
    }

    /**
     * 测试：recordPurchaseWithStock 金额为负数
     * 预期：返回 CODE_ERR_INVALID_AMOUNT
     */
    public function testRecordPurchaseWithStockNegativeAmount()
    {
        $sku = 'STOCK_NEG_AMT';
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 10]);

        $result = $this->salesManager->recordPurchaseWithStock($sku, 'USER_NEG_AMT', 1, -1000, 'ORDER_NEG_AMT', 0);
        $this->assertEquals(RedisSales::CODE_ERR_INVALID_AMOUNT, $result['code']);
        $this->assertStringContainsString('金额无效', $result['message']);
    }

    /**
     * 测试：recordPurchaseWithStock 限购超限场景
     * 预期：返回 CODE_ERR_LIMIT_EXCEEDED，并返回剩余可购买数量
     */
    public function testRecordPurchaseWithStockExceedLimit()
    {
        $sku = 'STOCK_LIMIT_EXCEED';
        $limitPerUser = 3;
        $stockManager = new \Nermif\RedisStock($this->redis, $this->testPrefix, new NullLogger());
        $stockManager->initStocks([$sku => 10]);

        // 第一次购买 2 件（在限购内）
        $result1 = $this->salesManager->recordPurchaseWithStock($sku, 'USER_LIMIT', 2, 2000, 'ORDER_LIMIT1', $limitPerUser);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result1['code']);

        // 第二次购买 2 件（超过限购，已购 2，限购 3，剩余可买 1）
        $result2 = $this->salesManager->recordPurchaseWithStock($sku, 'USER_LIMIT', 2, 2000, 'ORDER_LIMIT2', $limitPerUser);
        $this->assertEquals(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result2['code']);
        $this->assertEquals(1, $result2['remaining_limit']);
        $this->assertStringContainsString('还可购买 1 件', $result2['message']);
    }

    /**
     * 测试：recordPurchase 返回值结构完整性
     * 预期：返回数组必须包含 code, message, total_sales, remaining_limit 四个键
     */
    public function testRecordPurchaseReturnStructure()
    {
        $sku = 'STRUCT_RECORD';
        $result = $this->salesManager->recordPurchase($sku, 'USER_STRUCT', 1, 1000, 'ORDER_STRUCT');

        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('total_sales', $result);
        $this->assertArrayHasKey('remaining_limit', $result);
    }

    /**
     * 测试：isOrderProcessed 返回值结构完整性
     * 预期：返回数组必须包含 code 和 data
     */
    public function testIsOrderProcessedReturnStructure()
    {
        $orderId = 'ORDER_STRUCT_CHECK';
        $this->salesManager->recordPurchase('SKU_STRUCT_CHECK', 'U1', 1, 1000, $orderId);

        $result = $this->salesManager->isOrderProcessed($orderId);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * 测试：getMultipleSalesCounts 混合存在和不存在的 SKU（已存在类似测试，但单独强化结构验证）
     * 预期：返回数组中已存在的 SKU 有具体数值，不存在的为 0
     */
    public function testGetMultipleSalesCountsMixed()
    {
        $this->salesManager->recordPurchase('SKU_MIX_EXISTS', 'U1', 5, 5000, 'ORDER_MIX1');
        // 不存在的 SKU 为 SKU_MIX_MISSING

        $result = $this->salesManager->getMultipleSalesCounts(['SKU_MIX_EXISTS', 'SKU_MIX_MISSING']);
        $this->assertEquals(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
        $this->assertEquals(5, $result['data']['SKU_MIX_EXISTS']);
        $this->assertEquals(0, $result['data']['SKU_MIX_MISSING']);
    }
}