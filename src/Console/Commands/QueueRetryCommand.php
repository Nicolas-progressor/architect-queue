<?php

declare(strict_types=1);

namespace Architect\Queue\Console\Commands;

use Architect\Console\BaseCommand;
use Architect\Console\CommandInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * Повторно отправляет неудачные задачи.
 */
class QueueRetryCommand extends BaseCommand implements CommandInterface
{
    protected string $name = 'queue:retry';
    protected string $description = 'Retry failed jobs';

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
            ['--queue', 'The queue to retry from', 'default'],
            ['--id', 'Specific job ID to retry', null],
            ['--all', 'Retry all failed jobs', false],
        ];
    }

    public function execute(array $arguments, array $options): int
    {
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? 'default';
        $id = $options['id'] ?? null;
        $all = (bool) ($options['all'] ?? false);

        $driver = $this->queueManager->driver($connection);

        // В реальной реализации нужно получить список неудачных задач
        // Пока что просто выводим сообщение
        if ($id) {
            $this->info("Retrying job {$id} from queue '{$queue}'...");
            $this->warning('Not implemented yet.');
        } elseif ($all) {
            $this->info("Retrying all failed jobs from queue '{$queue}'...");
            $this->warning('Not implemented yet.');
        } else {
            $this->error('Please specify --id or --all option.');
            return 1;
        }

        return 0;
    }
}
