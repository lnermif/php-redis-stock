<?php

/**
 * RedisStock 高级用法示例
 * 
 * 功能：
 * - 自定义日志器
 * - 批量扣减库存（多规格商品）
 * - 库存监控与自愈
 * - 高并发重试机制
 * - 库存预热
 * - 库存告警
 * - 性能测试
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Nermif\RedisStock;

// ========== 1. 自定义日志器 ==========
echo "=== 自定义日志器 ===\n";

// 简单的文件日志器
$customLogger = new class implements \Psr\Log\LoggerInterface {
    private $logFile;
    
    public function __construct() {
        $this->logFile = __DIR__ . '/../logs/stock.log';
        if (!is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }
    
    public function emergency($message, array $context = []): void {
        $this->write('EMERGENCY', $message, $context);
    }
    public function alert($message, array $context = []): void {
        $this->write('ALERT', $message, $context);
    }
    public function critical($message, array $context = []): void {
        $this->write('CRITICAL', $message, $context);
    }
    public function error($message, array $context = []): void {
        $this->write('ERROR', $message, $context);
    }
    public function warning($message, array $context = []): void {
        $this->write('WARNING', $message, $context);
    }
    public function notice($message, array $context = []): void {
        $this->write('NOTICE', $message, $context);
    }
    public function info($message, array $context = []): void {
        $this->write('INFO', $message, $context);
    }
    public function debug($message, array $context = []): void {
        $this->write('DEBUG', $message, $context);
    }
    public function log($level, $message, array $context = []): void {
        $this->write(strtoupper($level), $message, $context);
    }
    
    private function write(string $level, string $message, array $context): void {
        $log = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );
        file_put_contents($this->logFile, $log, FILE_APPEND | LOCK_EX);
    }
};

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$stockManager = new RedisStock($redis, '{product:stock}:', $customLogger);

// ========== 2. 批量扣减库存（多规格商品）==========
echo "\n=== 批量扣减库存 ===\n";

// 初始化多个规格
$stocks = [
    'IPHONE_15_BLACK_128G' => 50,
    'IPHONE_15_WHITE_128G' => 50,
    'IPHONE_15_BLUE_256G' => 30,
];
$stockManager->initStocks($stocks, 7200);

// 用户一次性购买多个规格
$orderItems = [
    'IPHONE_15_BLACK_128G' => 2,
    'IPHONE_15_WHITE_128G' => 1,
];

$result = $stockManager->decrMultiStocks($orderItems);

if ($result['success']) {
    echo "批量扣减成功！\n";
    foreach ($result['remain'] as $sku => $remain) {
        echo "  {$sku} 剩余: {$remain}\n";
    }
} else {
    echo "批量扣减失败！\n";
    echo "  失败SKU: {$result['sku']}\n";
    echo "  需求数量: {$result['required']}\n";
    echo "  可用数量: {$result['available']}\n";
    echo "  错误码: {$result['code']}\n";
    if (isset($result['message'])) {
        echo "  错误信息: {$result['message']}\n";
    }
}

// ========== 3. 库存监控与自愈 ==========
echo "\n=== 库存监控与自愈 ===\n";

// 3.1 监控库存状态
echo "--- 监控库存状态 ---\n";
$monitorResult = $stockManager->monitor('IPHONE_15_BLACK_128G');
echo "SKU: IPHONE_15_BLACK_128G\n";
echo "  存在: " . ($monitorResult['exists'] ? '是' : '否') . "\n";
echo "  库存: {$monitorResult['stock']}\n";
echo "  TTL: {$monitorResult['ttl']}秒\n";
echo "  售罄标记: " . ($monitorResult['is_sold_out'] ? '有' : '无') . "\n";
echo "  一致性: " . ($monitorResult['consistency'] ? '一致' : '不一致') . "\n";

// 3.2 模拟数据不一致（手动设置错误的售罄标记）
echo "\n--- 模拟数据不一致 ---\n";
$redis->set('{product:stock}:IPHONE_15_BLACK_128G:soldout', 1);
$monitorResult = $stockManager->monitor('IPHONE_15_BLACK_128G');
echo "监控发现不一致: " . ($monitorResult['consistency'] ? '一致' : '不一致') . "\n";

// 3.3 自动修复
echo "\n--- 自动修复 ---\n";
$repairResult = $stockManager->repair('IPHONE_15_BLACK_128G');
echo "修复结果:\n";
echo "  成功: " . ($repairResult['success'] ? '是' : '否') . "\n";
echo "  操作码: {$repairResult['repair_code']}\n";
echo "  操作描述: {$repairResult['action']}\n";

// 验证修复后状态
$monitorResult = $stockManager->monitor('IPHONE_15_BLACK_128G');
echo "修复后一致性: " . ($monitorResult['consistency'] ? '一致' : '不一致') . "\n";

// ========== 4. 高并发场景下的重试机制 ==========
echo "\n=== 高并发重试 ===\n";

// 模拟高并发下的瞬态错误处理
try {
    $result = $stockManager->decrStock('HOT_PRODUCT', 1);
    switch ($result['code']) {
        case RedisStock::CODE_SUCCESS:
            echo "扣减成功，剩余: {$result['remain']}\n";
            break;
        case RedisStock::CODE_ERR_INSUFFICIENT:
            echo "库存不足，剩余: {$result['remain']}\n";
            break;
        case RedisStock::CODE_ERR_NOT_EXISTS:
            echo "库存未初始化\n";
            break;
        case RedisStock::CODE_ERR_REDIS_UNAVAILABLE:
            echo "Redis 服务不可用，请稍后重试\n";
            break;
    }
} catch (\RuntimeException $e) {
    echo "系统异常: {$e->getMessage()}\n";
}

// ========== 5. 库存预热（活动开始前）==========
echo "\n=== 库存预热 ===\n";

function preheatStock(RedisStock $manager, array $products, int $ttl = 3600): void
{
    $stocks = [];
    foreach ($products as $product) {
        $stocks[$product['sku']] = $product['stock'];
    }
    
    $created = $manager->initStocks($stocks, $ttl);
    echo "预热完成，成功创建 {$created} 个商品库存\n";
}

$products = [
    ['sku' => 'SECKILL_001', 'stock' => 100],
    ['sku' => 'SECKILL_002', 'stock' => 200],
];
preheatStock($stockManager, $products, 7200);

// ========== 6. 库存监控和告警 ==========
echo "\n=== 库存监控 ===\n";

function checkLowStock(RedisStock $manager, array $skus, int $threshold = 10): array
{
    $lowStockItems = [];
    $stocksResult = $manager->getStocks($skus);
    
    if ($stocksResult['code'] !== RedisStock::CODE_SUCCESS) {
        return $lowStockItems;
    }
    
    foreach ($stocksResult['data'] as $sku => $stock) {
        if ($stock !== null && $stock <= $threshold) {
            $lowStockItems[] = [
                'sku' => $sku,
                'stock' => $stock,
            ];
        }
    }
    
    return $lowStockItems;
}

$skusToMonitor = ['SECKILL_001', 'SECKILL_002'];
$lowStock = checkLowStock($stockManager, $skusToMonitor, 10);

if (!empty($lowStock)) {
    echo "⚠️ 以下商品库存不足:\n";
    foreach ($lowStock as $item) {
        echo "  {$item['sku']}: {$item['stock']}件\n";
    }
} else {
    echo "✅ 所有商品库存充足\n";
}

// ========== 7. 性能测试 ==========
echo "\n=== 性能测试 ===\n";

$start = microtime(true);
$iterations = 1000;

for ($i = 0; $i < $iterations; $i++) {
    $stockManager->getStock('SECKILL_001');
}

$elapsed = microtime(true) - $start;
$qps = $iterations / $elapsed;

echo "执行 {$iterations} 次查询，耗时: " . round($elapsed, 3) . "秒\n";
echo "QPS: " . round($qps, 2) . "\n";

// ========== 8. 清理测试数据 ==========
echo "\n=== 清理测试数据 ===\n";
$cleanupSkus = [
    'IPHONE_15_BLACK_128G',
    'IPHONE_15_WHITE_128G',
    'IPHONE_15_BLUE_256G',
    'SECKILL_001',
    'SECKILL_002',
];
foreach ($cleanupSkus as $sku) {
    $stockManager->delStock($sku);
}
echo "清理完成\n";
