<?php

/**
 * RedisStock 底层高级用法示例
 *
 * 仅当需要门面类未提供的能力时使用：
 *  - 批量扣减库存（原子）
 *  - 库存状态监控与自动修复
 *  - 单 sku 补货 / 删除库存
 *  - 自定义重试与错误处理
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStock;
use Nermif\RedisConstants;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$stockManager = new RedisStock($redis, '{store}:');

// 1. 批量初始化
$stockManager->initStocks([
    'ITEM_A' => 50,
    'ITEM_B' => 30,
    'ITEM_C' => 10,
], 7200);

// 2. 批量扣减（原子：要么全成功，要么全失败）
echo "=== 批量扣减套装 ===\n";
$bundle = ['ITEM_A' => 2, 'ITEM_B' => 1];
$res = $stockManager->decrMultiStocks($bundle);

if ($res['success']) {
    echo "成功！剩余：\n";
    foreach ($res['remain'] as $sku => $remain) {
        echo "  $sku: $remain\n";
    }
} else {
    echo "失败: SKU {$res['sku']} 不足（需要 {$res['required']}，可用 {$res['available']}）\n";
}

// 3. 库存监控与修复
echo "\n=== 库存监控与修复 ===\n";
$sku = 'ITEM_A';
$monitor = $stockManager->monitor($sku);
echo "一致性: " . ($monitor['consistency'] ? '正常' : '异常') . "\n";

// 模拟不一致
$redis->set('{store}:ITEM_A:soldout', 1);
echo "模拟后一致性: " . ($stockManager->monitor($sku)['consistency'] ? '正常' : '异常') . "\n";

$repair = $stockManager->repair($sku);
echo "修复: {$repair['action']}\n";

// 4. 补货与删除
echo "\n=== 补货 ===\n";
$stockManager->incrStock('ITEM_C', 20);
echo "补货后 ITEM_C: " . $stockManager->getStock('ITEM_C')['stock'] . "\n";

echo "\n=== 删除库存 ===\n";
$stockManager->delStock('ITEM_B');
echo "删除后 ITEM_B: " . ($stockManager->getStock('ITEM_B')['stock'] ?? 'null') . "\n";

echo "\n完成\n";