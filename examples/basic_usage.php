<?php

/**
 * RedisStock 基础使用示例
 * 
 * 功能：
 * - 初始化库存（支持 TTL）
 * - 查询库存（单个/批量）
 * - 扣减库存（原子操作）
 * - 增加库存（补货）
 * - 检查售罄状态
 * - 删除库存
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStock;

// 1. 创建 Redis 连接
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(0);

// 2. 创建库存管理实例（使用默认日志）
// 注意：建议使用 Hash Tag 前缀，确保 Redis Cluster 兼容性
$stockManager = new RedisStock($redis, '{product:stock}:');

// 3. 初始化库存
echo "=== 初始化库存 ===\n";
$stocks = [
    'SKU001' => 100,
    'SKU002' => 50,
    'SKU003' => 200,
];
$created = $stockManager->initStocks($stocks, 3600); // TTL 1小时
if ($created > 0) {
    echo "成功新创建 {$created} 个商品库存\n";
} else {
    echo "商品库存已存在，跳过初始化（幂等保护）\n";
}
echo "\n";

// 4. 查询库存（返回值结构：['code' => int, 'stock' => int|null, 'soldOut' => bool]）
echo "=== 查询库存 ===\n";
$stockInfo = $stockManager->getStock('SKU001');
if ($stockInfo['code'] === RedisStock::CODE_SUCCESS) {
    echo "SKU001 库存: {$stockInfo['stock']}, 售罄: " . ($stockInfo['soldOut'] ? '是' : '否') . "\n";
} else {
    echo "查询失败，错误码: {$stockInfo['code']}\n";
}
echo "\n";

// 5. 扣减库存（返回值结构：['code' => int, 'remain' => int|null]）
echo "=== 扣减库存 ===\n";
$result = $stockManager->decrStock('SKU001', 5);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "扣减成功，剩余库存: {$result['remain']}\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_INSUFFICIENT) {
    echo "库存不足，当前库存: {$result['remain']}\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_NOT_EXISTS) {
    echo "库存未初始化\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_INVALID_QUANTITY) {
    echo "扣减数量无效（必须大于0）\n";
}
echo "\n";

// 6. 增加库存（补货）（返回值结构：['code' => int, 'remain' => int|null]）
echo "=== 补货 ===\n";
$result = $stockManager->incrStock('SKU001', 20);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "补货成功，当前库存: {$result['remain']}\n";
} elseif ($result['code'] === RedisStock::CODE_ERR_NOT_EXISTS) {
    echo "库存未初始化，无法补货\n";
}
echo "\n";

// 7. 检查售罄状态（返回值结构：['code' => int, 'soldOut' => bool]）
echo "=== 检查售罄 ===\n";
$soldOutResult = $stockManager->isSoldOut('SKU001');
if ($soldOutResult['code'] === RedisStock::CODE_SUCCESS) {
    echo "SKU001 是否售罄: " . ($soldOutResult['soldOut'] ? '是' : '否') . "\n";
}
echo "\n";

// 8. 批量获取库存（返回值结构：['code' => int, 'data' => ['sku' => int|null]]）
echo "=== 批量查询 ===\n";
$allStocks = $stockManager->getStocks(['SKU001', 'SKU002', 'SKU003', 'NOT_EXISTS']);
if ($allStocks['code'] === RedisStock::CODE_SUCCESS) {
    foreach ($allStocks['data'] as $sku => $stock) {
        $stockStr = $stock === null ? '不存在' : (string)$stock;
        echo "{$sku}: {$stockStr}\n";
    }
}
echo "\n";

// 9. 删除库存（返回值结构：['code' => int, 'deleted' => int]）
echo "=== 删除库存 ===\n";
$delResult = $stockManager->delStock('SKU003');
if ($delResult['code'] === RedisStock::CODE_SUCCESS) {
    echo "已删除 {$delResult['deleted']} 个键\n";
} else {
    echo "删除失败\n";
}
echo "\n";

// 10. 验证删除后查询
echo "=== 验证删除 ===\n";
$stockInfo = $stockManager->getStock('SKU003');
if ($stockInfo['code'] === RedisStock::CODE_SUCCESS) {
    echo "SKU003 库存: " . ($stockInfo['stock'] === null ? '不存在' : $stockInfo['stock']) . "\n";
}
