<?php

namespace Nermif;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

abstract class AbstractRedisManager
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * Key 前缀
     * @var string
     */
    protected $keyPrefix;

    /**
     * 日志器
     * @var LoggerInterface|NullLogger
     */
    protected $logger;

    /**
     * Lua 脚本 SHA1 值缓存
     * @var array
     */
    protected $scriptShas = [];

    /**
     * 错误最大重试次数
     * @var int
     */
    protected $maxRetries;

    public function __construct(
        \Redis           $redis,
        string           $keyPrefix = '{product:stock}:',
        ?LoggerInterface $logger = null,
        ?int             $maxRetries = null
    )
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->logger = $logger ?: new NullLogger();
        $this->maxRetries = $maxRetries ?? RedisConstants::DEFAULT_MAX_RETRIES;
        $this->loadScripts();
        $this->verifyKeyPrefix();
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger->{$level}($message, $context);
    }

    /**
     * 子类必须返回一个关联数组：脚本名 => Lua 源码
     */
    abstract protected function getLuaScripts(): array;

    /**
     * 预处理脚本（如替换占位符），子类可按需覆盖
     */
    protected function prepareScript(string $scriptName, string $script): string
    {
        $placeholders = $this->getPlaceholders();
        if (!empty($placeholders)) {
            $script = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $script
            );
        }
        return $script;
    }

    /**
     * 返回需要替换的占位符映射
     * 子类可覆盖此方法以声明所有占位符
     * @return array
     */
    protected function getPlaceholders(): array
    {
        return [];
    }

    private function loadScripts(): void
    {
        $failedScripts = [];
        foreach ($this->getLuaScripts() as $name => $script) {
            try {
                $realScript = $this->prepareScript($name, $script);
                $this->scriptShas[$name] = $this->redis->script('load', $realScript);
            } catch (\RedisException $e) {
                $this->scriptShas[$name] = null;
                $failedScripts[] = $name;
                $this->log(LogLevel::ERROR, "Lua script {$name} load failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        if (!empty($failedScripts)) {
            throw new \RuntimeException(
                'Failed to load Lua scripts: ' . implode(', ', $failedScripts) . '. Redis connection may be unavailable.',
                RedisConstants::CODE_ERR_REDIS_UNAVAILABLE
            );
        }
    }

    protected function isTransientError(\RedisException $e): bool
    {
        $msg = $e->getMessage();
        if (strpos($msg, 'READONLY') !== false) {
            try {
                $this->redis->reset();
            } catch (\Throwable $e) {
                $this->log(LogLevel::DEBUG, 'Redis reset failed during READONLY handling', [
                    'error' => $e->getMessage(),
                ]);
            }
            return true;
        }
        
        $transientPatterns = [
            'Connection refused',
            'Connection timed out',
            'read error on connection',
            'Redis is loading',
            'OOM command not allowed',
            'CLUSTERDOWN',
            'TRYAGAIN',
            'MASTERDOWN',
            'MOVED',
            'ASK',
        ];
        
        foreach ($transientPatterns as $pattern) {
            if (strpos($msg, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    protected function execLua(string $scriptName, array $keys, array $args)
    {
        $numKeys = count($keys);
        $allArgs = array_merge($keys, $args);
        $sha = $this->scriptShas[$scriptName] ?? null;

        if ($sha) {
            try {
                return $this->redis->evalSha($sha, $allArgs, $numKeys);
            } catch (\RedisException $e) {
                if (strpos($e->getMessage(), 'NOSCRIPT') === false) {
                    throw $e;
                }
            }
        }

        $scripts = $this->getLuaScripts();
        if (!isset($scripts[$scriptName])) {
            throw new \InvalidArgumentException("Unknown script: {$scriptName}");
        }
        $originalScript = $this->prepareScript($scriptName, $scripts[$scriptName]);
        return $this->redis->eval($originalScript, $allArgs, $numKeys);
    }

    /**
     * 执行 Lua 脚本并支持重试
     * @param string $scriptName 脚本名
     * @param array $keys 键
     * @param array $args 参数
     * @param int|null $maxRetries 最大重试次数
     * @return mixed
     */
    protected function execLuaWithRetry(string $scriptName, array $keys, array $args, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $this->execLua($scriptName, $keys, $args);
            } catch (\RedisException $e) {
                $lastException = $e;
                if (!$this->isTransientError($e)) {
                    break;
                }
                $attempt++;
                if ($attempt <= $maxRetries) {
                    $sleepMicro = (int)pow(2, $attempt - 1) * RedisConstants::RETRY_BASE_DELAY_MICROSECONDS;
                    if ($sleepMicro > RedisConstants::RETRY_MAX_DELAY_MICROSECONDS) {
                        $sleepMicro = RedisConstants::RETRY_MAX_DELAY_MICROSECONDS;
                    }
                    usleep($sleepMicro);
                    $this->log(LogLevel::WARNING, "Transient error for {$scriptName}, retrying", [
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMicro / 1000,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->log(LogLevel::ERROR, "{$scriptName} failed after retries", [
            'error' => $lastException->getMessage() ?? 'Unknown error',
        ]);
        throw new \RuntimeException('服务繁忙，请稍后重试', RedisConstants::CODE_ERR_REDIS_UNAVAILABLE, $lastException);
    }

    /**
     * 执行带重试的读操作
     * 适用于所有可能抛出 RedisException 的读命令（get、mget、exists 等）
     * 
     * @param callable $operation 读操作闭包，接收 \Redis 实例作为参数
     * @param int|null $maxRetries 最大重试次数
     * @return mixed
     * @throws \RuntimeException 当重试耗尽后仍失败时抛出
     */
    protected function readWithRetry(callable $operation, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $operation($this->redis);
            } catch (\RedisException $e) {
                $lastException = $e;
                if (!$this->isTransientError($e)) {
                    break;
                }
                $attempt++;
                if ($attempt <= $maxRetries) {
                    $sleepMicro = (int)pow(2, $attempt - 1) * RedisConstants::RETRY_BASE_DELAY_MICROSECONDS;
                    if ($sleepMicro > RedisConstants::RETRY_MAX_DELAY_MICROSECONDS) {
                        $sleepMicro = RedisConstants::RETRY_MAX_DELAY_MICROSECONDS;
                    }
                    usleep($sleepMicro);
                    $this->log(LogLevel::WARNING, 'Read operation transient error, retrying', [
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMicro / 1000,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->log(LogLevel::ERROR, 'Read operation failed after retries', [
            'error' => $lastException ? $lastException->getMessage() : 'Unknown error',
        ]);
        throw new \RuntimeException('服务繁忙，请稍后重试', RedisConstants::CODE_ERR_REDIS_UNAVAILABLE, $lastException);
    }

    /**
     * 执行带重试的写操作
     * 适用于所有可能抛出 RedisException 的写命令（del、set、zRem 等）
     * 
     * @param callable $operation 写操作闭包，接收 \Redis 实例作为参数
     * @param int|null $maxRetries 最大重试次数
     * @return mixed
     * @throws \RuntimeException 当重试耗尽后仍失败时抛出
     */
    protected function writeWithRetry(callable $operation, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $operation($this->redis);
            } catch (\RedisException $e) {
                $lastException = $e;
                if (!$this->isTransientError($e)) {
                    break;
                }
                $attempt++;
                if ($attempt <= $maxRetries) {
                    $sleepMicro = (int)pow(2, $attempt - 1) * RedisConstants::RETRY_BASE_DELAY_MICROSECONDS;
                    if ($sleepMicro > RedisConstants::RETRY_MAX_DELAY_MICROSECONDS) {
                        $sleepMicro = RedisConstants::RETRY_MAX_DELAY_MICROSECONDS;
                    }
                    usleep($sleepMicro);
                    $this->log(LogLevel::WARNING, 'Write operation transient error, retrying', [
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMicro / 1000,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->log(LogLevel::ERROR, 'Write operation failed after retries', [
            'error' => $lastException ? $lastException->getMessage() : 'Unknown error',
        ]);
        throw new \RuntimeException('服务繁忙，请稍后重试', RedisConstants::CODE_ERR_REDIS_UNAVAILABLE, $lastException);
    }

    /**
     * 执行带重试的 Pipeline 操作
     * 适用于需要原子性执行的多个 Redis 命令
     * 
     * @param callable $operation Pipeline 操作闭包，接收 \Redis 实例作为参数，需返回 $pipe->exec() 结果
     * @param int|null $maxRetries 最大重试次数
     * @return mixed
     * @throws \RuntimeException 当重试耗尽后仍失败时抛出
     */
    protected function pipelineWithRetry(callable $operation, ?int $maxRetries = null)
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                return $operation($this->redis);
            } catch (\RedisException $e) {
                $lastException = $e;
                if (!$this->isTransientError($e)) {
                    break;
                }
                $attempt++;
                if ($attempt <= $maxRetries) {
                    $sleepMicro = (int)pow(2, $attempt - 1) * RedisConstants::RETRY_BASE_DELAY_MICROSECONDS;
                    if ($sleepMicro > RedisConstants::RETRY_MAX_DELAY_MICROSECONDS) {
                        $sleepMicro = RedisConstants::RETRY_MAX_DELAY_MICROSECONDS;
                    }
                    usleep($sleepMicro);
                    $this->log(LogLevel::WARNING, 'Pipeline operation transient error, retrying', [
                        'attempt' => $attempt,
                        'sleep_ms' => $sleepMicro / 1000,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->log(LogLevel::ERROR, 'Pipeline operation failed after retries', [
            'error' => $lastException ? $lastException->getMessage() : 'Unknown error',
        ]);
        throw new \RuntimeException('服务繁忙，请稍后重试', RedisConstants::CODE_ERR_REDIS_UNAVAILABLE, $lastException);
    }

    /**
     * 验证 Key 前缀的集群兼容性
     * @return void
     */
    protected function verifyKeyPrefix(): void
    {
        if (strpos($this->keyPrefix, '{') === false || strpos($this->keyPrefix, '}') === false) {
            $this->log(LogLevel::WARNING, 'keyPrefix does not contain Hash Tag {} (e.g., "{product:stock}:"), may cause CROSSSLOT error in Redis Cluster mode', [
                'keyPrefix' => $this->keyPrefix
            ]);
        }
    }

    /**
     * 将金额字符串转换为分（整数）
     * 优先使用 bcmath 扩展，若不可用则回退到原生计算
     * 
     * @param string $amountStr 金额字符串（如 "12.34"）
     * @return int 金额（分）
     */
    protected function amountToCents(string $amountStr): int
    {
        if (function_exists('bcmul')) {
            return (int)bcmul($amountStr, '100', 0);
        }
        return (int)round((float)$amountStr * 100);
    }
}