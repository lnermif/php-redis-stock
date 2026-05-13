# 使用示例

本目录包含 Redis 库存与销售管理库的各种使用示例。

## 📁 文件说明

| 文件 | 说明 | 适用场景 |
|------|------|---------|
| `facade_usage.php` | ⭐ 门面类快速上手（推荐） | 日常业务调用 |
| `seckill_demo.php` | 完整秒杀流程（底层类协作） | 学习内部机制 |
| `stock_usage.php` | 库存高级用法（批量、监控、修复） | 需要底层功能时 |
| `framework_usage.php` | Laravel / ThinkPHP 集成代码片段 | 框架集成 |

## 🚀 运行

```bash
# 门面类快速上手
php examples/facade_usage.php

# 秒杀演示
php examples/seckill_demo.php

# 高级用法
php examples/stock_usage.php

# 框架集成
php examples/framework_usage.php
```