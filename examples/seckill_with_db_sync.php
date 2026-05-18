<?php

/**
 * 秒杀场景：Redis 秒杀 + 异步队列 + 数据库对账
 *
 * 正确的架构分工：
 *
 *   ┌──────────────┐   purchase()成功    ┌──────────────┐
 *   │ RedisStock-  │ ── 返回结果 ──────→ │  业务层       │
 *   │ SalesManager │                     │              │
 *   └──────────────┘                     │ 1. 写入订单表  │
 *                                        │ 2. 投递 MQ    │
 *                                        └──────┬───────┘
 *                                               │
 *                                  ┌────────────▼────────────┐
 *                                  │  异步队列 Worker          │
 *                                  │  UPDATE stock = stock - qty   │  相对增量更新
 *                                  │  UPDATE sales = sales + qty   │
 *                                  └─────────────────────────┘
 *
 *   ┌──────────────┐   秒杀结束后    ┌──────────────────────┐
 *   │ RedisStock-  │ ──对账同步──→  │ RedisToDatabaseSync │
 *   │ SalesManager │               │                      │
 *   └──────────────┘               │ 读 Redis 绝对值       │
 *                                  │ 写 DB 绝对值          │  对账：修正任何偏差
 *                                  └──────────────────────┘
 *
 * 关键区别：
 *  - 队列 Worker 做的是相对操作 (stock = stock - 1)，每笔订单一个 delta
 *  - RedisToDatabaseSync 做的是绝对操作 (stock = 99)，秒杀结束后一次性写入最终结果
 *  - 两者不冲突：秒杀进行中用队列，秒杀结束后用对账同步兜底
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStockSalesManager;
use Nermif\RedisToDatabaseSync;
use Nermif\DatabaseSyncInterface;

echo "========================================\n";
echo "  秒杀 → Redis → 异步队列 → DB → 对账\n";
echo "========================================\n\n";

// ========== 1. 初始化 Redis 连接 ==========
echo "[1] 初始化 Redis 连接...\n";
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(2);
echo "✓ Redis 连接成功\n\n";

// ========== 2. 创建秒杀管理器（无自动同步） ==========
echo "[2] 创建秒杀管理器...\n";
// 注意：这里不传 DB 同步器——库存扣减归 Redis，DB 更新由队列负责
$manager = new RedisStockSalesManager(
    $redis,
    '{seckill:product}:',
    null,   // logger
    null    // maxRetries
);
echo "✓ 创建完成\n\n";

// ========== 3. 库存预热 ==========
echo "[3] 库存预热...\n";
$products = [
    'SECKILL_IPHONE' => 100,
    'SECKILL_MACBOOK' => 50,
];
$manager->initStocks($products, 86400);
echo "✓ 完成\n\n";

// ========== 4. 模拟你现有的完整秒杀流程 ==========
echo "[4] 模拟「秒杀 → 推送队列」完整流程...\n\n";

/**
 * 模拟：购买成功后投递订单数据到数据库（通过异步队列）
 *
 * 在大规模秒杀场景中，这里真实使用 MQ（RabbitMQ / Kafka / Redis List），
 * 而不是在 purchase() 的请求线程中直接写 DB。
 *
 * 关键点：
 *   - 队列 Worker 用相对量更新：UPDATE stock = stock - qty
 *   - 如果发生重复消费或消息丢失，数据库数据会偏离 Redis 真实值
 *   - 这就是对账同步器要解决的问题：秒杀结束后一次性修正
 */
function simulateQueueProcessor(\PDO $pdo, string $sku, int $quantity, int $amount, string $orderId): bool
{
    // 实际环境中这里是队列 Worker 的执行逻辑
    // 使用 UPDATE ... stock = stock - quantity 做相对扣减
    $sql = "INSERT INTO seckill_orders (order_id, sku, quantity, amount, created_at)
            VALUES (:order_id, :sku, :qty, :amount, NOW())
            ON DUPLICATE KEY UPDATE created_at = created_at";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':order_id' => $orderId, ':sku' => $sku, ':qty' => $quantity, ':amount' => $amount]);

    // 更新库存与销量（相对量）
    $sql = "INSERT INTO product_stock (sku, stock, sales_count, sales_amount)
            VALUES (:sku, :stock, :sales_count, :sales_amount)
            ON DUPLICATE KEY UPDATE
                stock = GREATEST(stock - :qty, 0),
                sales_count = sales_count + :qty,
                sales_amount = sales_amount + :amount";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sku' => $sku,
        ':stock' => 0,
        ':sales_count' => $quantity,
        ':sales_amount' => $amount,
        ':qty' => $quantity,
        ':amount' => $amount,
    ]);

    return true;
}

// 模拟 15 次用户抢购（每次成功都会收到 purchase() 返回的成功结果，
// 业务层拿到成功后创建订单 + 投递 MQ）
$successOrders = [];
for ($i = 1; $i <= 15; $i++) {
    $userId = 'user_' . $i;
    $sku = ($i <= 10) ? 'SECKILL_IPHONE' : 'SECKILL_MACBOOK';
    $quantity = 1;
    $amount = ($sku === 'SECKILL_IPHONE') ? 799900 : 999900;
    $orderId = 'ORD_' . microtime(true) . '_' . $i;

    $result = $manager->purchase($sku, $userId, $quantity, $amount, $orderId, 3);

    if ($result['success']) {
        $successOrders[] = [
            'sku' => $sku,
            'qty' => $quantity,
            'amount' => $amount,
            'orderId' => $orderId,
        ];

        // 模拟：向队列投递一条消息（真实场景推 MQ）
        echo "✓ {$orderId}: {$userId} 抢到 {$sku}, 剩余库存 {$result['data']['remain']}\n";
        // 真实场景：$mq->publish('seckill_orders', json_encode($successOrders[-1]));
    } else {
        echo "✗ {$orderId}: {$result['message']}\n";
    }
}

echo "\n成功秒杀：" . count($successOrders) . " 单\n\n";

// ========== 5. 模拟队列 Worker 处理（假设全都消费成功） ==========
echo "[5] 模拟队列 Worker 处理（相对量更新）...\n";
// 在实际项目中，这里无需手动调用，Worker 自动从 MQ 拉取消息并处理
// 这里我们展示可能的结果
echo "✓ 队列 Worker 已处理 " . count($successOrders) . " 条消息\n";
echo "  每条约等于: UPDATE product_stock SET stock = stock - 1, sales_count = sales_count + 1\n\n";

// ========== 6. 暂停秒杀入口，准备对账 ==========
echo "[6] 暂停秒杀入口，准备对账...\n";
$manager->syncActiveSkus(array_keys($products));
echo "✓ 秒杀入口已暂停\n\n";

// ========== 7. 创建对账同步器，用 Redis 绝对值修正 DB ==========
echo "[7] 创建对账同步器，用 Redis 绝对值修正 DB...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  核心逻辑：\n";
echo "    Redis 中的值是所有 Lua 原子操作的结果，视为唯一真相来源\n";
echo "    如果队列 Worker 有幂等问题或消息丢失\n";
echo "    → DB 中的值偏离了 Redis 的真实值\n";
echo "    → 用 Redis 绝对值覆盖 DB，一次性修正\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

/**
 * 基于 PDO 的对账级数据库同步实现
 *
 * 注意与队列 Worker 的区别：
 *   - 队列 Worker：UPDATE stock = stock - quantity（相对量）
 *   - 对账同步器：UPDATE stock = $redisRemain（绝对值，直接用 Redis 结果覆盖）
 *
 * 建议建表：
 * CREATE TABLE product_stock (
 *   sku varchar(64) NOT NULL,
 *   stock int(11) NOT NULL DEFAULT 0,
 *   sales_count int(11) NOT NULL DEFAULT 0,
 *   sales_amount bigint(20) NOT NULL DEFAULT 0,
 *   synced_at datetime DEFAULT NULL COMMENT '最后对账时间',
 *   PRIMARY KEY (sku)
 * ) ENGINE=InnoDB;
 */
class ReconciliationDbSync implements DatabaseSyncInterface
{
    private $writtenSkus = [];

    public function syncStockAndSales(string $sku, int $remain, int $salesCount, int $salesAmount): void
    {
        // 对账写入：直接覆盖为 Redis 绝对值
        // INSERT ... ON DUPLICATE KEY UPDATE 是幂等的
        $this->writtenSkus[$sku] = [
            'remain' => $remain,
            'salesCount' => $salesCount,
            'salesAmount' => $salesAmount,
        ];

        // 实际 SQL 示意：
        // INSERT INTO product_stock (sku, stock, sales_count, sales_amount, synced_at)
        // VALUES (?, ?, ?, ?, NOW())
        // ON DUPLICATE KEY UPDATE
        //     stock = VALUES(stock),
        //     sales_count = VALUES(sales_count),
        //     sales_amount = VALUES(sales_amount),
        //     synced_at = NOW();

        echo "  → [对账覆盖] {$sku}: stock={$remain}, sales_count={$salesCount}, sales_amount={$salesAmount}\n";
    }

    public function getWritten(): array { return $this->writtenSkus; }
}

$reconciler = new ReconciliationDbSync();

$syncService = new RedisToDatabaseSync(
    $manager->getStockManager(),
    $manager->getSalesManager(),
    $reconciler
);

// 对账同步所有活跃 SKU（秒杀结束后只执行一次）
$result = $syncService->syncAllActive();
echo "✓ 对账同步完成：成功 {$result['success']} 个，失败 {$result['failed']} 个\n\n";

// ========== 8. 展示最终结果 ==========
echo "[8] 最终数据一致性报告...\n";

foreach ($products as $sku => $initStock) {
    $stockInfo = $manager->getStock($sku);
    $redisRemain = $stockInfo['data']['stock'] ?? 0;
    $redisSold = $initStock - $redisRemain;

    $dbData = $reconciler->getWritten()[$sku] ?? null;
    $dbRemain = $dbData['remain'] ?? '?';
    $dbSold = $dbData['salesCount'] ?? '?';

    $match = ($redisRemain === $dbRemain && $redisSold === $dbSold) ? '✓ 一致' : '✗ 偏差';

    echo "  {$sku}:\n";
    echo "    初始: {$initStock} 件\n";
    echo "    Redis: 剩余 {$redisRemain} 件, 卖出 {$redisSold} 件\n";
    echo "    DB:    剩余 {$dbRemain} 件, 卖出 {$dbSold} 件\n";
    echo "    状态: {$match}\n";
}

echo "\n========================================\n";
echo "  对账同步完成\n";
echo "========================================\n\n";
echo "总结：\n\n";
echo "  ┌─────────────────────┬─────────────────┬──────────────────────┐\n";
echo "  │   组件               │  职责            │  写 DB 的方式         │\n";
echo "  ├─────────────────────┼─────────────────┼──────────────────────┤\n";
echo "  │ 异步队列 Worker      │ 秒杀进行中的更新  │ UPDATE stock=stock-1 │\n";
echo "  │ RedisToDatabaseSync  │ 秒杀结束后的对账  │ UPDATE stock=99      │\n";
echo "  └─────────────────────┴─────────────────┴──────────────────────┘\n\n";
echo "为什么互不冲突：\n";
echo "  1. 秒杀期间：只有队列在写 DB（相对增量），同步器不应该运行\n";
echo "  2. 秒杀结束：先暂停秒杀入口，Queue 消费完毕\n";
echo "  3. 最后执行：syncAllActive() 用 Redis 绝对值一次性覆盖 DB\n";
echo "  4. 即使队列有重试/幂等问题导致数据偏差，对账也能一次修正\n\n";
echo "注意：\n";
echo "  - purchase() / cancel() 中不存在自动同步逻辑\n";
echo "  - 如果你没有异步队列，只有这个组件作为唯一数据源\n";
echo "    那么在秒杀结束后调用 syncAllActive() 把最终结果写入 DB 即可\n";
echo "\n";