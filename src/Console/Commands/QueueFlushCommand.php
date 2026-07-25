<?php

declare(strict_types=1);

namespace Architect\Queue\Console\Commands;

use Architect\Console\BaseCommand;
use Architect\Console\CommandInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Очищает очередь.
 */
class QueueFlushCommand extends BaseCommand implements CommandInterface
{
    protected string $name = 'queue:flush';
    protected string $description = 'Flush all jobs from a queue';

    protected QueueManagerInterface $queueManager;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->queueManager = $container->get('queue.manager');
    }

    public function getOptions(): array
    {
        return [
            ['--connection', 'The connection to use', null],
            ['--queue', 'The queue to flush', 'default'],
            ['--force', 'Force flush without confirmation', false],
        ];
    }

    public function execute(array $arguments, array $options): int
    {
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? 'default';
        $force = (bool) ($options['force'] ?? false);

        $driver = $this->queueManager->driver($connection);
        $count = $driver->count($queue);

        if ($count === 0) {
            $this->info("Queue '{$queue}' is already empty.");
            return 0;
        }

        if (!$force) {
            $this->warning("This will delete {$count} jobs from queue '{$queue}'.");
            $confirm = $this->ask("Are you sure? (yes/no)", 'no');
            if (strtolower($confirm) !== 'yes') {
                $this->info("Aborted.");
                return 0;
            }
        }

        $driver->clear($queue);
        $this->success("Queue '{$queue}' flushed successfully. Removed {$count} jobs.");

        return 0;
    }
}