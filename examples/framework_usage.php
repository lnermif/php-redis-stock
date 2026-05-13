<?php

/**
 * 框架集成示例（Laravel / ThinkPHP）
 *
 * 推荐通过依赖注入使用 RedisStockSalesManager 门面类
 */

// ------- Laravel 示例 -------
// 1. 注册服务提供者（app/Providers/StockServiceProvider.php）
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Nermif\RedisStockSalesManager;

class StockServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(RedisStockSalesManager::class, function ($app) {
            $redis = $app['redis']->connection()->client();
            return new RedisStockSalesManager($redis, '{shop}:', $app['log']);
        });
    }
}

// 2. 在控制器中使用
class OrderController
{
    public function buy(RedisStockSalesManager $manager, Request $request)
    {
        $result = $manager->purchase(
            $request->sku,
            $request->user_id,
            $request->quantity,
            $request->amount,
            $request->order_id,
            $request->limit ?? 0
        );

        if ($result['success']) {
            return response()->json(['code' => 200, 'data' => $result['data']]);
        }
        return response()->json(['code' => 400, 'message' => $result['message']], 400);
    }
}

// ------- ThinkPHP 示例 -------
// 1. 在服务类中定义（app\common\StockService.php）
namespace app\common;

use Nermif\RedisStockSalesManager;
use think\facade\Cache;

class StockService
{
    protected $manager;

    public function __construct()
    {
        $redis = Cache::store('redis')->handler();
        $this->manager = new RedisStockSalesManager($redis, '{mall}:');
    }

    public function seckill(string $sku, string $userId, int $qty, int $amount, string $orderId)
    {
        return $this->manager->purchase($sku, $userId, $qty, $amount, $orderId);
    }
}

// 2. 控制器调用
class SeckillController
{
    public function run(\app\common\StockService $stock, Request $request)
    {
        $res = $stock->seckill(
            $request->param('sku'),
            $request->param('user_id'),
            $request->param('qty', 1),
            $request->param('amount'),
            $request->param('order_id')
        );
        return json($res);
    }
}