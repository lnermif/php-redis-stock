<?php

/**
 * 秒杀场景 + 数据库同步完整演示
 *
 * 演示在秒杀场景下，如何将 Redis 中实时扣减的库存和销量
 * 同步回持久化数据库（如 MySQL）。
 *
 * 包含三种同步模式：
 *  - 即时同步：每次购买成功后立即同步（保证数据一致性，但有一定性能开销）
 *  - 批量同步：秒杀结束后通过定时任务全量同步
 *  - 手动同步：仅在需要时手动触发特定 SKU 同步
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStockSalesManager;
use Nermif\RedisToDatabaseSync;
use Nermif\DatabaseSyncInterface;

echo "========================================\n";
echo "  秒杀场景 + 数据库同步演示\n";
echo "========================================\n\n";

// ========== 示例：使用 PDO 实现 MySQL 同步 ==========
/**
 * 基于 PDO 的 MySQL 数据库同步实现
 *
 * 业务方应根据自身框架（Laravel/ThinkPHP/Yii 等）修改此实现，
 * 使用项目已有的 DB 连接池和连接管理。
 *
 * 数据表结构建议：
 * CREATE TABLE `product_stock` (
 *   `sku` varchar(64) NOT NULL COMMENT '商品SKU',
 *   `stock` int(11) NOT NULL DEFAULT 0 COMMENT '当前剩余库存',
 *   `sales_count` int(11) NOT NULL DEFAULT 0 COMMENT '累计销量',
 *   `sales_amount` bigint(20) NOT NULL DEFAULT 0 COMMENT '累计销售额(分)',
 *   `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`sku`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
class PdoMysqlSync implements DatabaseSyncInterface
{
    /** @var \PDO */
    private $pdo;

    /** @var string 表名 */
    private $tableName;

    public function __construct(\PDO $pdo, string $tableName = 'product_stock')
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    /**
     * 同步库存和销量数据到 MySQL
     *
     * 使用 INSERT ... ON DUPLICATE KEY UPDATE 实现幂等写入，
     * 支持多次重复同步不会导致问题。
     */
    public function syncStockAndSales(string $sku, int $remain, int $salesCount, int $salesAmount): void
    {
        $sql = "INSERT INTO {$this->tableName} (sku, stock, sales_count, sales_amount)
                VALUES (:sku, :stock, :sales_count, :sales_amount)
                ON DUPLICATE KEY UPDATE
                    stock = VALUES(stock),
                    sales_count = VALUES(sales_count),
                    sales_amount = VALUES(sales_amount)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':sku', $sku, \PDO::PARAM_STR);
        $stmt->bindValue(':stock', $remain, \PDO::PARAM_INT);
        $stmt->bindValue(':sales_count', $salesCount, \PDO::PARAM_INT);
        $stmt->bindValue(':sales_amount', $salesAmount, \PDO::PARAM_INT);
        $stmt->execute();
    }
}

// ========== 1. 初始化环境 ==========
echo "[1] 初始化 Redis 连接...\n";
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->select(2);
echo "✓ Redis 连接成功\n\n";

// ========== 2. 创建 MySQL 同步器（模拟） ==========
echo "[2] 创建数据库同步器...\n";
// 实际项目中请使用已有连接，这里我们演示接口实现
// $pdo = new \PDO('mysql:host=127.0.0.1;dbname=seckill', 'root', 'password');
// $dbSync = new PdoMysqlSync($pdo);
// echo "✓ 数据库同步器创建成功\n\n";

// 这里我们用一个日志记录器模拟 DB 同步，方便演示
class MockDbSync implements DatabaseSyncInterface
{
    private $log = [];

    public function syncStockAndSales(string $sku, int $remain, int $salesCount, int $salesAmount): void
    {
        $this->log[] = [
            'sku' => $sku,
            'remain' => $remain,
            'salesCount' => $salesCount,
            'salesAmount' => $salesAmount,
            'time' => date('H:i:s'),
        ];
        echo "  → [DB 同步] {$sku}: 剩余库存 {$remain}, 销量 {$salesCount}, 销售额 {$salesAmount} (分)\n";
    }

    public function getSyncLog(): array
    {
        return $this->log;
    }
}
$mockDbSync = new MockDbSync();
echo "✓ 使用 Mock 同步器（演示用）\n\n";

// ========== 3. 创建秒杀管理器（带自动同步） ==========
echo "[3] 创建秒杀管理器（启用自动同步）...\n";
$manager = new RedisStockSalesManager(
    $redis,
    '{seckill:product}:',
    null,
    null,
    $mockDbSync // 传入 DB 同步接口，开启 purchase/cancel 后自动同步
);
echo "✓ 秒杀管理器创建完成（自动同步已启用）\n\n";

// ========== 4. 库存预热 ==========
echo "[4] 库存预热...\n";
$seckillProducts = [
    'SECKILL_IPHONE_15_PRO' => 100,
    'SECKILL_MACBOOK_AIR' => 50,
    'SECKILL_AIRPODS_PRO' => 200,
];
$result = $manager->initStocks($seckillProducts, 86400);
if ($result['success']) {
    echo "✓ 库存预热完成，新初始化 {$result['data']['initialized_count']} 个商品\n";
}
foreach ($seckillProducts as $sku => $stock) {
    echo "  - {$sku}: {$stock} 件\n";
}
echo "\n";

// ========== 5. 同步预热后的初始数据 ==========
echo "[5] 秒杀活动开始前，将初始数据同步到 DB...\n";
$syncService = new RedisToDatabaseSync(
    $manager->getStockManager(),
    $manager->getSalesManager(),
    $mockDbSync
);
$initSyncResult = $syncService->syncMultiple(array_keys($seckillProducts));
echo "✓ 初始数据同步完成：成功 {$initSyncResult['success']} 个，失败 {$initSyncResult['failed']} 个\n\n";

// ========== 6. 模拟秒杀抢购（每次成功自动同步） ==========
echo "[6] 模拟用户抢购（自动同步模式）...\n";

function doPurchase(RedisStockSalesManager $manager, string $userId, string $sku, int $quantity, int $priceYuan): array
{
    $amount = (int)($priceYuan * 100);
    $orderId = 'ORD_' . uniqid('', true);

    $result = $manager->purchase($sku, $userId, $quantity, $amount, $orderId, 3);

    $status = $result['success'] ? '✓' : '✗';
    echo "{$status} {$userId} → {$sku}: {$result['message']}\n";

    return $result;
}

// 模拟 10 次抢购
$users = ['alice', 'bob', 'charlie', 'david', 'emma', 'frank', 'grace', 'henry', 'ivy', 'jack'];
foreach ($users as $i => $user) {
    usleep(rand(5000, 20000));
    if ($i < 8) {
        doPurchase($manager, $user, 'SECKILL_IPHONE_15_PRO', 1, 7999);
    } else {
        doPurchase($manager, $user, 'SECKILL_MACBOOK_AIR', 1, 9999);
    }
}
echo "\n";

// ========== 7. 模拟订单取消（取消后也会自动同步） ==========
echo "[7] 模拟订单取消...\n";
// 先手动下单记录一下订单号
$cancelResult = $manager->purchase(
    'SECKILL_IPHONE_15_PRO',
    'user_cancel',
    1,
    7999 * 100,
    'ORDER_TO_CANCEL_001'
);
echo "\n";

if ($cancelResult['success']) {
    echo "  正在取消订单 ORDER_TO_CANCEL_001...\n";
    $cancel = $manager->cancel('SECKILL_IPHONE_15_PRO', 1, 7999 * 100, 'ORDER_TO_CANCEL_001');
    $status = $cancel['success'] ? '✓' : '✗';
    echo "{$status} 取消结果: {$cancel['message']}, 回滚后库存: {$cancel['data']['remain']}\n";
}
echo "\n";

// ========== 8. 查看最终数据 ==========
echo "[8] 秒杀结束，查看最终库存和销量...\n";
foreach ($seckillProducts as $sku => $_) {
    $stockInfo = $manager->getStock($sku);
    $stock = $stockInfo['data']['stock'] ?? 0;
    echo "  - {$sku}: 剩余库存 {$stock}\n";
}
echo "\n";

// ========== 9. 秒杀结束后全量同步演示 ==========
echo "[9] 秒杀活动结束，执行全量批量同步...\n";
$fullSyncResult = $syncService->syncAllActive();
echo "✓ 全量同步完成：成功 {$fullSyncResult['success']} 个，失败 {$fullSyncResult['failed']} 个\n\n";

// ========== 10. 清理演示数据 ==========
echo "[10] 清理测试数据...\n";
foreach ($seckillProducts as $sku => $_) {
    $manager->removeActiveSku($sku);
    $stockManager = $manager->getStockManager();
    $stockManager->delStock($sku);
    $salesManager = $manager->getSalesManager();
    $salesManager->clearSalesData($sku);
}
echo "✓ 清理完成\n\n";

echo "========================================\n";
echo "  演示完成\n";
echo "========================================\n";
echo "\n";
echo "三种同步策略对比：\n";
echo "1. 即时同步：购买成功后立即同步到 DB\n";
echo "   - 优点：数据一致性最强，Redis 和 DB 几乎实时一致\n";
echo "   - 缺点：每次购买都要写 DB，QPS 受 DB 性能限制\n";
echo "   - 适用：低并发秒杀活动\n\n";
echo "2. 定时批量：Redis 承担所有秒杀流量，活动结束后统一同步\n";
echo "   - 优点：Redis 扛住高并发，DB 只在结束后写一次，性能最好\n";
echo "   - 缺点：同步前 DB 数据不是最新\n";
echo "   - 适用：高并发大流量秒杀\n\n";
echo "3. 混合模式：秒杀进行中定时（每 1 分钟）同步一次，结束后再全量同步\n";
echo "   - 兼顾性能和数据一致性，推荐大多数场景使用\n";
echo "\n";
