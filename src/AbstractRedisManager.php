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
        return $script;
    }

    private function loadScripts(): void
    {
        foreach ($this->getLuaScripts() as $name => $script) {
            try {
                $realScript = $this->prepareScript($name, $script);
                $this->scriptShas[$name] = $this->redis->script('load', $realScript);
            } catch (\RedisException $e) {
                $this->scriptShas[$name] = null;
                $this->log(LogLevel::WARNING, "Lua script {$name} load failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function isTransientError(\RedisException $e): bool
    {
        $msg = $e->getMessage();
        if (strpos($msg, 'READONLY') !== false) {
            try {
                $this->redis->reset();
            } catch (\Throwable $ignored) {
            }
            return true;
        }
        return strpos($msg, 'Connection refused') !== false
            || strpos($msg, 'Connection timed out') !== false
            || strpos($msg, 'read error on connection') !== false
            || strpos($msg, 'Redis is loading') !== false;
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
     * @return void
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
}