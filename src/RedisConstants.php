<?php

namespace Nermif;

/**
 * Redis 相关常量统一管理（包括业务错误码、Key后缀、重试策略、过期时间、Lua占位符）
 */
final class RedisConstants
{
    // -------------------------------------------------------------------------
    // 通用业务错误码
    // -------------------------------------------------------------------------
    /** 操作成功 */
    public const CODE_SUCCESS = 1;
    /** Redis 不可用（网络、超时、只读等） */
    public const CODE_ERR_REDIS_UNAVAILABLE = -4;

    // -------------------------------------------------------------------------
    // 库存管理专用错误码
    // -------------------------------------------------------------------------
    /** 库存不足 */
    public const CODE_ERR_INSUFFICIENT = -1;
    /** 库存未初始化 */
    public const CODE_ERR_NOT_EXISTS = -2;
    /** 数量非法（≤0） */
    public const CODE_ERR_INVALID_QUANTITY = -3;

    // -------------------------------------------------------------------------
    // 销售管理专用错误码
    // -------------------------------------------------------------------------
    /** 超过限购数量 */
    public const CODE_ERR_LIMIT_EXCEEDED = -5;
    /** 订单已处理（幂等拦截） */
    public const CODE_ERR_ALREADY_PROCESSED = -6;
    /** 金额非法 */
    public const CODE_ERR_INVALID_AMOUNT = -7;

    // -------------------------------------------------------------------------
    // Key 后缀（用于拼接完整 Key）
    // -------------------------------------------------------------------------
    /** 售罄标记后缀 */
    public const SOLD_OUT_SUFFIX = ':soldout';
    /** 用户购买记录 Hash 后缀 */
    public const USER_BOUGHT_HASH_SUFFIX = ':user_bought';
    /** 商品销量统计 String 后缀 */
    public const SALES_COUNT_SUFFIX = ':sales_count';
    /** 商品销售额统计 String 后缀 */
    public const SALES_AMOUNT_SUFFIX = ':sales_amount';
    /** 销量排行榜 ZSet 后缀 */
    public const LEADERBOARD_COUNT_SUFFIX = ':leaderboard:count';
    /** 销售额排行榜 ZSet 后缀 */
    public const LEADERBOARD_AMOUNT_SUFFIX = ':leaderboard:amount';
    /** 用户购买集合 Key 前缀（独立于业务前缀，如 user:{userId}:purchased） */
    public const USER_PURCHASED_SET_PREFIX = 'user:';
    /** 订单幂等标记 Key 前缀 */
    public const ORDER_IDEMPOTENT_PREFIX = 'order:';

    // -------------------------------------------------------------------------
    // 重试策略配置
    // -------------------------------------------------------------------------
    /** 默认最大重试次数 */
    public const DEFAULT_MAX_RETRIES = 2;
    /** 重试基础延迟微秒数（10ms） */
    public const RETRY_BASE_DELAY_MICROSECONDS = 10000;

    // -------------------------------------------------------------------------
    // 默认过期时间（秒）
    // -------------------------------------------------------------------------
    /** 用户购买记录/集合默认过期时间（30天） */
    public const DEFAULT_USER_RECORD_TTL = 2592000;
    /** 订单幂等标记过期时间（24小时） */
    public const DEFAULT_ORDER_TTL = 86400;

    // -------------------------------------------------------------------------
    // Lua 脚本占位符
    // -------------------------------------------------------------------------
    /** 售罄后缀占位符（在 Lua 脚本中被替换为实际后缀） */
    public const LUA_PLACEHOLDER_SOLD_OUT_SUFFIX = '{{SOLD_OUT_SUFFIX}}';
    /** 用户购买记录/集合过期时间占位符（在 Lua 脚本中被替换为实际秒数） */
    public const LUA_USER_RECORD_TTL = '{{USER_RECORD_TTL}}';
    /** 订单幂等标记过期时间占位符（在 Lua 脚本中被替换为实际值） */
    public const LUA_ORDER_TTL = '{{ORDER_TTL}}';
}