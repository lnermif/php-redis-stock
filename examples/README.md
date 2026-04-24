# RedisStock 使用示例

本目录包含了 RedisStock 的各种使用示例，帮助你快速上手。

## 📁 示例文件说明

### 1. basic_usage.php - 基础用法
适合初学者，展示了 RedisStock 的基本功能：
- 创建 Redis 连接
- 初始化库存
- 查询库存
- 扣减/增加库存
- 检查售罄状态
- 批量查询
- 删除库存

**运行方式：**
```bash
php examples/basic_usage.php
```

---

### 2. laravel_usage.php - Laravel 框架集成
展示如何在 Laravel 项目中使用 RedisStock：
- 在 Service 中封装库存管理逻辑
- 集成 Laravel 日志系统
- 秒杀场景实现
- 订单取消库存回滚

**使用方式：**
将 `StockService` 类复制到你的 Laravel 项目中，然后在 Controller 中注入使用。

---

### 3. thinkphp_usage.php - ThinkPHP 框架集成
展示如何在 ThinkPHP 6.x/8.x 项目中使用 RedisStock：
- PSR-3 日志适配器
- 完整的秒杀下单流程
- 订单取消处理

**使用方式：**
将 `StockService` 类复制到你的 ThinkPHP 项目中，在 Controller 中调用。

---

### 4. advanced_usage.php - 高级用法
展示高级功能和最佳实践：
- 自定义日志器实现
- 批量扣减库存（多规格商品）
- 高并发重试机制
- 库存预热策略
- 库存监控和告警
- 性能测试

**运行方式：**
```bash
php examples/advanced_usage.php
```

---

### 5. seckill_demo.php - 秒杀场景完整演示
一个完整的秒杀场景模拟，包括：
- 活动前库存预热
- 多用户并发抢购
- 批量购买套装
- 订单取消库存回滚
- 性能统计
- 自动清理测试数据

**运行方式：**
```bash
php examples/seckill_demo.php
```

---

## 🚀 快速开始

### 前置要求

1. PHP >= 7.2.0
2. Redis 扩展已安装
3. Composer 依赖已安装

### 安装依赖

```bash
composer install
```

### 启动 Redis

确保 Redis 服务正在运行：

```bash
redis-server
```

### 运行示例

```bash
# 基础用法
php examples/basic_usage.php

# 高级用法
php examples/advanced_usage.php

# 秒杀演示
php examples/seckill_demo.php
```

---

## 💡 核心概念

### 1. Hash Tag 集群兼容

使用 `{key}:` 格式确保相关键落在同一个 Redis 槽位：

```php
$stockManager = new RedisStock($redis, '{product:stock}:');
```

### 2. 返回码说明

| 常量 | 值 | 说明 |
|------|-----|------|
| `CODE_SUCCESS` | 1 | 操作成功 |
| `CODE_ERR_INSUFFICIENT` | -1 | 库存不足 |
| `CODE_ERR_NOT_EXISTS` | -2 | 库存未初始化 |
| `CODE_ERR_INVALID_QUANTITY` | -3 | 数量非法 |
| `CODE_ERR_REDIS_UNAVAILABLE` | -4 | Redis 不可用 |

### 3. 售罄标记

当库存扣减至 0 时，会自动设置售罄标记 `:soldout`，可用于快速拦截无效请求。

---

## 🔧 常见场景

### 场景1: 单商品秒杀

```php
// 初始化
$stockManager->initStocks(['IPHONE_15' => 100], 7200);

// 扣减
$result = $stockManager->decrStock('IPHONE_15', 1);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "抢购成功，剩余: {$result['remain']}";
}
```

### 场景2: 多规格商品

```php
// 用户购买套装：手机 x1 + 壳 x1 + 充电器 x1
$items = [
    'PHONE' => 1,
    'CASE' => 1,
    'CHARGER' => 1,
];

$result = $stockManager->decrMultiStocks($items);
if ($result['success']) {
    echo "套装购买成功！";
}
```

### 场景3: 库存回滚

```php
// 订单取消，恢复库存
$result = $stockManager->incrStock('PRODUCT_SKU', 2);
if ($result['code'] === RedisStock::CODE_SUCCESS) {
    echo "库存已恢复: {$result['remain']}";
}
```

---

## 📊 性能优化建议

1. **使用售罄标记快速拦截**
   ```php
   if ($stockManager->isSoldOut($sku)) {
       return ['success' => false, 'message' => '已售罄'];
   }
   ```

2. **批量操作代替循环**
   ```php
   // ❌ 不推荐
   foreach ($items as $sku => $qty) {
       $stockManager->decrStock($sku, $qty);
   }
   
   // ✅ 推荐
   $stockManager->decrMultiStocks($items);
   ```

3. **合理设置 TTL**
   ```php
   // 活动库存设置过期时间，避免永久占用内存
   $stockManager->initStocks($stocks, 7200); // 2小时
   ```

---

## 🐛 故障排查

### 问题1: 连接失败
```
RedisException: Connection refused
```
**解决：** 确保 Redis 服务正在运行
```bash
redis-cli ping  # 应该返回 PONG
```

### 问题2: 类找不到
```
Class 'Nermif\PhpRedisStock\RedisStock' not found
```
**解决：** 运行 `composer install` 并确保正确引入 autoload

### 问题3: Lua 脚本错误
```
NOSCRIPT No matching script
```
**解决：** 这是正常的降级流程，系统会自动使用 EVAL 执行

---

## 📚 更多资源

- 查看 [src/RedisStock.php](../src/RedisStock.php) 了解完整 API
- 阅读源码中的注释了解每个方法的详细说明
- 查看 composer.json 了解依赖要求

---

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 许可证

MIT License
