<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use Axiom\Orm\Orm;
use RuntimeException;

/**
 * Драйвер очереди на основе базы данных.
 * Хранит задачи в таблице `queue_jobs`.
 */
class DatabaseDriver implements QueueDriverInterface
{
    protected string $table = 'queue_jobs';
    protected string $connectionName = 'default';
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->table = $config['table'] ?? 'queue_jobs';
        $this->connectionName = $config['connection'] ?? 'default';
    }

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $availableAt = $delay > 0 ? time() + $delay : time();

        $id = uniqid('', true);

        Orm::table($this->table, Orm::connection($this->connectionName))->insert([
            'id' => $id,
            'queue' => $queue,
            'payload' => json_encode($job->toArray()),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => time(),
        ]);

        return $id;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        // Используем атомарный SELECT ... FOR UPDATE (или аналоги) чтобы избежать конкуренции
        // Упрощённо: выбираем первую доступную задачу
        $connection = Orm::connection($this->connectionName);
        $now = time();

        $job = Orm::table($this->table, $connection)
            ->where('queue', $queue)
            ->where('reserved_at', null)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at', 'asc')
            ->limit(1)
            ->first();

        if (!$job) {
            return null;
        }

        // Помечаем как забронированную
        Orm::table($this->table, $connection)
            ->where('id', $job['id'])
            ->update(['reserved_at' => $now]);

        // Восстанавливаем задачу из payload
        $payload = json_decode($job['payload'], true);
        if (!$payload) {
            throw new RuntimeException('Invalid job payload');
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
        $this->deleteJob($job->getId());
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // Увеличиваем attempts и обновляем available_at
        $connection = Orm::connection($this->connectionName);
        $attempts = $job->getAttempts() + 1;
        $availableAt = $delay > 0 ? time() + $delay : time();

        Orm::table($this->table, $connection)
            ->where('id', $job->getId())
            ->update([
                'attempts' => $attempts,
                'reserved_at' => null,
                'available_at' => $availableAt,
            ]);
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        // Перемещаем в таблицу failed_jobs (пока просто удаляем)
        $this->deleteJob($job->getId());
        // Вызываем метод failed у задачи
        $job->failed(new RuntimeException('Job failed in database driver'));
    }

    public function count(string $queue = 'default'): int
    {
        $connection = Orm::connection($this->connectionName);
        return (int) Orm::table($this->table, $connection)
            ->where('queue', $queue)
            ->where('reserved_at', null)
            ->where('available_at', '<=', time())
            ->count();
    }

    public function clear(string $queue = 'default'): void
    {
        $connection = Orm::connection($this->connectionName);
        Orm::table($this->table, $connection)
            ->where('queue', $queue)
            ->delete();
    }

    public function listQueues(): array
    {
        $connection = Orm::connection($this->connectionName);
        $queues = Orm::table($this->table, $connection)
            ->distinct()
            ->pluck('queue');
        return $queues ?: [];
    }

    /**
     * Удаляет задачу по ID.
     */
    protected function deleteJob(string $id): void
    {
        $connection = Orm::connection($this->connectionName);
        Orm::table($this->table, $connection)
            ->where('id', $id)
            ->delete();
    }
}
