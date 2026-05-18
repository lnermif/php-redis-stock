# php-redis-stock 项目 Code Wiki

> **项目名称**：`nermif/php-redis-stock`  
> **描述**：基于 Redis Lua 脚本的原子化库存扣减与销售记录组件，专为高并发电商场景设计  
> **许可证**：MIT  
> **PHP 版本要求**：>= 7.2.0  
> **代码仓库**：https://github.com/nermif/php-redis-stock

---

## 目录

1. [项目概述](#1-项目概述)
2. [系统架构](#2-系统架构)
3. [目录结构](#3-目录结构)
4. [模块职责详解](#4-模块职责详解)
   - [4.1 RedisConstants — 常量定义中心](#41-redisconstants--常量定义中心)
   - [4.2 StockSalesCodes — 错误码接口](#42-stocksalescodes--错误码接口)
   - [4.3 IdSanitizer — ID 安全校验 Trait](#43-idsanitizer--id-安全校验-trait)
   - [4.4 AbstractRedisManager — 抽象基类](#44-abstractredismanager--抽象基类)
   - [4.5 RedisStock — 库存管理原子类](#45-redisstock--库存管理原子类)
   - [4.6 RedisSales — 销售管理原子类](#46-redissales--销售管理原子类)
   - [4.7 RedisStockSalesManager — 门面类（推荐入口）](#47-redisstocksalesmanager--门面类推荐入口)
5. [关键数据流与处理逻辑](#5-关键数据流与处理逻辑)
6. [依赖关系图](#6-依赖关系图)
7. [Redis Key 设计规范](#7-redis-key-设计规范)
8. [错误码体系](#8-错误码体系)
9. [重试与容错机制](#9-重试与容错机制)
10. [集群兼容策略](#10-集群兼容策略)
11. [项目运行与测试](#11-项目运行与测试)
12. [框架集成指南](#12-框架集成指南)
13. [安全与最佳实践](#13-安全与最佳实践)

---

## 1. 项目概述

`php-redis-stock` 是一个专为 PHP 高并发电商秒杀/抢购场景设计的 Redis 库存与销售管理库。其核心设计理念是：

- **所有写操作均由 Redis Lua 脚本原子执行**，杜绝超卖、脏读
- 通过**门面模式（Facade）**对外提供简洁统一的 API
- 支持**库存扣减、销售记录、排行榜、限购控制、订单回滚**等完整业务闭环

### 核心特性

| 特性 | 描述 |
|------|------|
| 原子操作 | 购买下单、取消订单均封装在 Redis Lua 脚本中 |
| 售罄快照 | 库存归零时自动生成售罄标记，网关可据此快速拦截无效请求 |
| 幂等 & 限购 | 基于订单 ID 的防重处理，支持用户维度的限购控制 |
| 集群兼容 | 内置 `{}` Hash Tag，确保相关 Key 落在同一 Redis Cluster Slot |
| 状态自愈 | `monitor()` / `repair()` 检测并修复库存与售罄标记的不一致 |
| 自动重试 | 瞬态故障（网络抖动、主从切换）采用指数退避重试 |
| 统一响应 | 门面所有方法返回 `[success, code, message, data]` 结构 |
| PSR-3 日志 | 可注入 PSR-3 兼容的日志组件 |

---

## 2. 系统架构

系统采用**门面模式 + 组合模式**，分为两个层级：

```
┌─────────────────────────────────────────────────────────────┐
│                   RedisStockSalesManager                      │
│                   （门面 / 推荐入口）                           │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  purchase()  │  cancel()  │  initStocks()           │    │
│  │  getStock()  │  isSoldOut()  │  monitor() / repair() │    │
│  │  incrStock() │  decrStock() │  syncActiveSkus()     │    │
│  └──────────────────────┬──────────────────────────────┘    │
│                         │ 组合（持有引用）                      │
│          ┌──────────────┴──────────────┐                     │
│          ▼                              ▼                     │
│  ┌───────────────┐            ┌───────────────────┐          │
│  │  RedisStock    │            │    RedisSales      │          │
│  │  (库存原子类)   │            │   (销售原子类)      │          │
│  │               │            │                    │          │
│  │ - 库存 CRUD    │            │ - 购买记录           │          │
│  │ - 批量扣减      │            │ - 幂等/限购          │          │
│  │ - 售罄标记      │            │ - 排行榜             │          │
│  │ - 监控/修复     │            │ - 订单回滚           │          │
│  └──────┬────────┘            └────────┬───────────┘          │
│         │  extends                       │  extends              │
│         └────────────┬──────────────────┘                     │
│                      ▼                                         │
│          ┌───────────────────────┐                             │
│          │  AbstractRedisManager │  ← 抽象基类                  │
│          │  - Lua脚本加载/执行     │                             │
│          │  - 指数退避重试         │                             │
│          │  - PSR-3 日志注入      │                             │
│          │  - Redis Cluster 校验  │                             │
│          │  uses: IdSanitizer     │                             │
│          └───────────────────────┘                             │
└─────────────────────────────────────────────────────────────┘

          ┌───────────────────────┐
          │   RedisConstants      │  ← 常量定义（错误码、Key后缀、TTL等）
          └───────────────────────┘
                      ▲
                      │ implements
          ┌───────────────────────┐
          │   StockSalesCodes     │  ← 错误码统一接口
          └───────────────────────┘
```

---

## 3. 目录结构

```
php-redis-stock/
├── .github/
│   └── workflows/
│       └── php.yml                # GitHub Actions CI：PHP 7.4 + Redis + PHPUnit
├── examples/                      # 使用示例
│   ├── README.md
│   ├── facade_usage.php           # ⭐ 门面类完整示例
│   ├── framework_usage.php        # Laravel / ThinkPHP 集成
│   ├── seckill_demo.php           # 秒杀全流程演示
│   └── stock_usage.php            # 库存高级用法
├── src/                           # 核心源代码
│   ├── RedisConstants.php         # Redis Key 后缀、错误码、TTL、重试策略、Lua占位符
│   ├── StockSalesCodes.php        # 业务操作码统一接口
│   ├── IdSanitizer.php            # ID 安全校验 Trait
│   ├── AbstractRedisManager.php   # 抽象基类（Lua管理、重试、日志）
│   ├── RedisStock.php             # 库存管理原子类
│   ├── RedisSales.php             # 销售管理原子类
│   └── RedisStockSalesManager.php # 门面类（推荐入口）
├── tests/                         # 单元测试（120+ 用例）
│   ├── RedisStockTest.php         # 库存模块测试
│   ├── RedisSalesTest.php         # 销售模块测试
│   └── RedisStockSalesManagerTest.php # 门面集成测试
├── composer.json                  # Composer 配置
├── composer.lock
└── README.md
```

---

## 4. 模块职责详解

### 4.1 RedisConstants — 常量定义中心

**文件**：[src/RedisConstants.php](file:///workspace/src/RedisConstants.php)  
**类签名**：`final class RedisConstants`  
**职责**：统一管理所有 Redis 相关常量，包括业务错误码、Key 后缀、重试策略配置、过期时间和 Lua 脚本占位符。

#### 错误码常量

| 常量 | 值 | 含义 |
|------|----|------|
| `CODE_SUCCESS` | 1 | 操作成功 |
| `CODE_ERR_INSUFFICIENT` | -1 | 库存不足 |
| `CODE_ERR_NOT_EXISTS` | -2 | 库存未初始化 |
| `CODE_ERR_INVALID_QUANTITY` | -3 | 数量非法（≤0 或 ID 非法） |
| `CODE_ERR_REDIS_UNAVAILABLE` | -4 | Redis 服务不可用 |
| `CODE_ERR_LIMIT_EXCEEDED` | -5 | 超过限购数量 |
| `CODE_ERR_ALREADY_PROCESSED` | -6 | 订单已处理（幂等拦截） |
| `CODE_ERR_INVALID_AMOUNT` | -7 | 金额非法 |
| `CODE_ERR_ORDER_CANCELED` | -8 | 订单已取消（并发拦截） |
| `CODE_ERR_ORDER_NOT_PROCESSED` | -9 | 订单未处理（取消失败） |

#### Key 后缀常量

| 常量 | 值 | 说明 |
|------|----|------|
| `SOLD_OUT_SUFFIX` | `:soldout` | 售罄标记后缀 |
| `USER_BOUGHT_HASH_SUFFIX` | `:user_bought` | 用户购买记录 Hash 后缀 |
| `SALES_COUNT_SUFFIX` | `:sales_count` | 商品销量统计后缀 |
| `SALES_AMOUNT_SUFFIX` | `:sales_amount` | 商品销售额统计后缀 |
| `LEADERBOARD_COUNT_SUFFIX` | `:leaderboard:count` | 销量排行榜 ZSet 后缀 |
| `LEADERBOARD_AMOUNT_SUFFIX` | `:leaderboard:amount` | 销售额排行榜 ZSet 后缀 |
| `USER_PURCHASED_SET_PREFIX` | `user:` | 用户购买集合前缀 |
| `ORDER_IDEMPOTENT_PREFIX` | `order:` | 订单幂等标记前缀 |
| `ORDER_CANCELED_SUFFIX` | `:canceled` | 订单取消标记后缀 |
| `ACTIVE_SKUS_KEY` | `active:skus` | 活跃 SKU 集合 Key 后缀 |

#### 重试与过期配置

| 常量 | 值 | 说明 |
|------|----|------|
| `DEFAULT_MAX_RETRIES` | 2 | 默认最大重试次数 |
| `RETRY_BASE_DELAY_MICROSECONDS` | 10000 (10ms) | 重试基础延迟 |
| `RETRY_MAX_DELAY_MICROSECONDS` | 200000 (200ms) | 重试最大延迟 |
| `DEFAULT_USER_RECORD_TTL` | 2592000 (30天) | 用户购买记录过期时间 |
| `DEFAULT_ORDER_TTL` | 86400 (24小时) | 订单幂等标记过期时间 |
| `MAX_TTL` | 2592000 (30天) | 最大过期时间 |

---

### 4.2 StockSalesCodes — 错误码接口

**文件**：[src/StockSalesCodes.php](file:///workspace/src/StockSalesCodes.php)  
**类型**：`interface StockSalesCodes`  

定义统一的业务操作码接口常量。所有底层模块和门面类均实现此接口，使常量定义只依赖 `RedisConstants`。

```php
interface StockSalesCodes
{
    public const CODE_SUCCESS                = 1;
    public const CODE_ERR_INSUFFICIENT       = -1;
    public const CODE_ERR_NOT_EXISTS         = -2;
    public const CODE_ERR_INVALID_QUANTITY   = -3;
    public const CODE_ERR_REDIS_UNAVAILABLE  = -4;
    public const CODE_ERR_LIMIT_EXCEEDED     = -5;
    public const CODE_ERR_ALREADY_PROCESSED  = -6;
    public const CODE_ERR_INVALID_AMOUNT     = -7;
    public const CODE_ERR_ORDER_CANCELED     = -8;
    public const CODE_ERR_ORDER_NOT_PROCESSED = -9;
}
```

---

### 4.3 IdSanitizer — ID 安全校验 Trait

**文件**：[src/IdSanitizer.php](file:///workspace/src/IdSanitizer.php)  
**类型**：`trait IdSanitizer`

提供 ID 安全性校验，防止包含冒号、空格、换行等特殊字符导致 Redis Key 混淆。

#### 方法

| 方法 | 签名 | 描述 |
|------|------|------|
| `isValidId` | `isValidId(string $id): bool` | 验证 ID 只包含 `[a-zA-Z0-9_\-]` 且非空 |

正则约束：`/^[a-zA-Z0-9_\-]+$/`

> **注**：`orderId` 未经过 `isValidId` 校验（无特殊字符限制），因为订单 ID 本身直接拼接为 Redis Key 的一部分，不存在 Key 混淆风险。

---

### 4.4 AbstractRedisManager — 抽象基类

**文件**：[src/AbstractRedisManager.php](file:///workspace/src/AbstractRedisManager.php)  
**类签名**：`abstract class AbstractRedisManager`  
**使用 Trait**：`IdSanitizer`

是整个系统的基础设施层，所有原子操作类（`RedisStock`、`RedisSales`）继承自此基类。

#### 核心属性

| 属性 | 类型 | 描述 |
|------|------|------|
| `$redis` | `\Redis` | Redis 连接实例 |
| `$keyPrefix` | `string` | Key 前缀（建议含 Hash Tag `{}`） |
| `$logger` | `LoggerInterface\|NullLogger` | PSR-3 日志器，默认 `NullLogger` |
| `$scriptShas` | `array` | Lua 脚本 SHA1 值缓存 |
| `$maxRetries` | `int` | 最大重试次数，默认 `RedisConstants::DEFAULT_MAX_RETRIES` |

#### 构造函数流程

```php
public function __construct(
    \Redis           $redis,
    string           $keyPrefix = '{product:stock}:',
    ?LoggerInterface $logger = null,
    ?int             $maxRetries = null
) {
    // 1. 存储属性
    // 2. 调用 $this->loadScripts() 预加载所有 Lua 脚本到 Redis
    // 3. 调用 $this->verifyKeyPrefix() 检查集群兼容性
}
```

#### 核心方法详解

##### `execLua(string $scriptName, array $keys, array $args)` — Lua 脚本执行

[AbstractRedisManager.php:L153-L175](file:///workspace/src/AbstractRedisManager.php#L153-L175)

- 优先使用 `EVALSHA`（通过 SHA1 缓存）执行，性能更高
- 若收到 `NOSCRIPT` 错误（脚本因重启丢失），自动降级为 `EVAL` 重新执行原始脚本

##### `execLuaWithRetry(string $scriptName, array $keys, array $args)` — 带重试的 Lua 执行

[AbstractRedisManager.php:L185-L219](file:///workspace/src/AbstractRedisManager.php#L185-L219)

- 捕获 `RedisException`，通过 `isTransientError()` 判断是否为瞬态故障
- 瞬态故障执行指数退避重试：延迟 = `2^(attempt-1) * 10ms`，上限 200ms
- 非瞬态故障或重试耗尽后抛出 `RuntimeException`

##### `readWithRetry(callable $operation)` — 带重试的读操作

[AbstractRedisManager.php:L230-L264](file:///workspace/src/AbstractRedisManager.php#L230-L264)

适用于 `get`、`mget`、`exists`、`sMembers` 等只读 Redis 命令。

##### `writeWithRetry(callable $operation)` — 带重试的写操作

[AbstractRedisManager.php:L275-L309](file:///workspace/src/AbstractRedisManager.php#L275-L309)

适用于 `del`、`set`、`zRem` 等写命令。

##### `pipelineWithRetry(callable $operation)` — 带重试的 Pipeline 操作

[AbstractRedisManager.php:L320-L354](file:///workspace/src/AbstractRedisManager.php#L320-L354)

适用于需要原子性批处理的 Pipeline 操作（如 `monitor()` 中的批量查询）。

##### `isTransientError(\RedisException $e): bool` — 瞬态故障检测

[AbstractRedisManager.php:L117-L151](file:///workspace/src/AbstractRedisManager.php#L117-L151)

识别以下瞬态错误模式：

| 模式 | 说明 |
|------|------|
| `READONLY` | 主从切换中（触发 `$redis->reset()`） |
| `Connection refused` | Redis 服务未就绪 |
| `Connection timed out` | 网络超时 |
| `CLUSTERDOWN` | 集群故障 |
| `TRYAGAIN` | 集群重定向 |
| `MASTERDOWN` | 主节点不可用 |
| `MOVED` / `ASK` | 集群 Slot 迁移 |
| `OOM command not allowed` | Redis 内存溢出 |

##### `prepareScript(string $scriptName, string $script): string` — 脚本预处理

[AbstractRedisManager.php:L70-L81](file:///workspace/src/AbstractRedisManager.php#L70-L81)

在脚本加载到 Redis 前，替换 Lua 源码中的占位符（如 `{{USER_RECORD_TTL}}` → `2592000`）。

##### `verifyKeyPrefix(): void` — 集群兼容性校验

[AbstractRedisManager.php:L360-L367](file:///workspace/src/AbstractRedisManager.php#L360-L367)

检查 `$keyPrefix` 是否包含 `{}` Hash Tag，缺失时输出 Warning 日志。

---

### 4.5 RedisStock — 库存管理原子类

**文件**：[src/RedisStock.php](file:///workspace/src/RedisStock.php)  
**类签名**：`class RedisStock extends AbstractRedisManager implements StockSalesCodes`

负责库存 CRUD、批量扣减、售罄标记管理和状态自愈。

#### 内嵌 Lua 脚本

##### `init` — 批量初始化库存

- 遍历 KEYS，仅操作不存在的 Key（幂等）
- 初始化库存为指定值
- 若库存 ≤ 0，立即写入售罄标记
- 若 TTL > 0，设置库存 Key 和售罄标记 Key 的过期时间

##### `decr` — 原子扣减库存

- 检查库存是否初始化 → 不存在返回 `CODE_ERR_NOT_EXISTS`
- 校验库存是否充足 → 不足返回 `CODE_ERR_INSUFFICIENT`
- 执行 `DECRBY`，若剩余为 0 → 自动写入售罄标记（继承库存 TTL）

##### `incr` — 原子增加库存

- 库存不存在 → 返回 `CODE_ERR_NOT_EXISTS`
- 执行 `INCRBY`，若结果 > 0 → 清除售罄标记

##### `decr_multi` — 批量原子扣减

- **两遍策略**：第一遍全量校验，第二遍统一扣减
- 任一 SKU 不满足条件（不存在/库存不足）→ 整体失败，校验阶段即返回，不执行任何扣减
- 所有 SKU 全部满足 → 统一扣减，并为扣至 0 的 SKU 设置售罄标记

##### `repair` — 状态自愈

- **场景 0**：库存和售罄标记都不存在 → 正常状态
- **场景 1**：有库存但有售罄标记 → 删除售罄标记
- **场景 2**：无库存但无售罄标记 → 补全售罄标记
- **场景 3**：库存与售罄标记一致 → 无需操作
- **场景 4**：主库存 Key 不存在但有售罄标记 → 清理孤立标记

#### 关键方法

| 方法 | 签名 | 返回 | 说明 |
|------|------|------|------|
| `initStocks` | `initStocks(array $stocks, int $ttl=0): int` | 成功初始化的 SKU 数 | 批量初始化，幂等（已存在不覆盖） |
| `getStock` | `getStock(string $sku): array` | `['code'=>int, 'stock'=>int\|null, 'soldOut'=>bool]` | 查询单商品库存+售罄状态 |
| `getStocks` | `getStocks(array $skus): array` | `['code'=>int, 'data'=>['sku'=>int\|null]]` | 批量查询库存 |
| `isSoldOut` | `isSoldOut(string $sku): array` | `['code'=>int, 'soldOut'=>bool]` | 轻量售罄查询，适合网关拦截 |
| `decrStock` | `decrStock(string $sku, int $qty): array` | `['code'=>int, 'remain'=>int\|null]` | 原子扣减 |
| `incrStock` | `incrStock(string $sku, int $qty): array` | `['code'=>int, 'remain'=>int\|null]` | 原子补货/退款 |
| `decrMultiStocks` | `decrMultiStocks(array $items): array` | `['success'=>bool, 'code'=>int, 'remain'=>array]` | 批量原子扣减 |
| `monitor` | `monitor(string $sku): array` | 含 `consistency` 字段的一致性报告 | 检测库存与售罄标记的一致性 |
| `repair` | `repair(string $sku): array` | `['code'=>int, 'success'=>bool, 'action'=>string]` | 修复不一致状态 |
| `isSkuActive` | `isSkuActive(string $sku): bool` | `bool` | 检查 SKU 是否在活跃集合中 |
| `syncActiveSkus` | `syncActiveSkus(array $skus): void` | void | 全量同步活跃 SKU 集合 |
| `getActiveSkus` | `getActiveSkus(): array` | `array` | 获取所有活跃 SKU |
| `removeActiveSku` | `removeActiveSku(string $sku): void` | void | 从活跃集合中移除 SKU |
| `delStock` | `delStock(string $sku): array` | `['code'=>int, 'deleted'=>int]` | 删除库存+售罄标记 |

---

### 4.6 RedisSales — 销售管理原子类

**文件**：[src/RedisSales.php](file:///workspace/src/RedisSales.php)  
**类签名**：`class RedisSales extends AbstractRedisManager implements StockSalesCodes`

负责销售记录、幂等控制、限购管理、排行榜和订单回滚。

#### 内嵌 Lua 脚本

##### `record_purchase` — 纯记录销售（不涉及库存）

1. **幂等检查**：`ORDER_KEY` 存在 → `CODE_ERR_ALREADY_PROCESSED`
2. **限购检查**：查询 `USER_BOUGHT_HASH`，若 `已购 + 本次 > 限购` → `CODE_ERR_LIMIT_EXCEEDED`
3. **记录购买**：HINCRBY 用户购买数、SADD 用户 SKU 集合
4. **更新统计**：INCRBY 销量/销售额、ZINCRBY 排行榜
5. **幂等标记**：SETEX ORDER_KEY 24h

##### `record_purchase_with_stock` — 原子扣减库存并记录销售

在 `record_purchase` 基础上增加：

0. **取消拦截**：CANCEL_KEY 存在 → `CODE_ERR_ORDER_CANCELED`
1. **库存检查** → `CODE_ERR_NOT_EXISTS` / `CODE_ERR_INSUFFICIENT`
2. **库存扣减** → DECRBY，库存归零时设置售罄标记

##### `cancel_order_with_stock` — 原子回滚

1. 幂等：CANCEL_KEY 存在 → `CODE_ERR_ORDER_CANCELED`（不重复回滚）
2. 校验：ORDER_KEY 不存在 → `CODE_ERR_ORDER_NOT_PROCESSED`
3. 回滚库存 → INCRBY，清除售罄标记
4. 回滚销售数据 → DECRBY 销量/销售额，ZINCRBY 负值
5. 删除订单幂等标记，写入取消标记

#### 关键方法

| 方法 | 签名 | 说明 |
|------|------|------|
| `recordPurchase` | `recordPurchase(sku, userId, qty, amount, orderId, limitPerUser=0)` | 仅记录销售（不扣库存） |
| `recordPurchaseWithStock` | `recordPurchaseWithStock(sku, userId, qty, amount, orderId, limitPerUser=0)` | 原子扣减库存+记录销售 |
| `cancelOrderWithStock` | `cancelOrderWithStock(sku, qty, amount, orderId)` | 回滚库存+销售数据 |
| `getUserPurchases` | `getUserPurchases(userId)` | 查询用户所有购买记录 |
| `getUserPurchaseCount` | `getUserPurchaseCount(sku, userId)` | 查询用户某 SKU 的购买数量 |
| `getRemainingLimit` | `getRemainingLimit(sku, userId, limit)` | 查询用户剩余限购数量 |
| `getSalesCount` | `getSalesCount(sku)` | 获取商品总销量 |
| `getSalesAmount` | `getSalesAmount(sku)` | 获取商品总销售额（分） |
| `getMultipleSalesCounts` | `getMultipleSalesCounts(skus)` | 批量获取销量 |
| `getSalesLeaderboard` | `getSalesLeaderboard(start, stop, withScores=true)` | 销量排行榜（ZREVRANGE） |
| `getAmountLeaderboard` | `getAmountLeaderboard(start, stop, withScores=true)` | 销售额排行榜 |
| `isOrderProcessed` | `isOrderProcessed(orderId)` | 检查订单是否已处理 |
| `clearSalesData` | `clearSalesData(sku)` | 清除某 SKU 的全部销售数据 |

---

### 4.7 RedisStockSalesManager — 门面类（推荐入口）

**文件**：[src/RedisStockSalesManager.php](file:///workspace/src/RedisStockSalesManager.php)  
**类签名**：`class RedisStockSalesManager implements StockSalesCodes`

推荐使用入口，封装 `RedisStock` 和 `RedisSales`，提供**统一返回格式**和**参数快速失败校验**。

#### 统一响应格式

所有门面方法均返回：

```php
[
    'success' => bool,      // 是否为 CODE_SUCCESS
    'code'    => int,       // 业务状态码
    'message' => string,    // 人类可读描述
    'data'    => mixed,     // 该操作特有的业务数据
]
```

内部通过 `private function response(int $code, string $message, $data = null)` 统一构造。

#### 核心方法

| 方法 | 描述 | data 关键字段 |
|------|------|----|
| `purchase(sku, userId, qty, amount, orderId, limitPerUser=0)` | 购买下单 | `sku`, `user_id`, `order_id`, `total_sales`, `remain`, `remaining_limit` |
| `cancel(sku, qty, amount, orderId)` | 取消订单 | `sku`, `order_id`, `remain` |
| `initStocks(stocks, ttl=0)` | 批量初始化库存 | `initialized_count` |
| `incrStock(sku, qty)` | 增加库存 | `remain` |
| `decrStock(sku, qty)` | 手动扣除库存 | `remain` |
| `getStock(sku)` | 查询库存+售罄 | `stock`, `soldOut` |
| `isSoldOut(sku)` | 轻量售罄查询 | `soldOut` |
| `monitor(sku)` | 一致性监控 | `exists`, `stock`, `ttl`, `is_sold_out`, `consistency` |
| `repair(sku)` | 一致性修复 | `repair_code` |
| `syncActiveSkus(skus)` | 同步活跃 SKU | null |
| `getActiveSkus()` | 获取活跃 SKU | `string[]` |
| `removeActiveSku(sku)` | 下架 SKU | null |

#### 快速失败校验

`purchase()` 方法在调用底层 Lua 之前进行了多层参数校验：

1. `orderId` 为空 → `CODE_ERR_INVALID_QUANTITY`
2. `limitPerUser < 0` → `CODE_ERR_INVALID_QUANTITY`
3. SKU 包含非法字符 → `CODE_ERR_INVALID_QUANTITY`
4. 用户 ID 包含非法字符 → `CODE_ERR_INVALID_QUANTITY`
5. SKU 不在活跃集合中 → `CODE_ERR_INVALID_QUANTITY`（"该商品当前不可售"）

构造函数中还通过 `$redis->ping()` 验证连接可用性。

---

## 5. 关键数据流与处理逻辑

### 5.1 购买下单完整流程

```
用户请求 purchase(sku, userId, qty, amount, orderId, limitPerUser)
    │
    ├── [PHP 层] 参数快速失败校验
    │   ├── orderId 非空? 
    │   ├── limitPerUser >= 0?
    │   ├── sku 合法? (isValidId)
    │   ├── userId 合法? (isValidId)
    │   └── SKU 活跃? (isSkuActive)
    │
    └── [Redis Lua] recordPurchaseWithStock 原子执行
        │
        ├── 0. CANCEL_KEY 存在? → CODE_ERR_ORDER_CANCELED (并发取消拦截)
        ├── 1. ORDER_KEY 存在? → CODE_ERR_ALREADY_PROCESSED (幂等拦截)
        ├── 2. STOCK_KEY 存在? → 否: CODE_ERR_NOT_EXISTS
        ├── 3. stock >= quantity? → 否: CODE_ERR_INSUFFICIENT
        ├── 4. 限购校验: bought + quantity > limit? → CODE_ERR_LIMIT_EXCEEDED
        ├── 5. DECRBY stock_key quantity (库存扣减)
        │   └── remain == 0 → 写入 SOLD_OUT_KEY
        ├── 6. HINCRBY user_bought_key (用户购买记录)
        ├── 7. SADD user_set_key sku (用户 SKU 集合)
        ├── 8. INCRBY sales_count/amount (销量/销售额统计)
        ├── 9. ZINCRBY leaderboard_count/amount (排行榜)
        ├── 10. SETEX order_key (幂等标记)
        └── 返回 [CODE_SUCCESS, total_sales]
```

### 5.2 取消订单完整流程

```
用户请求 cancel(sku, qty, amount, orderId)
    │
    ├── [PHP 层] sku 合法性校验
    │
    └── [Redis Lua] cancelOrderWithStock 原子执行
        │
        ├── 1. CANCEL_KEY 存在? → CODE_ERR_ORDER_CANCELED (幂等，不重复回滚)
        ├── 2. ORDER_KEY 不存在? → CODE_ERR_ORDER_NOT_PROCESSED
        ├── 3. STOCK_KEY 不存在? → CODE_ERR_NOT_EXISTS
        ├── 4. INCRBY stock_key qty (回滚库存)
        │   └── remain > 0 → DEL soldout_key (清除售罄标记)
        ├── 5. DECRBY sales_count/amount qty/amount (回退统计数据)
        ├── 6. ZINCRBY leaderboard_count/amount -qty/-amount (回退排行榜)
        ├── 7. DEL order_key (移除订单幂等标记)
        ├── 8. SETEX cancel_key (写入取消标记，防并发重试)
        └── 返回 [CODE_SUCCESS, remain]
```

### 5.3 监控与修复流程

```
monitor(sku)
    │
    └── Pipeline 批量查询:
        ├── GET stock_key → stock
        ├── TTL stock_key → ttl
        └── EXISTS soldout_key → is_sold_out
        │
        └── 一致性判定:
            - stock == false && is_sold_out → 不一致（孤立标记）
            - stock > 0 && is_sold_out → 不一致（无效售罄）
            - stock <= 0 && !is_sold_out → 不一致（缺失售罄）
            - 其他 → 一致

repair(sku) → Lua 原子修复:
    ├── 场景1: 有库存但有售罄标记 → DEL soldout_key
    ├── 场景2: 无库存但无售罄标记 → SET soldout_key
    ├── 场景4: 主Key消失但有售罄 → DEL soldout_key (清理孤立)
    └── 场景3/0: 一致 → 无操作
```

---

## 6. 依赖关系图

```
外部依赖:
├── php: >=7.2.0
├── ext-redis: *            (PHP Redis 扩展，必需)
├── psr/log: ^1.0|^2.0|^3.0 (PSR-3 日志接口)
│
开发依赖:
├── phpunit/phpunit: ^9.0|^12.0

类依赖关系:
    RedisConstants (final class, 纯常量)
         ▲
         │ 引用常量
         │
    StockSalesCodes (interface)
         ▲
         │ implements
    ┌────┴─────┬─────────────────┐
    │          │                 │
RedisStock  RedisSales   RedisStockSalesManager
    │          │                 │
    └────┬─────┘                 │
         │ extends               │ 组合持有
    AbstractRedisManager         │
         │                      │
         │ uses                 │ uses
    IdSanitizer (trait)   IdSanitizer (trait)

执行流程:
    RedisStockSalesManager
        │
        ├──> RedisStock::decrStock() / incrStock() / getStock() ...
        └──> RedisSales::recordPurchaseWithStock() / cancelOrderWithStock() ...

    所有 Redis 操作最终通过 AbstractRedisManager::execLua() 执行
```

---

## 7. Redis Key 设计规范

以 `keyPrefix = '{product:stock}:'` 为例：

| 用途 | Key 格式 | Redis 类型 | TTL |
|------|----------|------------|-----|
| 库存数量 | `{product:stock}:SKU123` | String | 可选 |
| 售罄标记 | `{product:stock}:SKU123:soldout` | String (1) | 继承库存 TTL |
| 用户购买 Hash | `{product:stock}:SKU123:user_bought` | Hash | 30天 |
| 用户购买集合 | `{product:stock}:user:USER001:purchased` | Set | 30天 |
| 销量统计 | `{product:stock}:SKU123:sales_count` | String | 无 |
| 销售额统计 | `{product:stock}:SKU123:sales_amount` | String | 无 |
| 销量排行榜 | `{product:stock}:leaderboard:count` | ZSet | 无 |
| 销售额排行榜 | `{product:stock}:leaderboard:amount` | ZSet | 无 |
| 订单幂等标记 | `{product:stock}:order:ORDER001` | String (1) | 24小时 |
| 订单取消标记 | `{product:stock}:order:ORDER001:canceled` | String (1) | 24小时 |
| 活跃 SKU 集合 | `{product:stock}:active:skus` | Set | 无 |

### Hash Tag 原理

`{product:stock}` 花括号内容作为 Hash Tag，Redis Cluster 只根据 `product:stock` 计算 CRC16 分配 Slot。所有包含相同 Hash Tag 的 Key 保证落在同一节点，从而支持多 Key Lua 操作。

---

## 8. 错误码体系

错误码分为正数和负数两类：

- **正数 (1)**：`CODE_SUCCESS` — 操作成功
- **负数 (-1 ~ -4)**：库存相关错误
- **负数 (-5 ~ -9)**：销售/订单相关错误

| 错误码 | 含义 | 触发场景 |
|--------|------|----------|
| `1` | 成功 | 操作正常完成 |
| `-1` | 库存不足 | `decrStock` / `purchase` 时库存不够 |
| `-2` | 库存未初始化 | SKU 的库存 Key 不存在于 Redis |
| `-3` | 数量/参数非法 | qty≤0 / ID 含非法字符 / 空 orderId |
| `-4` | Redis 不可用 | 网络超时 / 集群故障 / 重试耗尽 |
| `-5` | 超过限购 | 用户累计购买超过 `limitPerUser` |
| `-6` | 订单已处理 | 幂等拦截：相同 orderId 重复提交 |
| `-7` | 金额非法 | amount < 0 |
| `-8` | 订单已取消 | cancel 后 purchase 并发重试被拦截 |
| `-9` | 订单未处理 | cancel 时找不到对应订单幂等标记 |

---

## 9. 重试与容错机制

### 触发条件

仅对**瞬态故障**（通过 `isTransientError()` 判定）进行重试，非瞬态故障直接抛出异常。

瞬态故障包括：
- READONLY（主从切换）
- Connection refused / timed out
- Redis is loading（启动中）
- CLUSTERDOWN / MASTERDOWN
- MOVED / ASK（集群重定向）
- OOM command not allowed

### 重试策略

采用**指数退避**（Exponential Backoff）：

| 重试次数 | 延迟 | 计算公式 |
|----------|------|----------|
| 第 1 次 | 10ms | `2^0 * 10000 μs` |
| 第 2 次 | 20ms | `2^1 * 10000 μs` |
| 第 3 次 (capped) | 200ms | `min(2^2 * 10000, 200000) μs` |

最大重试次数默认 **2 次**，可通过构造函数注入自定义值。

### 三类重试包装器

| 方法 | 适用场景 | 异常处理 |
|------|----------|----------|
| `execLuaWithRetry` | Lua 脚本写操作 | 抛出 `RuntimeException` |
| `readWithRetry` | 只读命令（get/mget/exists） | 抛出 `RuntimeException` |
| `writeWithRetry` | 普通写命令（del） | 抛出 `RuntimeException` |
| `pipelineWithRetry` | Pipeline 批量操作 | 抛出 `RuntimeException` |

### ReadOnly 特殊处理

当检测到 `READONLY` 错误时，会自动调用 `$redis->reset()` 重置连接状态，尝试重新建立主节点连接。

---

## 10. 集群兼容策略

### Hash Tag 强制要求

构造函数中标配前缀 `{product:stock}:`，且在 `verifyKeyPrefix()` 中验证前缀是否包含 `{}`。

```php
// 正确
$manager = new RedisStockSalesManager($redis, '{shop}:');

// 警告：缺少 Hash Tag
$manager = new RedisStockSalesManager($redis, 'shop:');
```

### 跨 Key 操作 Key 构造规则

所有多 Key Lua 脚本涉及的 Key 都严格注入同一个 `keyPrefix`：

```php
// 以 {shop}: 为例，所有 Key 都以此为前缀
$stockKey       = '{shop}:SKU123';
$soldOutKey     = '{shop}:SKU123:soldout';
$orderKey       = '{shop}:order:ORDER001';     // orderId 也注入前缀
$userSetKey     = '{shop}:user:USER001:purchased';
$leaderboardKey = '{shop}:leaderboard:count';
```

这样所有 Key 都共享 `{shop}` 的 Slot 计算结果，确保落在同一 Redis Cluster 节点。

---

## 11. 项目运行与测试

### 安装

```bash
composer require nermif/php-redis-stock
```

### 环境要求

| 依赖 | 版本 |
|------|------|
| PHP | >= 7.2.0 |
| PHP Redis 扩展 | `ext-redis` 任意版本 |
| Redis 服务 | 任意版本 |
| PSR-3 日志（可选） | `psr/log` ^1.0\|^2.0\|^3.0 |

### 运行示例

确保 Redis 已启动：

```bash
php examples/facade_usage.php
```

### 运行测试

测试套件包含 **120+ 用例**，覆盖库存、销售、门面层及边界场景。需要本地 Redis 运行在 6379 端口。

```bash
# 安装依赖
composer install

# 运行全部测试
./vendor/bin/phpunit

# 运行指定测试文件
./vendor/bin/phpunit tests/RedisStockTest.php
./vendor/bin/phpunit tests/RedisSalesTest.php
./vendor/bin/phpunit tests/RedisStockSalesManagerTest.php
```

### CI/CD（GitHub Actions）

[.github/workflows/php.yml](file:///workspace/.github/workflows/php.yml) 定义了 CI 流程：

- **触发**：push / PR 到 `main` 分支
- **环境**：Ubuntu latest + PHP 7.4 + ext-redis
- **服务**：Redis Docker 容器（端口 6379）
- **步骤**：Checkout → Setup PHP → Validate composer.json → Cache → Install → Run PHPUnit

---

## 12. 框架集成指南

### Laravel

```php
// AppServiceProvider.php
$this->app->singleton(RedisStockSalesManager::class, function ($app) {
    $redis = $app['redis']->connection()->client();
    return new RedisStockSalesManager($redis, '{shop}:', $app['log']);
});

// 控制器中依赖注入
public function buy(RedisStockSalesManager $manager, Request $request) {
    return $manager->purchase(
        $request->input('sku'),
        $request->user()->id,
        $request->input('quantity'),
        $request->input('amount'),
        $request->input('order_id')
    );
}
```

### ThinkPHP

```php
$redis = \think\facade\Cache::store('redis')->handler();
$manager = new \Nermif\RedisStockSalesManager($redis, '{mall}:');
```

---

## 13. 安全与最佳实践

### Key 前缀规范

务必使用 `{}` 包裹前缀核心部分（如 `{stock}:`），否则多 Key 操作在 Redis Cluster 下会报 `CROSSSLOT`。

### Redis 配置建议

- `maxmemory-policy` 建议设为 `volatile-lru`，避免意外驱逐导致售罄标记丢失
- 生产环境建议启用持久化（RDB + AOF）

### 日志注入

建议注入 PSR-3 日志器（如 Monolog），当 `repair()` 触发修复时可通过日志追踪底层异常。

### 批量操作上限

`decrMultiStocks` 虽然支持原子扣减，但单次 SKU 数建议 **不超过 20 个**，因为所有 Key 必须在同一 Cluster Slot，且整个 Lua 脚本执行期间 Redis 是单线程阻塞的。

### 限购过期理解

用户购买记录的 TTL 默认为 **30 天**，超时后限购计数自动清零。请根据业务需求调整。

### 金额单位

所有金额参数使用**整数分**为单位（如 `199900` 表示 1999.00 元），避免浮点数精度问题。

### 取消约束

`cancel()` 的 `$quantity` 和 `$amount` 必须与下单时使用的值完全一致，否则会导致库存和销售数据产生不可逆的偏差。建议调用方从订单系统获取原始下单参数后调用。

### 活跃 SKU 管理

通过 `syncActiveSkus()` / `removeActiveSku()` 管理商品上下架状态。未在活跃集合中的 SKU 调用 `purchase()` 会被直接拒绝（"该商品当前不可售"），但 `decrStock()` 不受此限制。