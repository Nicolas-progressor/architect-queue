<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use InvalidArgumentException;

/**
 * Синхронный драйвер очереди.
 * Выполняет задачи немедленно, без помещения в очередь.
 * Полезен для тестирования и разработки.
 */
class SyncDriver implements QueueDriverInterface
{
    protected array $jobs = [];

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        if ($delay > 0) {
            // В синхронном режиме задержка игнорируется (можно эмулировать через sleep, но не будем)
            // Просто выполняем сразу
        }

        // Немедленно выполняем задачу
        try {
            $job->handle();
            $this->acknowledge($job, $queue);
        } catch (\Throwable $e) {
            $this->fail($job, $queue);
            throw $e;
        }

        return $job->getId();
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        // В синхронном драйвере нет очереди, поэтому всегда возвращаем null
        return null;
    }

    public function acknowledge(JobInterface $job, string $queue = 'default'): void
    {
        // Ничего не делаем, задача уже выполнена
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // Повторно выполняем задачу
        $this->push($job, $queue, $delay);
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        // Вызываем метод failed у задачи
        $job->failed(new \RuntimeException('Job failed in sync driver'));
    }

    public function count(string $queue = 'default'): int
    {
        return 0;
    }

    public function clear(string $queue = 'default'): void
    {
        // Ничего не очищаем
    }

    public function listQueues(): array
    {
        return ['default'];
    }
}