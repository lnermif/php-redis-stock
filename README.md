
---

# RedisStock: 高并发原子库存管理方案

`RedisStock` 是一个基于 PHP 和 Redis Lua 脚本构建的高性能库存管理组件。专为秒杀、大促等高并发场景设计，在确保**绝对不超卖**的同时，通过“售罄快照”机制极大提升了系统的吞吐量。

## 🚀 核心特性

* **原子性保证**：所有核心写操作均封装在 Redis Lua 脚本中执行，利用 Redis 单线程原子性杜绝竞态条件。
* **售罄拦截（Sold-out Marker）**：当库存清零时自动生成轻量级标记，网关层可快速拦截无效请求，保护后端存储。
* **集群兼容**：内置 Hash Tag `{}` 支持，确保库存主 Key 与售罄标记始终落在 Redis Cluster 的同一 Slot。
* **状态自愈**：提供 `monitor()` 与 `repair()` 机制，能够自动识别并修复由于网络抖动或主从切换引起的状态不一致。
* **故障弱化**：内置指数退避（Exponential Backoff）重试逻辑，优雅处理 Redis 瞬态连接故障。
* **高度兼容**：支持 PHP 7.2+，无缝对接任何符合 PSR-3 标准的日志组件。

---

## 🏗️ 架构设计

系统采用“双重校验”模型：

1.  **第一重：轻量级拦截 (`isSoldOut`)**
    通过检查是否存在 `:soldout` 标记来快速判定，无需读取库存数值。
2.  **第二重：原子操作 (`decr/incr`)**
    在 Lua 脚本内部再次校验库存并执行扣减，确保最终一致性。



---

## 🛠️ 安装要求

* **PHP**: >= 7.2
* **Redis**: >= 5.0 (建议 6.0+)
* **PHP 扩展**: `php-redis`

---

## 📖 快速上手

### 1. 初始化
建议使用带有 Hash Tag 的前缀以支持集群模式。

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// 建议前缀格式：{业务标识:库存}:
$stockManager = new RedisStock($redis, '{seckill:stock}:', $logger);
```

### 2. 库存初始化
```php
$stocks = [
    'iphone_15' => 100,
    'macbook_pro' => 50
];
// 设置 3600 秒后自动过期
$stockManager->initStocks($stocks, 3600);
```

### 3. 安全扣减
```php
$result = $stockManager->decrStock('iphone_15', 1);

if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "下单成功，剩余库存：" . $result['remain'];
} elseif ($result['code'] === RedisStock::CODE_ERR_INSUFFICIENT) {
    echo "手慢了，库存不足";
}
```

### 4. 批量扣减（订单事务）
支持多规格（SKU）同时扣减，要么全部成功，要么全部失败。
```php
$items = ['sku_1' => 2, 'sku_2' => 1];
$res = $stockManager->decrMultiStocks($items);

if ($res['success']) {
    // 扣减成功
}
```

---

## 🔍 监控与维护

### 状态一致性检测
在高并发环境下，建议开启异步巡检任务，调用 `monitor` 和 `repair`：

```php
$status = $stockManager->monitor('iphone_15');

if (!$status['consistency']) {
    // 发现状态不一致（如：库存 > 0 但有售罄标记）
    $stockManager->repair('iphone_15');
}
```

### 返回码参考

| 常量 | 值 | 描述 |
| :--- | :--- | :--- |
| `CODE_SUCCESS` | 1 | 操作成功 |
| `CODE_ERR_INSUFFICIENT` | -1 | 库存不足 |
| `CODE_ERR_NOT_EXISTS` | -2 | 库存 Key 不存在（未初始化） |
| `CODE_ERR_INVALID_QUANTITY` | -3 | 传入的数量参数非法 |
| `CODE_ERR_REDIS_UNAVAILABLE`| -4 | Redis 服务异常 |

---

## ⚠️ 生产环境建议

1.  **Redis 逐出策略**：建议将 Redis 的 `maxmemory-policy` 设置为 `volatile-lru`。
2.  **前缀管理**：务必使用 `{}` 包裹前缀的核心部分（如 `{stock}:`），否则 `decr_multi` 等多 Key 操作在集群环境下会报错。
3.  **日志记录**：务必注入 `Logger`。当 `repair` 触发修复动作时，可以通过日志追踪底层系统的异常抖动。
4.  **数量限制**：`decrMultiStocks` 虽然支持原子扣减多个 SKU，但建议单次数量不要超过 20 个，以防止长时间阻塞 Redis 单线程。

---

## 📄 License
MIT License. 可自由用于个人或商业项目。