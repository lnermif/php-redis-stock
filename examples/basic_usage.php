<?php

/**
 * RedisStock 基础使用示例
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\PhpRedisStock\RedisStock;

// 1. 创建 Redis 连接
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(0);

// 2. 创建库存管理实例（使用默认日志）
$stockManager = new RedisStock($redis, '{product:stock}:');

// 3. 初始化库存
echo "=== 初始化库存 ===\n";
$stocks = [
    'SKU001' => 100,
    'SKU002' => 50,
    'SKU003' => 200,
];
$created = $stockManager->initStocks($stocks, 3600); // TTL 1小时
echo "成功初始化 {$created} 个商品库存\n\n";

// 4. 查询库存
echo "=== 查询库存 ===\n";
$stockInfo = $stockManager->getStock('SKU001');
echo "SKU001 库存: {$stockInfo['stock']}, 售罄: " . ($stockInfo['soldOut'] ? '是' : '否') . "\n\n";

// 5. 扣减库存
echo "=== 扣减库存 ===\n";
$result = $stockManager->decrStock('SKU001', 5);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "扣减成功，剩余库存: {$result['remain']}\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_INSUFFICIENT) {
    echo "库存不足，当前库存: {$result['remain']}\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_NOT_EXISTS) {
    echo "库存未初始化\n";
}
echo "\n";

// 6. 增加库存（补货）
echo "=== 补货 ===\n";
$result = $stockManager->incrStock('SKU001', 20);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "补货成功，当前库存: {$result['remain']}\n";
}
echo "\n";

// 7. 检查售罄状态
echo "=== 检查售罄 ===\n";
$isSoldOut = $stockManager->isSoldOut('SKU001');
echo "SKU001 是否售罄: " . ($isSoldOut ? '是' : '否') . "\n\n";

// 8. 批量获取库存
echo "=== 批量查询 ===\n";
$allStocks = $stockManager->getStocks(['SKU001', 'SKU002', 'SKU003']);
foreach ($allStocks as $sku => $stock) {
    echo "{$sku}: {$stock}\n";
}
echo "\n";

// 9. 删除库存
echo "=== 删除库存 ===\n";
$deleted = $stockManager->delStock('SKU003');
echo "已删除 {$deleted} 个键\n";
