<?php

/**
 * RedisStockSalesManager 门面类推荐使用示例
 *
 * 使用门面类可自动获得：
 *   - 统一返回格式 [success, code, message, data]
 *   - 购买下单原子操作（扣减库存 + 记录销售）
 *   - 参数快速失败校验（非法 ID、空订单号等）
 *   - 连接状态自动验证
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStockSalesManager;
use Nermif\StockSalesCodes;

// 1. 创建 Redis 连接并实例化门面
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$manager = new RedisStockSalesManager($redis, '{store}:');

// 2. 初始化库存
echo "=== 初始化库存 ===\n";
$result = $manager->initStocks([
    'PHONE_15' => 100,
    'AIRPODS'  => 50,
    'CHARGER'  => 200,
]);
echo "成功初始化: {$result['data']['initialized_count']} 个 SKU\n\n";

// 3. 购买下单（原子扣减库存 + 记录销售）
echo "=== 购买下单 ===\n";
$purchase = $manager->purchase(
    'PHONE_15',     // SKU
    'user_1001',    // 用户ID
    1,              // 数量
    999900,         // 金额（分，即 9999.00 元）
    'ORD_20260501'  // 订单ID
);

if ($purchase['success']) {
    echo "✅ 购买成功\n";
    echo "   订单ID: {$purchase['data']['order_id']}\n";
    echo "   总销量: {$purchase['data']['total_sales']}\n";
    echo "   剩余库存: " . $manager->getStock('PHONE_15')['data']['stock'] . "\n";
} else {
    echo "❌ 购买失败: {$purchase['message']}\n";
    echo "   错误码: {$purchase['code']}\n";
}
echo "\n";

// 4. 限购示例
echo "=== 限购示例（每人限购 2 件） ===\n";
$result1 = $manager->purchase('AIRPODS', 'user_2001', 2, 29900, 'ORD_LIMIT_1', 2);
echo "第一次购买: {$result1['message']}\n";

$result2 = $manager->purchase('AIRPODS', 'user_2001', 1, 14950, 'ORD_LIMIT_2', 2);
if (!$result2['success']) {
    echo "第二次购买: {$result2['message']}\n";
    echo "   剩余可买: {$result2['data']['remaining_limit']} 件\n";
}
echo "\n";

// 5. 取消订单
echo "=== 取消订单 ===\n";
$cancel = $manager->cancel('AIRPODS', 2, 29900, 'ORD_LIMIT_1');
echo "取消结果: {$cancel['message']}\n";
echo "回滚后库存: " . $manager->getStock('AIRPODS')['data']['stock'] . "\n\n";

// 6. 参数错误处理
echo "=== 参数错误拦截 ===\n";
$bad1 = $manager->purchase('sku:bad', 'U1', 1, 100, 'O1');
echo "非法 SKU: {$bad1['message']}\n";

$bad2 = $manager->purchase('PHONE_15', 'U1', 1, 100, '');
echo "空订单号: {$bad2['message']}\n";

$bad3 = $manager->purchase('PHONE_15', 'U1', 1, 100, 'O_NEG', -1);
echo "负数限购: {$bad3['message']}\n\n";

// 7. 辅助查询
echo "=== 辅助查询 ===\n";
$stock = $manager->getStock('PHONE_15');
echo "PHONE_15 库存: {$stock['data']['stock']}, 售罄: " . ($stock['data']['soldOut'] ? '是' : '否') . "\n";

$soldOut = $manager->isSoldOut('PHONE_15');
echo "是否售罄: " . ($soldOut['data']['soldOut'] ? '是' : '否') . "\n";

$monitor = $manager->monitor('PHONE_15');
echo "一致性: " . ($monitor['data']['consistency'] ? '一致' : '不一致') . "\n";

echo "\n=== 示例结束 ===\n";