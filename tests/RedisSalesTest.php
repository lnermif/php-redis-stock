<?php

namespace Nermif\Tests;

use PHPUnit\Framework\TestCase;
use Nermif\RedisSales;
use Nermif\RedisStock;
use Redis;
use Psr\Log\NullLogger;

class RedisSalesTest extends TestCase
{
    private $redis;
    private $salesManager;
    private $stockManager;
    private $testPrefix = '{test:sales}:';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(15);

        $this->salesManager = new RedisSales($this->redis, $this->testPrefix, new NullLogger());
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

    public function testRecordPurchaseSuccess(): void
    {
        $result = $this->salesManager->recordPurchase('SKU001', 'USER001', 2, 9990, 'ORDER001');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertSame(2, $result['total_sales']);
        $this->assertNull($result['remaining_limit']);
    }

    public function testRecordPurchaseAccumulative(): void
    {
        $this->salesManager->recordPurchase('SKU002', 'USER001', 1, 5000, 'ORDER001');
        $result = $this->salesManager->recordPurchase('SKU002', 'USER002', 3, 15000, 'ORDER002');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertSame(4, $result['total_sales']);
        $countResult = $this->salesManager->getSalesCount('SKU002');
        $this->assertSame(4, $countResult['data']);
    }

    public function testRecordPurchaseZeroAmount(): void
    {
        $result = $this->salesManager->recordPurchase('FREE_ITEM', 'USER001', 1, 0, 'ORDER_FREE');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $amountResult = $this->salesManager->getSalesAmount('FREE_ITEM');
        $this->assertSame(0, $amountResult['data']);
    }

    public function testRecordPurchaseZeroQuantity(): void
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', 0, 1000, 'ORDER003');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertStringContainsString('数量无效', $result['message']);
    }

    public function testRecordPurchaseNegativeQuantity(): void
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', -1, 1000, 'ORDER003');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testRecordPurchaseNegativeAmount(): void
    {
        $result = $this->salesManager->recordPurchase('SKU003', 'USER001', 1, -1000, 'ORDER003');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_AMOUNT, $result['code']);
    }

    public function testRecordPurchaseSmallAmount(): void
    {
        $this->salesManager->recordPurchase('SKU_TINY', 'USER001', 1, 1, 'ORDER_TINY1');
        $this->salesManager->recordPurchase('SKU_TINY', 'USER002', 1, 2, 'ORDER_TINY2');
        $amountResult = $this->salesManager->getSalesAmount('SKU_TINY');
        $this->assertSame(3, $amountResult['data']);
    }

    public function testRecordPurchaseLargeAmount(): void
    {
        $result = $this->salesManager->recordPurchase('SKU_EXPENSIVE', 'USER001', 1, 99999999, 'ORDER_EXP');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $amountResult = $this->salesManager->getSalesAmount('SKU_EXPENSIVE');
        $this->assertSame(99999999, $amountResult['data']);
    }

    public function testRecordPurchaseLargeQuantity(): void
    {
        $result = $this->salesManager->recordPurchase('SKU_BULK', 'USER001', 10000, 10000000, 'ORDER_BULK');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertSame(10000, $result['total_sales']);
    }

    public function testRecordPurchaseDuplicateOrderId(): void
    {
        $result1 = $this->salesManager->recordPurchase('SKU_IDEM', 'USER001', 1, 1000, 'ORDER_IDEM_001');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result1['code']);
        $result2 = $this->salesManager->recordPurchase('SKU_IDEM', 'USER002', 5, 5000, 'ORDER_IDEM_001');
        $this->assertSame(RedisSales::CODE_ERR_ALREADY_PROCESSED, $result2['code']);
        $this->assertStringContainsString('订单已处理', $result2['message']);
        $countResult = $this->salesManager->getSalesCount('SKU_IDEM');
        $this->assertSame(1, $countResult['data']);
    }

    public function testRecordPurchaseDifferentOrderId(): void
    {
        $r1 = $this->salesManager->recordPurchase('SKU_DIFF', 'USER001', 1, 1000, 'ORDER_A');
        $r2 = $this->salesManager->recordPurchase('SKU_DIFF', 'USER001', 1, 1000, 'ORDER_B');
        $this->assertSame(RedisSales::CODE_SUCCESS, $r1['code']);
        $this->assertSame(RedisSales::CODE_SUCCESS, $r2['code']);
        $countResult = $this->salesManager->getSalesCount('SKU_DIFF');
        $this->assertSame(2, $countResult['data']);
    }

    public function testRecordPurchaseWithinLimit(): void
    {
        $result = $this->salesManager->recordPurchase('SKU_LIMIT', 'USER001', 3, 3000, 'ORDER_LIM1', 5);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
    }

    public function testRecordPurchaseExactlyAtLimit(): void
    {
        $this->salesManager->recordPurchase('SKU_EXACT', 'USER001', 3, 3000, 'ORDER_EX1', 5);
        $result = $this->salesManager->recordPurchase('SKU_EXACT', 'USER001', 2, 2000, 'ORDER_EX2', 5);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_EXACT', 'USER001');
        $this->assertSame(5, $countResult['data']);
    }

    public function testRecordPurchaseExceedLimit(): void
    {
        $this->salesManager->recordPurchase('SKU_OVER', 'USER001', 3, 3000, 'ORDER_OV1', 5);
        $result = $this->salesManager->recordPurchase('SKU_OVER', 'USER001', 3, 3000, 'ORDER_OV2', 5);
        $this->assertSame(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result['code']);
        $this->assertSame(2, $result['remaining_limit']);
        $this->assertStringContainsString('还可购买 2 件', $result['message']);
    }

    public function testRecordPurchaseFirstTimeExceedLimit(): void
    {
        $result = $this->salesManager->recordPurchase('SKU_FIRST', 'USER001', 5, 5000, 'ORDER_FIRST', 3);
        $this->assertSame(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $result['code']);
        $this->assertSame(3, $result['remaining_limit']);
    }

    public function testRecordPurchaseNoLimit(): void
    {
        $r1 = $this->salesManager->recordPurchase('SKU_NOLIMIT', 'USER001', 100, 100000, 'ORDER_NL1', 0);
        $r2 = $this->salesManager->recordPurchase('SKU_NOLIMIT', 'USER001', 200, 200000, 'ORDER_NL2', 0);
        $this->assertSame(RedisSales::CODE_SUCCESS, $r1['code']);
        $this->assertSame(RedisSales::CODE_SUCCESS, $r2['code']);
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_NOLIMIT', 'USER001');
        $this->assertSame(300, $countResult['data']);
    }

    public function testRecordPurchaseLimitPerUserIndependent(): void
    {
        $this->salesManager->recordPurchase('SKU_INDEP', 'USER_A', 2, 2000, 'ORDER_IA1', 2);
        $rA = $this->salesManager->recordPurchase('SKU_INDEP', 'USER_A', 1, 1000, 'ORDER_IA2', 2);
        $rB = $this->salesManager->recordPurchase('SKU_INDEP', 'USER_B', 2, 2000, 'ORDER_IB1', 2);
        $this->assertSame(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $rA['code']);
        $this->assertSame(RedisSales::CODE_SUCCESS, $rB['code']);
    }

    public function testGetUserPurchases(): void
    {
        $this->salesManager->recordPurchase('SKU_A', 'USER_Q', 2, 2000, 'ORDER_Q1');
        $this->salesManager->recordPurchase('SKU_B', 'USER_Q', 3, 3000, 'ORDER_Q2');
        $this->salesManager->recordPurchase('SKU_C', 'USER_Q', 1, 1000, 'ORDER_Q3');
        $result = $this->salesManager->getUserPurchases('USER_Q');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $purchases = $result['data'];
        $this->assertCount(3, $purchases);
        $this->assertSame(2, $purchases['SKU_A']);
        $this->assertSame(3, $purchases['SKU_B']);
        $this->assertSame(1, $purchases['SKU_C']);
    }

    public function testGetUserPurchasesNewUser(): void
    {
        $result = $this->salesManager->getUserPurchases('NEW_USER');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
        $this->assertEmpty($result['data']);
    }

    public function testGetUserPurchasesSameSkuMultiple(): void
    {
        $this->salesManager->recordPurchase('SKU_X', 'USER_X', 2, 2000, 'ORDER_X1');
        $this->salesManager->recordPurchase('SKU_X', 'USER_X', 3, 3000, 'ORDER_X2');
        $result = $this->salesManager->getUserPurchases('USER_X');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertCount(1, $result['data']);
        $this->assertSame(5, $result['data']['SKU_X']);
    }

    public function testGetUserPurchasesInvalidUser(): void
    {
        $result = $this->salesManager->getUserPurchases('user:bad');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertEmpty($result['data']);
    }

    public function testGetUserPurchaseCount(): void
    {
        $this->salesManager->recordPurchase('SKU_CNT', 'USER_CNT', 5, 5000, 'ORDER_CNT1');
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_CNT', 'USER_CNT');
        $this->assertSame(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertSame(5, $countResult['data']);
    }

    public function testGetUserPurchaseCountNotPurchased(): void
    {
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_NONE', 'USER_NONE');
        $this->assertSame(RedisSales::CODE_SUCCESS, $countResult['code']);
        $this->assertSame(0, $countResult['data']);
    }

    public function testGetUserPurchaseCountInvalidSku(): void
    {
        $result = $this->salesManager->getUserPurchaseCount('bad:sku', 'USER001');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetUserPurchaseCountInvalidUser(): void
    {
        $result = $this->salesManager->getUserPurchaseCount('SKU_OK', 'user:bad');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetRemainingLimit(): void
    {
        $this->salesManager->recordPurchase('SKU_REM', 'USER_REM', 3, 3000, 'ORDER_REM1');
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_REM', 'USER_REM', 10);
        $this->assertSame(RedisSales::CODE_SUCCESS, $remainingResult['code']);
        $this->assertSame(7, $remainingResult['data']);
    }

    public function testGetRemainingLimitReached(): void
    {
        $this->salesManager->recordPurchase('SKU_FULL', 'USER_FULL', 5, 5000, 'ORDER_FULL1');
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_FULL', 'USER_FULL', 5);
        $this->assertSame(0, $remainingResult['data']);
    }

    public function testGetRemainingLimitNoLimit(): void
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_ANY', 'USER_ANY', 0);
        $this->assertSame(PHP_INT_MAX, $remainingResult['data']);
    }

    public function testGetRemainingLimitNegativeLimit(): void
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_ANY', 'USER_ANY', -1);
        $this->assertSame(PHP_INT_MAX, $remainingResult['data']);
    }

    public function testGetRemainingLimitNeverBought(): void
    {
        $remainingResult = $this->salesManager->getRemainingLimit('SKU_NEW', 'NEW_USER', 10);
        $this->assertSame(10, $remainingResult['data']);
    }

    public function testGetRemainingLimitInvalidSku(): void
    {
        $result = $this->salesManager->getRemainingLimit('bad:sku', 'USER', 5);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetRemainingLimitInvalidUser(): void
    {
        $result = $this->salesManager->getRemainingLimit('SKU_OK', 'bad:user', 5);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetSalesCount(): void
    {
        $this->salesManager->recordPurchase('SKU_STAT', 'U1', 2, 2000, 'O1');
        $this->salesManager->recordPurchase('SKU_STAT', 'U2', 3, 3000, 'O2');
        $countResult = $this->salesManager->getSalesCount('SKU_STAT');
        $this->assertSame(5, $countResult['data']);
    }

    public function testGetSalesCountNotExists(): void
    {
        $countResult = $this->salesManager->getSalesCount('SKU_GHOST');
        $this->assertSame(0, $countResult['data']);
    }

    public function testGetSalesCountInvalidSku(): void
    {
        $result = $this->salesManager->getSalesCount('bad:sku');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetSalesAmount(): void
    {
        $this->salesManager->recordPurchase('SKU_AMT', 'U1', 1, 9990, 'O1');
        $this->salesManager->recordPurchase('SKU_AMT', 'U2', 2, 19980, 'O2');
        $amountResult = $this->salesManager->getSalesAmount('SKU_AMT');
        $this->assertSame(29970, $amountResult['data']);
    }

    public function testGetSalesAmountNotExists(): void
    {
        $amountResult = $this->salesManager->getSalesAmount('SKU_GHOST');
        $this->assertSame(0, $amountResult['data']);
    }

    public function testGetSalesAmountInvalidSku(): void
    {
        $result = $this->salesManager->getSalesAmount('bad:sku');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['data']);
    }

    public function testGetMultipleSalesCounts(): void
    {
        $this->salesManager->recordPurchase('SKU_M1', 'U1', 5, 5000, 'O1');
        $this->salesManager->recordPurchase('SKU_M2', 'U1', 3, 3000, 'O2');
        $countsResult = $this->salesManager->getMultipleSalesCounts(['SKU_M1', 'SKU_M2', 'SKU_M3']);
        $this->assertSame(RedisSales::CODE_SUCCESS, $countsResult['code']);
        $this->assertSame(5, $countsResult['data']['SKU_M1']);
        $this->assertSame(3, $countsResult['data']['SKU_M2']);
        $this->assertSame(0, $countsResult['data']['SKU_M3']);
    }

    public function testGetMultipleSalesCountsEmpty(): void
    {
        $countsResult = $this->salesManager->getMultipleSalesCounts([]);
        $this->assertSame(RedisSales::CODE_SUCCESS, $countsResult['code']);
        $this->assertIsArray($countsResult['data']);
        $this->assertEmpty($countsResult['data']);
    }

    public function testGetMultipleSalesCountsInvalidSku(): void
    {
        $result = $this->salesManager->getMultipleSalesCounts(['VALID', 'bad:sku']);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertEmpty($result['data']);
    }

    public function testGetMultipleSalesCountsSingleSku(): void
    {
        $this->salesManager->recordPurchase('SKU_SINGLE', 'U1', 7, 7000, 'ORDER_SINGLE');
        $result = $this->salesManager->getMultipleSalesCounts(['SKU_SINGLE']);
        $this->assertCount(1, $result['data']);
        $this->assertSame(7, $result['data']['SKU_SINGLE']);
    }

    public function testGetSalesLeaderboard(): void
    {
        $this->salesManager->recordPurchase('SKU_TOP1', 'U1', 10, 10000, 'O1');
        $this->salesManager->recordPurchase('SKU_TOP2', 'U1', 5, 5000, 'O2');
        $this->salesManager->recordPurchase('SKU_TOP3', 'U1', 8, 8000, 'O3');
        $result = $this->salesManager->getSalesLeaderboard(0, 9, true);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $leaderboard = $result['data'];
        $this->assertIsArray($leaderboard);
        $this->assertGreaterThanOrEqual(3, count($leaderboard));
        $this->assertSame('SKU_TOP1', array_key_first($leaderboard));
        $this->assertEquals(10, $leaderboard['SKU_TOP1']);
    }

    public function testGetAmountLeaderboard(): void
    {
        $this->salesManager->recordPurchase('SKU_RICH1', 'U1', 1, 100000, 'O1');
        $this->salesManager->recordPurchase('SKU_RICH2', 'U1', 10, 5000, 'O2');
        $this->salesManager->recordPurchase('SKU_RICH3', 'U1', 5, 10000, 'O3');
        $result = $this->salesManager->getAmountLeaderboard(0, 9, true);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $leaderboard = $result['data'];
        $this->assertIsArray($leaderboard);
        $this->assertSame('SKU_RICH1', array_key_first($leaderboard));
        $this->assertEquals(100000, $leaderboard['SKU_RICH1']);
    }

    public function testLeaderboardPagination(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->salesManager->recordPurchase("SKU_{$i}", 'U1', $i, $i * 1000, "O_{$i}");
        }
        $top5Result = $this->salesManager->getSalesLeaderboard(0, 4, false);
        $this->assertCount(5, $top5Result['data']);
        $next5Result = $this->salesManager->getSalesLeaderboard(5, 9, false);
        $this->assertCount(5, $next5Result['data']);
    }

    public function testLeaderboardWithoutScores(): void
    {
        $this->salesManager->recordPurchase('SKU_A', 'U1', 5, 5000, 'O1');
        $this->salesManager->recordPurchase('SKU_B', 'U1', 3, 3000, 'O2');
        $result = $this->salesManager->getSalesLeaderboard(0, 9, false);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
        $this->assertSame('SKU_A', $result['data'][0]);
        $this->assertSame('SKU_B', $result['data'][1]);
    }

    public function testEmptyLeaderboard(): void
    {
        $result = $this->salesManager->getSalesLeaderboard();
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertIsArray($result['data']);
        $this->assertEmpty($result['data']);
    }

    public function testSalesLeaderboardNegativeStart(): void
    {
        $this->salesManager->recordPurchase('SKU_NEG', 'U1', 5, 5000, 'ORDER_NEG');
        $result = $this->salesManager->getSalesLeaderboard(-5, 5, false);
        $this->assertIsArray($result['data']);
    }

    public function testIsOrderProcessed(): void
    {
        $this->salesManager->recordPurchase('SKU_CHK', 'U1', 1, 1000, 'ORDER_CHK');
        $result = $this->salesManager->isOrderProcessed('ORDER_CHK');
        $this->assertTrue($result['data']);
    }

    public function testIsOrderNotProcessed(): void
    {
        $result = $this->salesManager->isOrderProcessed('ORDER_NONEXIST');
        $this->assertFalse($result['data']);
    }

    public function testIsOrderProcessedReturnStructure(): void
    {
        $this->salesManager->recordPurchase('SKU_STRUCT_CHECK', 'U1', 1, 1000, 'ORDER_STRUCT_CHECK');
        $result = $this->salesManager->isOrderProcessed('ORDER_STRUCT_CHECK');
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testClearSalesData(): void
    {
        $this->salesManager->recordPurchase('SKU_CLEAR', 'U1', 5, 5000, 'O_CLR1');
        $this->salesManager->recordPurchase('SKU_CLEAR', 'U2', 3, 3000, 'O_CLR2');
        $result = $this->salesManager->clearSalesData('SKU_CLEAR');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertGreaterThan(0, $result['deleted']);
        $this->assertSame(0, $this->salesManager->getSalesCount('SKU_CLEAR')['data']);
        $this->assertSame(0, $this->salesManager->getSalesAmount('SKU_CLEAR')['data']);
        $this->assertSame(0, $this->salesManager->getUserPurchaseCount('SKU_CLEAR', 'U1')['data']);
    }

    public function testClearSalesDataNotExists(): void
    {
        $result = $this->salesManager->clearSalesData('SKU_GHOST');
        $this->assertSame(0, $result['deleted']);
    }

    public function testClearSalesDataRemovesFromLeaderboard(): void
    {
        $this->salesManager->recordPurchase('SKU_LEADER', 'U1', 5, 5000, 'ORDER_L1');
        $this->salesManager->clearSalesData('SKU_LEADER');
        $lb = $this->salesManager->getSalesLeaderboard(0, 100, false)['data'];
        $this->assertNotContains('SKU_LEADER', $lb);
        $alb = $this->salesManager->getAmountLeaderboard(0, 100, false)['data'];
        $this->assertNotContains('SKU_LEADER', $alb);
    }

    public function testClearSalesDataInvalidSku(): void
    {
        $result = $this->salesManager->clearSalesData('bad:sku');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertSame(0, $result['deleted']);
    }

    public function testSpecialCharactersInSku(): void
    {
        $result = $this->salesManager->recordPurchase('PROD:123#{TEST}', 'USER_SPEC', 1, 1000, 'ORDER_SPEC');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testSpecialCharactersInUserId(): void
    {
        $result = $this->salesManager->recordPurchase('SKU_USR', 'user:test#123', 1, 1000, 'ORDER_USR');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testSpecialCharactersInOrderId(): void
    {
        $orderId = 'ORD:2024#TEST_001';
        $result = $this->salesManager->recordPurchase('SKU_ORD', 'U1', 1, 1000, $orderId);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $orderResult = $this->salesManager->isOrderProcessed($orderId);
        $this->assertTrue($orderResult['data']);
    }

    public function testEmptyStringParameters(): void
    {
        $result = $this->salesManager->recordPurchase('', 'U1', 1, 1000, 'ORDER_EMPTY');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $result2 = $this->salesManager->recordPurchase('SKU_EMPTY', '', 1, 1000, 'ORDER_EMPTY2');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result2['code']);
        $result3 = $this->salesManager->recordPurchase('SKU_EMPTY', 'U1', 1, 1000, '');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result3['code']);
    }

    public function testLongStrings(): void
    {
        $longSku = str_repeat('A', 100);
        $longUserId = str_repeat('U', 100);
        $longOrderId = str_repeat('O', 100);
        $result = $this->salesManager->recordPurchase($longSku, $longUserId, 1, 1000, $longOrderId);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
    }

    public function testHighFrequencyPurchaseBySameUser(): void
    {
        $limitPerUser = 10;
        $successCount = 0;
        for ($i = 0; $i < 15; $i++) {
            $result = $this->salesManager->recordPurchase('SKU_FREQ', 'USER_FREQ', 1, 1000, "ORDER_FREQ_{$i}", $limitPerUser);
            if ($result['code'] === RedisSales::CODE_SUCCESS) {
                $successCount++;
            }
        }
        $this->assertSame(10, $successCount);
        $countResult = $this->salesManager->getUserPurchaseCount('SKU_FREQ', 'USER_FREQ');
        $this->assertSame(10, $countResult['data']);
    }

    public function testMultipleUsersPurchaseSameSku(): void
    {
        for ($u = 1; $u <= 5; $u++) {
            for ($i = 0; $i < 5; $i++) {
                $this->salesManager->recordPurchase('SKU_MULTI', "USER_{$u}", 1, 1000, "ORDER_M_{$u}_{$i}", 3);
            }
        }
        $countResult = $this->salesManager->getSalesCount('SKU_MULTI');
        $this->assertSame(15, $countResult['data']);
    }

    public function testMassOrderIdempotency(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->salesManager->recordPurchase('SKU_MASS', "USER_{$i}", 1, 1000, "ORDER_MASS_{$i}");
        }
        $duplicateCount = 0;
        for ($i = 0; $i < 100; $i++) {
            $result = $this->salesManager->recordPurchase('SKU_MASS', "USER_{$i}", 1, 1000, "ORDER_MASS_{$i}");
            if ($result['code'] === RedisSales::CODE_ERR_ALREADY_PROCESSED) {
                $duplicateCount++;
            }
        }
        $this->assertSame(100, $duplicateCount);
        $countResult = $this->salesManager->getSalesCount('SKU_MASS');
        $this->assertSame(100, $countResult['data']);
    }

    public function testAmountPrecisionAccumulation(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->salesManager->recordPurchase('SKU_PREC', "USER_P{$i}", 1, 1, "ORDER_P{$i}");
        }
        $amountResult = $this->salesManager->getSalesAmount('SKU_PREC');
        $this->assertSame(100, $amountResult['data']);
    }

    public function testIntegerBoundaryValues(): void
    {
        $largeQuantity = 1000000;
        $result = $this->salesManager->recordPurchase('SKU_INT_MAX', 'USER_BIG', $largeQuantity, 1000000000, 'ORDER_BIG');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $countResult = $this->salesManager->getSalesCount('SKU_INT_MAX');
        $this->assertSame($largeQuantity, $countResult['data']);
    }

    public function testRecordPurchaseReturnStructure(): void
    {
        $result = $this->salesManager->recordPurchase('STRUCT_RECORD', 'USER_STRUCT', 1, 1000, 'ORDER_STRUCT');
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('total_sales', $result);
        $this->assertArrayHasKey('remaining_limit', $result);
    }

    public function testRecordPurchaseWithStockInsufficient(): void
    {
        $this->stockManager->initStocks(['STOCK_INSUF' => 2]);
        $result = $this->salesManager->recordPurchaseWithStock('STOCK_INSUF', 'USER_INS', 5, 5000, 'ORDER_INS', 0);
        $this->assertSame(RedisSales::CODE_ERR_INSUFFICIENT, $result['code']);
        $this->assertSame(2, $result['remain']);
        $this->assertStringContainsString('库存不足，剩余 2 件', $result['message']);
    }

    public function testRecordPurchaseWithStockNotExists(): void
    {
        $result = $this->salesManager->recordPurchaseWithStock('SKU_MISS', 'U1', 1, 1000, 'ORDER_MISS', 0);
        $this->assertSame(RedisSales::CODE_ERR_NOT_EXISTS, $result['code']);
        $this->assertStringContainsString('商品库存未初始化', $result['message']);
    }

    public function testRecordPurchaseWithStockSuccess(): void
    {
        $this->stockManager->initStocks(['STOCK_OK' => 10]);
        $result = $this->salesManager->recordPurchaseWithStock('STOCK_OK', 'USER_OK', 3, 2997, 'ORDER_OK', 0);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertSame(3, $result['total_sales']);
        $this->assertNull($result['remain']);
        $this->assertNull($result['remaining_limit']);
        $stockRes = $this->stockManager->getStock('STOCK_OK');
        $this->assertSame(7, $stockRes['stock']);
        $amountRes = $this->salesManager->getSalesAmount('STOCK_OK');
        $this->assertSame(2997, $amountRes['data']);
    }

    public function testRecordPurchaseWithStockZeroQuantity(): void
    {
        $this->stockManager->initStocks(['STOCK_ZERO_QTY' => 10]);
        $result = $this->salesManager->recordPurchaseWithStock('STOCK_ZERO_QTY', 'USER_ZERO', 0, 1000, 'ORDER_ZERO_QTY', 0);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
        $this->assertNull($result['remain']);
    }

    public function testRecordPurchaseWithStockNegativeQuantity(): void
    {
        $this->stockManager->initStocks(['STOCK_NEG_QTY' => 10]);
        $result = $this->salesManager->recordPurchaseWithStock('STOCK_NEG_QTY', 'USER_NEG', -1, 1000, 'ORDER_NEG_QTY', 0);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testRecordPurchaseWithStockNegativeAmount(): void
    {
        $this->stockManager->initStocks(['STOCK_NEG_AMT' => 10]);
        $result = $this->salesManager->recordPurchaseWithStock('STOCK_NEG_AMT', 'USER_NEG_AMT', 1, -1000, 'ORDER_NEG_AMT', 0);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_AMOUNT, $result['code']);
    }

    public function testRecordPurchaseWithStockExceedLimit(): void
    {
        $this->stockManager->initStocks(['STOCK_LIMIT_EXCEED' => 10]);
        $r1 = $this->salesManager->recordPurchaseWithStock('STOCK_LIMIT_EXCEED', 'USER_LIMIT', 2, 2000, 'ORDER_LIMIT1', 3);
        $this->assertSame(RedisSales::CODE_SUCCESS, $r1['code']);
        $r2 = $this->salesManager->recordPurchaseWithStock('STOCK_LIMIT_EXCEED', 'USER_LIMIT', 2, 2000, 'ORDER_LIMIT2', 3);
        $this->assertSame(RedisSales::CODE_ERR_LIMIT_EXCEEDED, $r2['code']);
        $this->assertSame(1, $r2['remaining_limit']);
    }

    public function testRecordPurchaseWithStockOrderCanceled(): void
    {
        $this->stockManager->initStocks(['STOCK_CANCELED' => 10]);
        $cancelKey = $this->testPrefix . 'order:' . 'ORDER_CANCELED_FLAG' . ':canceled';
        $this->redis->setex($cancelKey, 3600, '1');

        $result = $this->salesManager->recordPurchaseWithStock('STOCK_CANCELED', 'USER_CAN', 1, 1000, 'ORDER_CANCELED_FLAG', 0);
        $this->assertSame(RedisSales::CODE_ERR_ORDER_CANCELED, $result['code']);
        $this->assertStringContainsString('订单已取消', $result['message']);
    }

    public function testRecordPurchaseWithStockIdempotent(): void
    {
        $this->stockManager->initStocks(['STOCK_IDEM' => 10]);
        $r1 = $this->salesManager->recordPurchaseWithStock('STOCK_IDEM', 'U1', 2, 2000, 'ORDER_WS_IDEM', 0);
        $this->assertSame(RedisSales::CODE_SUCCESS, $r1['code']);
        $r2 = $this->salesManager->recordPurchaseWithStock('STOCK_IDEM', 'U2', 3, 3000, 'ORDER_WS_IDEM', 0);
        $this->assertSame(RedisSales::CODE_ERR_ALREADY_PROCESSED, $r2['code']);
        $stockRes = $this->stockManager->getStock('STOCK_IDEM');
        $this->assertSame(8, $stockRes['stock']);
    }

    public function testRecordPurchaseWithStockDeductToZeroSetsSoldout(): void
    {
        $this->stockManager->initStocks(['STOCK_TO_ZERO' => 1]);
        $this->salesManager->recordPurchaseWithStock('STOCK_TO_ZERO', 'U1', 1, 1000, 'ORDER_WZ', 0);
        $soldOutRes = $this->stockManager->isSoldOut('STOCK_TO_ZERO');
        $this->assertTrue($soldOutRes['soldOut']);
    }

    public function testRecordPurchaseWithStockInvalidSku(): void
    {
        $result = $this->salesManager->recordPurchaseWithStock('bad:sku', 'U1', 1, 1000, 'O', 0);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testRecordPurchaseWithStockInvalidUserId(): void
    {
        $result = $this->salesManager->recordPurchaseWithStock('SKU_VALID', 'bad:user', 1, 1000, 'O', 0);
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testCancelOrderWithStockSuccess(): void
    {
        $this->stockManager->initStocks(['SKU_CANCEL_OK' => 10]);
        $this->salesManager->recordPurchaseWithStock('SKU_CANCEL_OK', 'U1', 3, 3000, 'ORDER_CANCEL_OK', 0);
        $result = $this->salesManager->cancelOrderWithStock('SKU_CANCEL_OK', 3, 3000, 'ORDER_CANCEL_OK');
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $this->assertSame(10, $result['remain']);
        $stockRes = $this->stockManager->getStock('SKU_CANCEL_OK');
        $this->assertSame(10, $stockRes['stock']);
    }

    public function testCancelOrderWithStockAlreadyCanceled(): void
    {
        $this->stockManager->initStocks(['SKU_CANCEL_AGAIN' => 5]);
        $this->salesManager->recordPurchaseWithStock('SKU_CANCEL_AGAIN', 'U1', 1, 1000, 'ORDER_CANCEL_AGAIN', 0);
        $this->salesManager->cancelOrderWithStock('SKU_CANCEL_AGAIN', 1, 1000, 'ORDER_CANCEL_AGAIN');
        $result = $this->salesManager->cancelOrderWithStock('SKU_CANCEL_AGAIN', 1, 1000, 'ORDER_CANCEL_AGAIN');
        $this->assertSame(RedisSales::CODE_ERR_ORDER_CANCELED, $result['code']);
        $this->assertNotNull($result['remain']);
    }

    public function testCancelOrderWithStockNotProcessed(): void
    {
        $result = $this->salesManager->cancelOrderWithStock('SKU_ANY', 1, 100, 'ORDER_NOT_EXIST');
        $this->assertSame(RedisSales::CODE_ERR_ORDER_NOT_PROCESSED, $result['code']);
        $this->assertNull($result['remain']);
    }

    public function testCancelOrderWithStockNotInitialized(): void
    {
        $orderKey = $this->testPrefix . 'order:' . 'ORDER_NO_STOCK';
        $this->redis->setex($orderKey, 3600, '1');
        $result = $this->salesManager->cancelOrderWithStock('SKU_NO_STOCK', 1, 1000, 'ORDER_NO_STOCK');
        $this->assertSame(RedisSales::CODE_ERR_NOT_EXISTS, $result['code']);
        $this->assertNull($result['remain']);
    }

    public function testCancelOrderWithStockNegativeQuantity(): void
    {
        $result = $this->salesManager->cancelOrderWithStock('SKU', -1, 1000, 'ORDER');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testCancelOrderWithStockZeroQuantity(): void
    {
        $result = $this->salesManager->cancelOrderWithStock('SKU', 0, 1000, 'ORDER');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testCancelOrderWithStockNegativeAmount(): void
    {
        $result = $this->salesManager->cancelOrderWithStock('SKU', 1, -1000, 'ORDER');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_AMOUNT, $result['code']);
    }

    public function testCancelOrderWithStockInvalidSku(): void
    {
        $result = $this->salesManager->cancelOrderWithStock('bad:sku', 1, 1000, 'ORDER');
        $this->assertSame(RedisSales::CODE_ERR_INVALID_QUANTITY, $result['code']);
    }

    public function testCancelOrderWithStockRestoresSoldout(): void
    {
        $this->stockManager->initStocks(['SKU_RESTORE' => 1]);
        $this->salesManager->recordPurchaseWithStock('SKU_RESTORE', 'U1', 1, 1000, 'ORDER_RESTORE', 0);
        $this->assertTrue($this->stockManager->isSoldOut('SKU_RESTORE')['soldOut']);
        $this->salesManager->cancelOrderWithStock('SKU_RESTORE', 1, 1000, 'ORDER_RESTORE');
        $this->assertFalse($this->stockManager->isSoldOut('SKU_RESTORE')['soldOut']);
    }

    public function testCancelOrderWithStockRollsBackSalesData(): void
    {
        $this->stockManager->initStocks(['SKU_ROLLBACK' => 10]);
        $this->salesManager->recordPurchaseWithStock('SKU_ROLLBACK', 'U1', 5, 5000, 'ORDER_RB', 0);
        $this->assertSame(5, $this->salesManager->getSalesCount('SKU_ROLLBACK')['data']);
        $this->salesManager->cancelOrderWithStock('SKU_ROLLBACK', 5, 5000, 'ORDER_RB');
        $this->assertSame(0, $this->salesManager->getSalesCount('SKU_ROLLBACK')['data']);
        $this->assertSame(0, $this->salesManager->getSalesAmount('SKU_ROLLBACK')['data']);
    }

    public function testRecordPurchaseWithStockPersistsUserPurchaseSet(): void
    {
        $this->stockManager->initStocks(['SKU_USERSET' => 10]);
        $result = $this->salesManager->recordPurchaseWithStock('SKU_USERSET', 'USER_SET', 2, 2000, 'ORDER_SET', 0);
        $this->assertSame(RedisSales::CODE_SUCCESS, $result['code']);
        $purchases = $this->salesManager->getUserPurchases('USER_SET');
        $this->assertArrayHasKey('SKU_USERSET', $purchases['data']);
        $this->assertSame(2, $purchases['data']['SKU_USERSET']);
    }
}