<?php

declare(strict_types=1);

namespace Architect\Queue\Console\Commands;

use Architect\Console\BaseCommand;
use Architect\Console\CommandInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Architect\Queue\Contracts\WorkerInterface;
use Psr\Container\ContainerInterface;

/**
 * Запускает воркер для обработки задач из очереди.
 */
class QueueWorkCommand extends BaseCommand implements CommandInterface
{
    protected string $name = 'queue:work';
    protected string $description = 'Start processing jobs from the queue';

    protected QueueManagerInterface $queueManager;
    protected WorkerInterface $worker;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->queueManager = $container->get('queue.manager');
        // Пока Worker не реализован, создадим заглушку
        $this->worker = new class implements WorkerInterface {
            public function run(string $queue = 'default', array $options = []): void
            {
                echo "Worker is not implemented yet.\n";
            }
            public function stop(): void {}
            public function pause(int $seconds): void {}
            public function resume(): void {}
            public function status(): array
            {
                return [];
            }
            public function processNextJob(string $queue = 'default'): bool
            {
                return false;
            }
        };
    }

    public function getOptions(): array
    {
        return [
            ['--queue', 'The queue to process', 'default'],
            ['--connection', 'The connection to use', null],
            ['--sleep', 'Seconds to sleep when no job is available', 3],
            ['--timeout', 'Maximum seconds a job can run', 60],
            ['--max-jobs', 'Maximum number of jobs to process before stopping', 100],
            ['--memory', 'Memory limit in MB', 128],
            ['--stop-on-empty', 'Stop when queue is empty', false],
        ];
    }

    public function execute(array $arguments, array $options): int
    {
        $queue = $options['queue'] ?? 'default';
        $connection = $options['connection'] ?? null;
        $sleep = (int) ($options['sleep'] ?? 3);
        $timeout = (int) ($options['timeout'] ?? 60);
        $maxJobs = (int) ($options['max-jobs'] ?? 100);
        $memoryLimit = (int) ($options['memory'] ?? 128);
        $stopOnEmpty = (bool) ($options['stop-on-empty'] ?? false);

        $this->info("Starting queue worker for queue '{$queue}'");

        if ($connection) {
            $this->info("Using connection '{$connection}'");
        }

        $driver = $this->queueManager->driver($connection);
        $this->info('Driver: ' . get_class($driver));

        $processed = 0;
        $startTime = time();

        while (true) {
            // Проверяем ограничения
            if ($processed >= $maxJobs) {
                $this->info("Maximum jobs ({$maxJobs}) processed. Stopping.");
                break;
            }

            if ($this->exceededMemoryLimit($memoryLimit)) {
                $this->warning('Memory limit exceeded. Stopping.');
                break;
            }

            if ($this->exceededTimeLimit($startTime, $timeout)) {
                $this->warning('Time limit exceeded. Stopping.');
                break;
            }

            $job = $driver->pop($queue);
            if ($job === null) {
                if ($stopOnEmpty) {
                    $this->info('Queue is empty. Stopping.');
                    break;
                }
                $this->debug("No jobs available. Sleeping for {$sleep} seconds.");
                sleep($sleep);
                continue;
            }

            $this->info('Processing job: ' . $job->getId());
            try {
                $job->handle();
                $driver->acknowledge($job, $queue);
                $this->success('Job processed successfully.');
            } catch (\Throwable $e) {
                $this->error('Job failed: ' . $e->getMessage());
                $driver->fail($job, $queue);
            }

            $processed++;
        }

        $this->info("Worker stopped. Processed {$processed} jobs.");
        return 0;
    }

    protected function exceededMemoryLimit(int $limitMb): bool
    {
        $used = memory_get_usage(true) / 1024 / 1024;
        return $used > $limitMb;
    }

    protected function exceededTimeLimit(int $startTime, int $timeout): bool
    {
        return (time() - $startTime) > $timeout;
    }

    protected function debug(string $message): void
    {
        if ($this->getOutput()->isVerbose()) {
            $this->line($message);
        }
    }
}
