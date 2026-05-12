<?php

namespace Nermif;

/**
 * 业务操作码统一接口
 * 所有底层模块和门面类均实现此接口，使常量定义只依赖 RedisConstants。
 */
interface StockSalesCodes
{
    // 成功
    public const CODE_SUCCESS = RedisConstants::CODE_SUCCESS;

    // 库存相关错误
    public const CODE_ERR_INSUFFICIENT = RedisConstants::CODE_ERR_INSUFFICIENT;
    public const CODE_ERR_NOT_EXISTS = RedisConstants::CODE_ERR_NOT_EXISTS;
    public const CODE_ERR_INVALID_QUANTITY = RedisConstants::CODE_ERR_INVALID_QUANTITY;
    public const CODE_ERR_REDIS_UNAVAILABLE = RedisConstants::CODE_ERR_REDIS_UNAVAILABLE;

    // 销售相关错误
    public const CODE_ERR_LIMIT_EXCEEDED = RedisConstants::CODE_ERR_LIMIT_EXCEEDED;
    public const CODE_ERR_ALREADY_PROCESSED = RedisConstants::CODE_ERR_ALREADY_PROCESSED;
    public const CODE_ERR_INVALID_AMOUNT = RedisConstants::CODE_ERR_INVALID_AMOUNT;
    public const CODE_ERR_ORDER_CANCELED = RedisConstants::CODE_ERR_ORDER_CANCELED;
    public const CODE_ERR_ORDER_NOT_PROCESSED = RedisConstants::CODE_ERR_ORDER_NOT_PROCESSED;
}