<?php

/**
 * Laravel 框架中使用 RedisStock 示例
 * 
 * 在 Laravel 项目中，可以将此代码放在 Service Provider 或 Controller 中
 */

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Nermif\PhpRedisStock\RedisStock;

class StockService
{
    /**
     * @var RedisStock
     */
    private $stockManager;

    public function __construct()
    {
        // 1. 获取 Redis 连接
        $redis = Redis::connection()->client();

        // 2. 注入 Laravel 日志器（Laravel Log 已实现 PSR-3 接口）
        $logger = Log::channel('daily');

        // 3. 创建库存管理实例
        $this->stockManager = new RedisStock(
            $redis,
            '{seckill:stock}:',
            $logger
        );
    }

    /**
     * 初始化商品库存
     */
    public function initProductStock(int $productId, int $quantity): bool
    {
        $sku = 'product_' . $productId;
        
        try {
            $created = $this->stockManager->initStocks(
                [$sku => $quantity],
                7200 // 2小时过期
            );
            
            return $created > 0;
        } catch (\RuntimeException $e) {
            Log::error('初始化库存失败', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 秒杀扣减库存
     */
    public function seckillDecr(int $productId, int $quantity = 1): array
    {
        $sku = 'product_' . $productId;

        try {
            // 快速检查是否已售罄
            if ($this->stockManager->isSoldOut($sku)) {
                return [
                    'success' => false,
                    'message' => '商品已售罄',
                    'code' => RedisStock::CODE_ERR_INSUFFICIENT
                ];
            }

            // 扣减库存
            $result = $this->stockManager->decrStock($sku, $quantity);

            switch ($result['code']) {
                case RedisStock::CODE_SUCCESS:
                    return [
                        'success' => true,
                        'message' => '抢购成功',
                        'remain' => $result['remain']
                    ];
                
                case RedisStock::CODE_ERR_INSUFFICIENT:
                    return [
                        'success' => false,
                        'message' => '库存不足',
                        'remain' => $result['remain']
                    ];
                
                case RedisStock::CODE_ERR_NOT_EXISTS:
                    return [
                        'success' => false,
                        'message' => '商品库存未初始化',
                    ];
                
                default:
                    return [
                        'success' => false,
                        'message' => '系统繁忙，请稍后重试',
                    ];
            }

        } catch (\RuntimeException $e) {
            Log::error('秒杀扣减库存异常', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '系统异常，请稍后重试',
            ];
        }
    }

    /**
     * 查询商品库存
     */
    public function getProductStock(int $productId): array
    {
        $sku = 'product_' . $productId;
        
        try {
            $stockInfo = $this->stockManager->getStock($sku);
            
            return [
                'success' => true,
                'stock' => $stockInfo['stock'],
                'sold_out' => $stockInfo['soldOut']
            ];
        } catch (\RuntimeException $e) {
            Log::error('查询库存失败', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '查询失败',
            ];
        }
    }

    /**
     * 订单取消时回滚库存
     */
    public function rollbackStock(int $productId, int $quantity): bool
    {
        $sku = 'product_' . $productId;

        try {
            $result = $this->stockManager->incrStock($sku, $quantity);
            
            return $result['code'] === RedisStock::CODE_SUCCESS;
        } catch (\RuntimeException $e) {
            Log::error('回滚库存失败', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

// ===== 使用示例 =====

// 在 Controller 中调用
// class SeckillController extends Controller
// {
//     public function seckill(Request $request, StockService $stockService)
//     {
//         $productId = $request->input('product_id');
//         
//         $result = $stockService->seckillDecr($productId);
//         
//         if ($result['success']) {
//             return response()->json([
//                 'code' => 200,
//                 'message' => $result['message'],
//                 'remain_stock' => $result['remain']
//             ]);
//         }
//         
//         return response()->json([
//             'code' => 400,
//             'message' => $result['message']
//         ], 400);
//     }
// }
