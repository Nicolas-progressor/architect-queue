<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Драйвер очереди на основе Redis.
 * Использует Redis списки для хранения задач.
 */
class RedisDriver implements QueueDriverInterface
{
    protected Redis $redis;
    protected string $prefix = 'queue:';
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'queue:';
        $this->connect();
    }

    /**
     * Устанавливает соединение с Redis.
     *
     * @throws RuntimeException Если не удалось подключиться
     */
    protected function connect(): void
    {
        $this->redis = new Redis();

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 6379;
        $password = $this->config['password'] ?? null;
        $database = $this->config['database'] ?? 0;
        $timeout = $this->config['timeout'] ?? 0.0;

        try {
            $connected = $this->redis->connect($host, $port, $timeout);
            if (!$connected) {
                throw new RuntimeException("Could not connect to Redis at {$host}:{$port}");
            }

            if ($password !== null) {
                $this->redis->auth($password);
            }

            if ($database !== 0) {
                $this->redis->select($database);
            }
        } catch (RedisException $e) {
            throw new RuntimeException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $queueKey = $this->prefix . $queue;

        if ($delay > 0) {
            // Для отложенных задач используем sorted set с временем выполнения
            $score = time() + $delay;
            $this->redis->zAdd($queueKey . ':delayed', $score, serialize($job->toArray()));
        } else {
            // Немедленная задача идёт в список
            $this->redis->rPush($queueKey, serialize($job->toArray()));
        }

        return $job->getId();
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $queueKey = $this->prefix . $queue;

        // Сначала проверяем отложенные задачи
        $now = time();
        $delayedKey = $queueKey . ':delayed';
        $delayedJobs = $this->redis->zRangeByScore($delayedKey, '-inf', $now, ['limit' => [0, 1]]);

        if (!empty($delayedJobs)) {
            $jobData = $delayedJobs[0];
            // Перемещаем в активную очередь
            $this->redis->zRem($delayedKey, $jobData);
            $this->redis->rPush($queueKey, $jobData);
        }

        // Извлекаем задачу из списка
        $jobData = $this->redis->lPop($queueKey);
        if (!$jobData) {
            return null;
        }

        $payload = unserialize($jobData);
        if (!$payload) {
            throw new RuntimeException('Invalid job payload from Redis');
        }

        $jobClass = $payload['class'] ?? null;
        if (!$jobClass || !class_exists($jobClass)) {
            throw new RuntimeException("Job class {$jobClass} not found");
        }

        /** @var JobInterface $jobInstance */
        $jobInstance = new $jobClass();
        $jobInstance->restoreFromPayload($payload);
        $jobInstance->setQueue($queue);

        return $jobInstance;
    }

    public function acknowledge(JobInterface $job, string $queue = 'default'): void
    {
        // В Redis нет отдельного подтверждения, задача уже удалена из списка при pop
        // Можно вести журнал обработанных задач, но пока ничего не делаем
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // Помещаем задачу обратно в очередь с задержкой
        $this->push($job, $queue, $delay);
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        // Перемещаем в failed set
        $failedKey = $this->prefix . $queue . ':failed';
        $this->redis->sAdd($failedKey, serialize($job->toArray()));
        // Вызываем метод failed у задачи
        $job->failed(new RuntimeException('Job failed in Redis driver'));
    }

    public function count(string $queue = 'default'): int
    {
        $queueKey = $this->prefix . $queue;
        return (int) $this->redis->lLen($queueKey);
    }

    public function clear(string $queue = 'default'): void
    {
        $queueKey = $this->prefix . $queue;
        $this->redis->del($queueKey);
        $this->redis->del($queueKey . ':delayed');
        $this->redis->del($queueKey . ':failed');
    }

    public function listQueues(): array
    {
        // Redis не хранит метаданные об очередях, поэтому возвращаем только default
        // Можно было бы сканировать ключи по префиксу, но для простоты вернём массив
        return ['default'];
    }

    /**
     * Возвращает экземпляр Redis для низкоуровневых операций.
     *
     * @return Redis
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }
}
