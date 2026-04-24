<?php

/**
 * 秒杀场景完整演示
 * 
 * 模拟高并发秒杀场景，包括：
 * - 库存预热
 * - 并发扣减
 * - 订单创建
 * - 失败处理
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStock;

echo "========================================\n";
echo "    秒杀场景演示\n";
echo "========================================\n\n";

// ========== 1. 初始化环境 ==========
echo "[1] 初始化 Redis 连接...\n";
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(1); // 使用数据库1
echo "✓ Redis 连接成功\n\n";

// ========== 2. 创建库存管理器 ==========
echo "[2] 创建库存管理器...\n";
$stockManager = new RedisStock($redis, '{seckill:product}:');
echo "✓ 库存管理器创建成功\n\n";

// ========== 3. 活动开始前 - 库存预热 ==========
echo "[3] 活动开始前 - 库存预热...\n";
$seckillProducts = [
    'SECKILL_IPHONE_15' => 10,   // iPhone 15 只有 10 台
    'SECKILL_AIRPODS' => 50,     // AirPods 有 50 台
    'SECKILL_IPAD' => 30,        // iPad 有 30 台
];

$created = $stockManager->initStocks($seckillProducts, 7200); // 2小时过期
echo "✓ 库存预热完成\n";
if ($created > 0) {
    echo "  新创建 {$created} 个商品库存\n";
} else {
    echo "  注意：所有商品库存已存在，跳过初始化（幂等保护）\n";
}
echo "  预热商品列表：\n";
foreach ($seckillProducts as $sku => $stock) {
    echo "    - {$sku}: {$stock} 件\n";
}
echo "\n";

// ========== 4. 模拟用户抢购 ==========
echo "[4] 模拟用户抢购...\n";

function simulateSeckill(RedisStock $manager, string $userId, string $sku, int $quantity = 1): array
{
    // 步骤1: 快速检查是否售罄（轻量级查询）
    if ($manager->isSoldOut($sku)) {
        return [
            'success' => false,
            'message' => '商品已售罄',
            'user_id' => $userId
        ];
    }

    // 步骤2: 查询详细库存
    $stockInfo = $manager->getStock($sku);
    
    if ($stockInfo['stock'] === null) {
        return [
            'success' => false,
            'message' => '商品不存在',
            'user_id' => $userId
        ];
    }

    if ($stockInfo['stock'] < $quantity) {
        return [
            'success' => false,
            'message' => "库存不足 (剩余: {$stockInfo['stock']})",
            'user_id' => $userId
        ];
    }

    // 步骤3: 原子扣减库存
    try {
        $result = $manager->decrStock($sku, $quantity);

        switch ($result['code']) {
            case RedisStock::CODE_SUCCESS:
                // 步骤4: 创建订单（这里简化处理）
                $orderId = generateOrderId($userId, $sku);
                
                return [
                    'success' => true,
                    'message' => '抢购成功',
                    'user_id' => $userId,
                    'order_id' => $orderId,
                    'remain_stock' => $result['remain']
                ];

            case RedisStock::CODE_ERR_INSUFFICIENT:
                return [
                    'success' => false,
                    'message' => "库存不足 (剩余: {$result['remain']})",
                    'user_id' => $userId
                ];

            case RedisStock::CODE_ERR_NOT_EXISTS:
                return [
                    'success' => false,
                    'message' => '商品不存在',
                    'user_id' => $userId
                ];

            default:
                return [
                    'success' => false,
                    'message' => '系统繁忙，请重试',
                    'user_id' => $userId
                ];
        }
    } catch (\RuntimeException $e) {
        return [
            'success' => false,
            'message' => '系统异常',
            'user_id' => $userId,
            'error' => $e->getMessage()
        ];
    }
}

function generateOrderId(string $userId, string $sku): string
{
    return 'ORD' . date('YmdHis') . '_' . substr($userId, -4) . '_' . substr(md5($sku), 0, 4);
}

// 模拟多个用户抢购同一个商品
echo "\n--- 场景1: 多用户抢购 iPhone 15 ---\n";
$users = ['user_001', 'user_002', 'user_003', 'user_004', 'user_005'];
$results = [];

foreach ($users as $user) {
    $result = simulateSeckill($stockManager, $user, 'SECKILL_IPHONE_15', 1);
    $results[] = $result;
    
    $status = $result['success'] ? '✓' : '✗';
    echo "{$status} {$user}: {$result['message']}\n";
    
    if ($result['success']) {
        echo "   订单号: {$result['order_id']}, 剩余库存: {$result['remain_stock']}\n";
    }
    
    // 模拟网络延迟
    usleep(rand(10000, 50000)); // 10-50ms
}

$successCount = count(array_filter($results, function($r) { return $r['success']; }));
echo "\n结果: {$successCount}/" . count($users) . " 人抢购成功\n\n";

// ========== 5. 查看库存状态 ==========
echo "[5] 查看库存状态...\n";
foreach ($seckillProducts as $sku => $initialStock) {
    $stockInfo = $stockManager->getStock($sku);
    $soldOut = $stockInfo['soldOut'] ? ' (已售罄)' : '';
    echo "  - {$sku}: {$stockInfo['stock']} 件{$soldOut}\n";
}
echo "\n";

// ========== 6. 批量购买场景 ==========
echo "[6] 批量购买场景 - 用户一次性购买多个商品...\n";

// 初始化测试库存
$stockManager->initStocks([
    'BUNDLE_PHONE' => 20,
    'BUNDLE_CASE' => 100,
    'BUNDLE_CHARGER' => 100,
], 3600);

$bundleItems = [
    'BUNDLE_PHONE' => 1,
    'BUNDLE_CASE' => 1,
    'BUNDLE_CHARGER' => 1,
];

echo "用户尝试购买套装: ";
foreach ($bundleItems as $sku => $qty) {
    echo "{$sku} x{$qty} ";
}
echo "\n";

try {
    $result = $stockManager->decrMultiStocks($bundleItems);
    
    if ($result['success']) {
        echo "✓ 套装购买成功！\n";
        foreach ($result['remain'] as $sku => $remain) {
            echo "  - {$sku} 剩余: {$remain}\n";
        }
    } else {
        echo "✗ 套装购买失败: {$result['sku']} 库存不足\n";
        echo "  需要: {$result['required']}, 可用: {$result['available']}\n";
    }
} catch (\RuntimeException $e) {
    echo "✗ 系统异常: {$e->getMessage()}\n";
}
echo "\n";

// ========== 7. 订单取消 - 库存回滚 ==========
echo "[7] 订单取消 - 库存回滚...\n";

$stockInfo = $stockManager->getStock('SECKILL_AIRPODS');
$beforeStock = $stockInfo['stock'];
echo "回滚前库存: {$beforeStock}\n";

// 模拟用户取消订单，退回 2 件商品
$result = $stockManager->incrStock('SECKILL_AIRPODS', 2);

if ($result['code'] === RedisStock::CODE_SUCCESS) {
    $stockInfo = $stockManager->getStock('SECKILL_AIRPODS');
    echo "回滚后库存: {$stockInfo['stock']}\n";
    echo "✓ 库存已成功回滚\n";
} else {
    echo "✗ 库存回滚失败\n";
}
echo "\n";

// ========== 8. 性能统计 ==========
echo "[8] 性能测试...\n";

$testSku = 'PERF_TEST_ITEM';
$stockManager->initStocks([$testSku => 10000], 3600);

$start = microtime(true);
$ops = 1000;

for ($i = 0; $i < $ops; $i++) {
    $stockManager->getStock($testSku);
}

$elapsed = microtime(true) - $start;
$qps = $ops / $elapsed;

echo "执行 {$ops} 次查询，耗时: " . round($elapsed * 1000, 2) . " ms\n";
echo "QPS: " . number_format($qps, 2) . "\n\n";

// ========== 9. 清理测试数据 ==========
echo "[9] 清理测试数据...\n";
foreach ($seckillProducts as $sku => $stock) {
    $stockManager->delStock($sku);
}
$stockManager->delStock('BUNDLE_PHONE');
$stockManager->delStock('BUNDLE_CASE');
$stockManager->delStock('BUNDLE_CHARGER');
$stockManager->delStock('PERF_TEST_ITEM');
echo "✓ 清理完成\n\n";

echo "========================================\n";
echo "    演示结束\n";
echo "========================================\n";
