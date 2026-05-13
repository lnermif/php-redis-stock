# PHP Redis 库存与销售管理库

基于 Redis Lua 脚本的原子化库存扣减与销售记录组件，专为高并发电商场景设计。  
提供**门面 (Facade)** 与**底层原子类**两种模式，兼顾易用性与灵活性。

---

## ✨ 特性

- **原子操作** – 购买下单、取消订单均封装在 Redis Lua 脚本中，杜绝超卖、脏读。
- **售罄快照** – 库存归零时自动生成售罄标记，网关可据此快速拦截无效请求。
- **幂等 & 限购** – 基于订单 ID 的防重处理，支持用户维度的限购控制。
- **集群兼容** – 内置 `{}` Hash Tag，确保相关 Key 落在同一 Redis Cluster Slot。
- **状态自愈** – 提供 `monitor()` / `repair()` 检测并修复库存与售罄标记的不一致。
- **自动重试** – 瞬态故障（网络抖动、主从切换）采用指数退避重试，对业务透明。
- **统一响应** – 门面所有方法返回 `[success, code, message, data]` 结构，调用方解析简单。
- **PSR‑3 日志** – 可注入 PSR‑3 兼容的日志组件，便于生产监控。

---

## ⚙️ 快速开始

### 安装

```bash
composer require nermif/php-redis-stock
```

### 基础使用（推荐门面）

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$manager = new RedisStockSalesManager($redis, '{shop}:');

// 初始化库存
$manager->initStocks(['PHONE' => 100, 'CASE' => 200], 3600);

// 购买下单（原子扣减库存 + 记录销售）
$result = $manager->purchase('PHONE', 'user_123', 1, 99900, 'ORDER_001');
if ($result['success']) {
echo "购买成功，剩余库存：" . $manager->getStock('PHONE')['data']['stock'];
} else {
echo "失败：" . $result['message'];
}

// 取消订单（原子回滚库存 + 销售）
$manager->cancel('PHONE', 1, 99900, 'ORDER_001');
```

### 统一响应格式

门面所有方法均返回：

```php
[
'success' => bool,      // code 是否为 CODE_SUCCESS
'code'    => int,       // 状态码
'message' => string,    // 可读描述
'data'    => mixed,     // 方法特有数据
]
```

---

## 🏁 错误码一览

| 常量 | 值 | 含义 |
|------|----|------|
| `CODE_SUCCESS` | 1 | 成功 |
| `CODE_ERR_INSUFFICIENT` | -1 | 库存不足 |
| `CODE_ERR_NOT_EXISTS` | -2 | 库存未初始化 |
| `CODE_ERR_INVALID_QUANTITY` | -3 | 数量非法（≤0） |
| `CODE_ERR_REDIS_UNAVAILABLE` | -4 | Redis 服务不可用 |
| `CODE_ERR_LIMIT_EXCEEDED` | -5 | 超过限购数量 |
| `CODE_ERR_ALREADY_PROCESSED` | -6 | 订单已处理（幂等拦截） |
| `CODE_ERR_INVALID_AMOUNT` | -7 | 金额非法 |
| `CODE_ERR_ORDER_CANCELED` | -8 | 订单已取消（并发拦截） |
| `CODE_ERR_ORDER_NOT_PROCESSED` | -9 | 订单未处理（取消时） |

门面类和底层类都实现了 `StockSalesCodes` 接口，可以直接通过 `类名::CODE_XXX` 判断返回值。

---

## 🗂️ 示例项目

示例代码位于 `examples/` 目录：

| 文件 | 说明 |
|------|------|
| `facade_usage.php` | ⭐ 推荐 – 门面类完整示例 |
| `seckill_demo.php` | 秒杀全流程演示（库存+销售+排行榜） |
| `stock_usage.php` | 库存高级用法（批量、监控、修复） |
| `framework_usage.php` | Laravel / ThinkPHP 集成代码片段 |

运行示例前确保 Redis 已启动：

```bash
php examples/facade_usage.php
```

---

## 🧩 高级用法（直接使用底层类）

如果需要门面未提供的能力（如批量扣减、自定义重试），可以直接操作 `RedisStock` 和 `RedisSales`：

```php
// 批量扣减（原子：全成功或全失败）
$stockManager = new RedisStock($redis, '{shop}:');
$res = $stockManager->decrMultiStocks(['SKU_A' => 2, 'SKU_B' => 1]);
if ($res['success']) {
foreach ($res['remain'] as $sku => $remain) {
echo "$sku 剩余 $remain\n";
}
}

// 销售记录与排行榜
$salesManager = new RedisSales($redis, '{shop}:');
$salesManager->getSalesLeaderboard(0, 9);
$salesManager->getUserPurchases('user_123');
```

底层类同样实现 `StockSalesCodes`，可使用统一的错误码常量。

---

## 🔧 框架集成

### Laravel
```php
// 在 AppServiceProvider 中注册
$this->app->singleton(RedisStockSalesManager::class, function ($app) {
$redis = $app['redis']->connection()->client();
return new RedisStockSalesManager($redis, '{shop}:', $app['log']);
});

// 控制器中依赖注入即可
public function buy(RedisStockSalesManager $manager, Request $request) {
return $manager->purchase(...);
}
```

### ThinkPHP
```php
$redis = \think\facade\Cache::store('redis')->handler();
$manager = new \Nermif\RedisStockSalesManager($redis, '{mall}:');
```

---

## 🏗️ 内部架构（面向高阶开发者）

系统通过**门面模式**组织对外接口，内部分工清晰：

```
┌─────────────────────────────────┐
│    RedisStockSalesManager       │  ← 推荐入口（统一格式）
│    - purchase()    - cancel()   │
│    - getStock()    - initStocks │
└─────────────┬───────────────────┘
│ 组合
┌──────────────┴──────────────┐
▼                              ▼
┌───────────────┐              ┌───────────────┐
│  RedisStock    │              │  RedisSales   │  ← 底层原子类
│  - 库存 CRUD   │              │  - 销售记录   │
│  - 批量扣减    │              │  - 幂等/限购  │
│  - 监控/修复   │              │  - 排行榜     │
└───────────────┘              └───────────────┘
```

**购买流程**：`purchase()` 内部调用 `recordPurchaseWithStock` Lua 脚本，一次性原子完成：  
库存校验 → 限购检查 → 库存扣减 → 售罄标记 → 记录销量/销售额/排行榜 → 订单幂等标记。

用户无需分别调用 `decrStock()` + `recordPurchase()`，也无需在业务层手动判断售罄。

---

## ⚠️ 生产环境建议

1. **Key 前缀** – 务必使用 `{}` 包裹前缀核心部分（如 `{stock}:`），否则多 Key 操作在集群下会报 `CROSSSLOT`。
2. **Redis 配置** – `maxmemory-policy` 建议设为 `volatile-lru`，避免意外驱逐导致售罄标记丢失。
3. **日志注入** – 建议注入 PSR‑3 日志器。当 `repair()` 触发修复时可通过日志追踪底层异常。
4. **批量操作** – `decrMultiStocks` 虽然支持原子扣减，但单次 SKU 数建议不超过 20，避免长时间阻塞 Redis。
5. **限购过期的理解** – 用户购买记录的 TTL 默认为 30 天，超时后限购计数自动清零，请按业务需求调整。

---

## 🧪 测试

测试套件包含 120+ 用例，覆盖库存、销售、门面层及边界场景。

```bash
./vendor/bin/phpunit
```

---

## 📄 许可

MIT License. 可自由用于个人或商业项目。