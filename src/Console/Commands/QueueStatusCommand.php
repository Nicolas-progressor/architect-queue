<?php

declare(strict_types=1);

namespace Architect\Queue\Console\Commands;

use Architect\Console\BaseCommand;
use Architect\Console\CommandInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Показывает статус очередей.
 */
class QueueStatusCommand extends BaseCommand implements CommandInterface
{
    protected string $name = 'queue:status';
    protected string $description = 'Show queue status and statistics';

    protected QueueManagerInterface $queueManager;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->queueManager = $container->get('queue.manager');
    }

    public function getOptions(): array
    {
        return [
            ['--connection', 'The connection to check', null],
            ['--queue', 'Specific queue to check', 'default'],
        ];
    }

    public function execute(array $arguments, array $options): int
    {
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? 'default';

        $driver = $this->queueManager->driver($connection);

        $this->info("Queue Status");
        $this->line("Connection: " . ($connection ?? 'default'));
        $this->line("Queue: {$queue}");
        $this->line("Driver: " . get_class($driver));

        $count = $driver->count($queue);
        $this->line("Pending jobs: {$count}");

        $queues = $driver->listQueues();
        $this->line("Available queues: " . implode(', ', $queues));

        return 0;
    }
}