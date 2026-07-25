<?php

declare(strict_types=1);

namespace Architect\Queue\Debug;

use Architect\Queue\Contracts\QueueManagerInterface;
use Architect\Services\Debug\Contracts\DebugCollectorInterface;

/**
 * Коллектор данных очередей для дебаг-панели.
 */
class QueueDataCollector implements DebugCollectorInterface
{
    protected QueueManagerInterface $queueManager;
    protected array $stats = [];

    public function __construct(QueueManagerInterface $queueManager)
    {
        $this->queueManager = $queueManager;
    }

    public function addMessage(string $category, string $message, string $level = 'info', array $context = []): void
    {
        // Не используется
    }

    public function startTimer(string $name, string $category = 'performance'): void
    {
        // Не используется
    }

    public function stopTimer(string $name): ?float
    {
        return null;
    }

    public function addData(string $category, $data, string $description = ''): void
    {
        // Не используется
    }

    public function incrementCounter(string $category, string $counterName, int $value = 1): void
    {
        // Не используется
    }

    public function markEvent(string $eventName, array $metadata = []): void
    {
        // Не используется
    }

    public function setMetadata(string $key, $value): void
    {
        // Не используется
    }

    public function clear(): void
    {
        $this->stats = [];
    }

    public function getData(): array
    {
        $connections = $this->queueManager->getConnections();
        $stats = [];

        foreach ($connections as $connection) {
            $driver = $this->queueManager->driver($connection);
            $queues = $driver->listQueues();
            $queueStats = [];

            foreach ($queues as $queue) {
                $count = $driver->count($queue);
                $queueStats[$queue] = [
                    'pending' => $count,
                ];
            }

            $stats[$connection] = [
                'driver' => get_class($driver),
                'queues' => $queueStats,
            ];
        }

        return [
            'has_data' => !empty($stats),
            'connections' => $stats,
            'default_connection' => $this->queueManager->getDefaultConnection(),
        ];
    }

    public function resetNewDataFlag(): void
    {
        // Не используется
    }

    public function getCategoryCount(): int
    {
        return 1;
    }

    public function getTotalMessages(): int
    {
        return 0;
    }
}