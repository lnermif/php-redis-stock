<?php

/**
 * RedisSales 销售记录使用示例
 * 
 * 功能：
 * - 记录销售（不扣减库存）
 * - 记录销售（原子扣减库存）
 * - 限购控制
 * - 订单幂等
 * - 用户购买记录查询
 * - 销售统计
 * - 排行榜
 * - 数据清理
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisSales;
use Nermif\RedisStock;

// 1. 创建 Redis 连接
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(0);

// 2. 创建销售管理实例
$salesManager = new RedisSales($redis, '{product:sales}:');

// 3. 创建库存管理实例（用于演示 recordPurchaseWithStock）
$stockManager = new RedisStock($redis, '{product:sales}:');

// =========================================================================
// 一、记录销售（不扣减库存）
// =========================================================================
echo "=== 记录销售（不扣减库存） ===\n";

// 记录购买（金额单位：分）
$result = $salesManager->recordPurchase(
    'SKU001',        // SKU
    'USER001',       // 用户ID
    2,               // 数量
    9990,            // 金额（99.90元 = 9990分）
    'ORDER001',      // 订单ID
    0                // 限购数量（0=无限制）
);

if ($result['code'] === RedisSales::CODE_SUCCESS) {
    echo "✓ 购买记录成功\n";
    echo "  总销量: {$result['total_sales']}\n";
} else {
    echo "✗ 购买记录失败: {$result['message']}\n";
}
echo "\n";

// =========================================================================
// 二、记录销售（原子扣减库存）
// =========================================================================
echo "=== 记录销售（原子扣减库存） ===\n";

// 先初始化库存
$stockManager->initStocks(['SKU002' => 100], 3600);

// 原子扣减库存并记录销售
$result = $salesManager->recordPurchaseWithStock(
    'SKU002',        // SKU
    'USER002',       // 用户ID
    3,               // 数量
    15990,           // 金额（159.90元 = 15990分）
    'ORDER002',      // 订单ID
    0                // 限购数量
);

if ($result['code'] === RedisSales::CODE_SUCCESS) {
    echo "✓ 购买成功（库存已扣减）\n";
    echo "  总销量: {$result['total_sales']}\n";
    echo "  剩余库存: {$result['remain']}\n";
} else {
    echo "✗ 购买失败: {$result['message']}\n";
    if ($result['remain'] !== null) {
        echo "  剩余库存: {$result['remain']}\n";
    }
}
echo "\n";

// =========================================================================
// 三、限购控制
// =========================================================================
echo "=== 限购控制 ===\n";

// 用户首次购买（在限购内）
$result1 = $salesManager->recordPurchase('SKU003', 'USER003', 2, 5000, 'ORDER003', 5);
echo "首次购买: {$result1['message']}\n";

// 用户第二次购买（仍在限购内）
$result2 = $salesManager->recordPurchase('SKU003', 'USER003', 2, 5000, 'ORDER004', 5);
echo "第二次购买: {$result2['message']}\n";

// 用户第三次购买（超过限购）
$result3 = $salesManager->recordPurchase('SKU003', 'USER003', 2, 5000, 'ORDER005', 5);
echo "第三次购买: {$result3['message']}\n";
if ($result3['code'] === RedisSales::CODE_ERR_LIMIT_EXCEEDED) {
    echo "  还可购买: {$result3['remaining_limit']} 件\n";
}
echo "\n";

// =========================================================================
// 四、订单幂等性
// =========================================================================
echo "=== 订单幂等性 ===\n";

// 第一次提交
$result1 = $salesManager->recordPurchase('SKU004', 'USER004', 1, 10000, 'ORDER_IDEM', 0);
echo "第一次提交: {$result1['message']}\n";

// 第二次提交（相同订单号）
$result2 = $salesManager->recordPurchase('SKU004', 'USER004', 1, 10000, 'ORDER_IDEM', 0);
echo "第二次提交: {$result2['message']}\n";
echo "✓ 幂等性保护生效，不会重复记录\n\n";

// =========================================================================
// 五、用户购买记录查询
// =========================================================================
echo "=== 用户购买记录查询 ===\n";

// 记录多个购买
$salesManager->recordPurchase('SKU_A', 'USER_Q', 2, 2000, 'ORDER_Q1');
$salesManager->recordPurchase('SKU_B', 'USER_Q', 3, 3000, 'ORDER_Q2');
$salesManager->recordPurchase('SKU_C', 'USER_Q', 1, 1000, 'ORDER_Q3');

// 查询用户购买记录
$purchases = $salesManager->getUserPurchases('USER_Q');
echo "USER_Q 购买记录:\n";
foreach ($purchases['data'] as $sku => $count) {
    echo "  - {$sku}: {$count} 件\n";
}
echo "\n";

// 查询用户某 SKU 的购买数量
$count = $salesManager->getUserPurchaseCount('SKU_A', 'USER_Q');
echo "USER_Q 购买 SKU_A 数量: {$count['data']}\n\n";

// =========================================================================
// 六、剩余限购数量查询
// =========================================================================
echo "=== 剩余限购数量查询 ===\n";

$remaining = $salesManager->getRemainingLimit('SKU_A', 'USER_Q', 10);
echo "USER_Q 购买 SKU_A 还可购买: {$remaining['data']} 件\n\n";

// =========================================================================
// 七、销售统计
// =========================================================================
echo "=== 销售统计 ===\n";

// 单个 SKU 统计
$salesCount = $salesManager->getSalesCount('SKU_A');
$salesAmount = $salesManager->getSalesAmount('SKU_A');
echo "SKU_A 销售统计:\n";
echo "  销量: {$salesCount['data']} 件\n";
echo "  销售额: " . number_format($salesAmount['data'] / 100, 2) . " 元\n\n";

// 批量获取销量
$counts = $salesManager->getMultipleSalesCounts(['SKU_A', 'SKU_B', 'SKU_C', 'SKU_NOT_EXISTS']);
echo "批量销量查询:\n";
foreach ($counts['data'] as $sku => $count) {
    echo "  - {$sku}: {$count} 件\n";
}
echo "\n";

// =========================================================================
// 八、排行榜
// =========================================================================
echo "=== 排行榜 ===\n";

// 销量排行榜
$leaderboard = $salesManager->getSalesLeaderboard(0, 9, true);
echo "销量排行榜（Top 10）:\n";
foreach ($leaderboard['data'] as $sku => $count) {
    echo "  {$sku}: {$count} 件\n";
}
echo "\n";

// 销售额排行榜
$amountLeaderboard = $salesManager->getAmountLeaderboard(0, 9, true);
echo "销售额排行榜（Top 10）:\n";
foreach ($amountLeaderboard['data'] as $sku => $amount) {
    echo "  {$sku}: " . number_format($amount / 100, 2) . " 元\n";
}
echo "\n";

// 不带分数的排行榜（仅 SKU 列表）
$leaderboardNoScores = $salesManager->getSalesLeaderboard(0, 4, false);
echo "销量排行榜（Top 5，仅 SKU）:\n";
foreach ($leaderboardNoScores['data'] as $index => $sku) {
    echo "  " . ($index + 1) . ". {$sku}\n";
}
echo "\n";

// =========================================================================
// 九、订单状态检查
// =========================================================================
echo "=== 订单状态检查 ===\n";

$isProcessed = $salesManager->isOrderProcessed('ORDER001');
echo "ORDER001 是否已处理: " . ($isProcessed['data'] ? '是' : '否') . "\n";

$isProcessed = $salesManager->isOrderProcessed('ORDER_NOT_EXISTS');
echo "ORDER_NOT_EXISTS 是否已处理: " . ($isProcessed['data'] ? '是' : '否') . "\n\n";

// =========================================================================
// 十、数据清理
// =========================================================================
echo "=== 数据清理 ===\n";

$clearResult = $salesManager->clearSalesData('SKU_A');
echo "清理 SKU_A 销售数据:\n";
echo "  删除的 Key 数量: {$clearResult['deleted']}\n";

// 验证清理结果
$salesCount = $salesManager->getSalesCount('SKU_A');
echo "  清理后销量: {$salesCount['data']}\n\n";

// =========================================================================
// 十一、错误处理
// =========================================================================
echo "=== 错误处理 ===\n";

// 数量无效
$result = $salesManager->recordPurchase('SKU_ERR', 'USER_ERR', 0, 1000, 'ORDER_ERR1');
echo "数量为0: {$result['message']}\n";

// 金额无效
$result = $salesManager->recordPurchase('SKU_ERR', 'USER_ERR', 1, -100, 'ORDER_ERR2');
echo "金额为负: {$result['message']}\n";

// 库存不足（recordPurchaseWithStock）
$stockManager->initStocks(['SKU_LOW' => 2], 3600);
$result = $salesManager->recordPurchaseWithStock('SKU_LOW', 'USER_ERR', 5, 1000, 'ORDER_ERR3');
echo "库存不足: {$result['message']}\n";

// 库存未初始化
$result = $salesManager->recordPurchaseWithStock('SKU_NOT_INIT', 'USER_ERR', 1, 1000, 'ORDER_ERR4');
echo "库存未初始化: {$result['message']}\n\n";

echo "=== 示例结束 ===\n";
