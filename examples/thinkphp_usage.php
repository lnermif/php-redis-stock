<?php

/**
 * ThinkPHP 框架中使用 RedisStock 示例
 * 
 * 在 ThinkPHP 6.x/8.x 项目中使用
 */

use think\facade\Log;
use think\facade\Cache;
use Nermif\RedisStock;

class StockService
{
    /**
     * @var RedisStock
     */
    private $stockManager;

    public function __construct()
    {
        // 1. 获取 Redis 连接
        $redis = Cache::store('redis')->handler();

        // 2. 注入 ThinkPHP 日志器（需要适配器）
        $logger = new class implements \Psr\Log\LoggerInterface {
            public function emergency($message, array $context = []): void {
                Log::error($message, $context);
            }
            public function alert($message, array $context = []): void {
                Log::warning($message, $context);
            }
            public function critical($message, array $context = []): void {
                Log::error($message, $context);
            }
            public function error($message, array $context = []): void {
                Log::error($message, $context);
            }
            public function warning($message, array $context = []): void {
                Log::warning($message, $context);
            }
            public function notice($message, array $context = []): void {
                Log::notice($message, $context);
            }
            public function info($message, array $context = []): void {
                Log::info($message, $context);
            }
            public function debug($message, array $context = []): void {
                Log::debug($message, $context);
            }
            public function log($level, $message, array $context = []): void {
                Log::log($level, $message, $context);
            }
        };

        // 3. 创建库存管理实例
        $this->stockManager = new RedisStock(
            $redis,
            '{seckill:stock}:',
            $logger
        );
    }

    /**
     * 秒杀下单（完整流程）
     */
    public function createSeckillOrder(int $userId, int $productId, int $quantity = 1): array
    {
        $sku = 'product_' . $productId;

        try {
            // 1. 预检查库存
            $stockInfo = $this->stockManager->getStock($sku);
            
            if ($stockInfo['stock'] === null) {
                return ['success' => false, 'message' => '商品不存在'];
            }
            
            if ($stockInfo['soldOut']) {
                return ['success' => false, 'message' => '商品已售罄'];
            }
            
            if ($stockInfo['stock'] < $quantity) {
                return ['success' => false, 'message' => '库存不足'];
            }

            // 2. 扣减库存
            $result = $this->stockManager->decrStock($sku, $quantity);

            if ($result['code'] !== RedisStock::CODE_SUCCESS) {
                return $this->handleDecrError($result['code'], $result['remain']);
            }

            // 3. 创建订单（这里简化处理）
            $orderId = $this->createOrder($userId, $productId, $quantity);

            return [
                'success' => true,
                'message' => '下单成功',
                'order_id' => $orderId,
                'remain_stock' => $result['remain']
            ];

        } catch (\RuntimeException $e) {
            Log::error('秒杀下单异常: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统异常'];
        }
    }

    /**
     * 处理扣减错误
     */
    private function handleDecrError(int $code, ?int $remain): array
    {
        switch ($code) {
            case RedisStock::CODE_ERR_INSUFFICIENT:
                return [
                    'success' => false,
                    'message' => '库存不足',
                    'remain' => $remain
                ];
            
            case RedisStock::CODE_ERR_NOT_EXISTS:
                return [
                    'success' => false,
                    'message' => '商品不存在'
                ];
            
            default:
                return [
                    'success' => false,
                    'message' => '系统繁忙，请重试'
                ];
        }
    }

    /**
     * 创建订单（简化示例）
     */
    private function createOrder(int $userId, int $productId, int $quantity): string
    {
        $orderId = 'ORD' . date('YmdHis') . rand(1000, 9999);
        
        // 实际应该写入数据库
        // Db::table('orders')->insert([...]);
        
        return $orderId;
    }

    /**
     * 取消订单并恢复库存
     */
    public function cancelOrder(string $orderId, int $productId, int $quantity): bool
    {
        $sku = 'product_' . $productId;

        try {
            $result = $this->stockManager->incrStock($sku, $quantity);
            
            if ($result['code'] === RedisStock::CODE_SUCCESS) {
                Log::info("订单取消，库存已恢复", [
                    'order_id' => $orderId,
                    'sku' => $sku,
                    'quantity' => $quantity
                ]);
                return true;
            }
            
            return false;
        } catch (\RuntimeException $e) {
            Log::error('取消订单恢复库存失败: ' . $e->getMessage());
            return false;
        }
    }
}

// ===== 使用示例 =====

// 在 Controller 中调用
// namespace app\controller;
// 
// use app\BaseController;
// use think\Request;
// 
// class SeckillController extends BaseController
// {
//     public function seckill(Request $request, StockService $stockService)
//     {
//         $userId = $request->uid; // 从中间件获取用户ID
//         $productId = $request->param('product_id');
//         
//         $result = $stockService->createSeckillOrder($userId, $productId);
//         
//         if ($result['success']) {
//             return json([
//                 'code' => 200,
//                 'msg' => $result['message'],
//                 'data' => [
//                     'order_id' => $result['order_id'],
//                     'remain_stock' => $result['remain_stock']
//                 ]
//             ]);
//         }
//         
//         return json([
//             'code' => 400,
//             'msg' => $result['message']
//         ], 400);
//     }
// }
