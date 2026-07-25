<?php

declare(strict_types=1);

namespace Architect\Queue;

use Architect\Queue\Contracts\FailedJobRepositoryInterface;
use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Architect\Queue\Contracts\WorkerInterface;
use Architect\Queue\Events\EventDispatcherInterface;
use Architect\Queue\Events\JobProcessing;
use Architect\Queue\Events\JobProcessed;
use Architect\Queue\Events\JobFailed;
use Architect\Queue\Events\JobRetrying;
use Architect\Queue\Middleware\MiddlewarePipeline;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Воркер для обработки задач из очереди.
 */
class Worker implements WorkerInterface
{
    protected QueueManagerInterface $queueManager;
    protected ContainerInterface $container;
    protected ?LoggerInterface $logger = null;
    protected ?FailedJobRepositoryInterface $failedJobRepository = null;
    protected ?EventDispatcherInterface $eventDispatcher = null;
    protected ?MiddlewarePipeline $middlewarePipeline = null;
    protected bool $shouldStop = false;
    protected bool $paused = false;
    protected int $processedJobs = 0;
    protected array $options = [];

    public function __construct(
        QueueManagerInterface $queueManager,
        ContainerInterface $container,
        ?LoggerInterface $logger = null,
        ?FailedJobRepositoryInterface $failedJobRepository = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?MiddlewarePipeline $middlewarePipeline = null
    ) {
        $this->queueManager = $queueManager;
        $this->container = $container;
        $this->logger = $logger;
        $this->failedJobRepository = $failedJobRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->middlewarePipeline = $middlewarePipeline;
    }

    public function run(string $queue = 'default', array $options = []): void
    {
        $this->options = array_merge([
            'sleep' => 3,
            'timeout' => 60,
            'max_jobs' => 100,
            'memory_limit' => 128,
            'stop_on_empty' => false,
            'connection' => null,
        ], $options);

        $this->log('info', "Worker started for queue '{$queue}'");

        $startTime = time();
        $driver = $this->queueManager->driver($this->options['connection']);

        while (!$this->shouldStop) {
            if ($this->paused) {
                sleep(1);
                continue;
            }

            // Проверка ограничений
            if ($this->exceededMaxJobs()) {
                $this->log('info', 'Maximum jobs processed, stopping.');
                break;
            }

            if ($this->exceededMemoryLimit()) {
                $this->log('warning', 'Memory limit exceeded, stopping.');
                break;
            }

            if ($this->exceededTimeLimit($startTime)) {
                $this->log('warning', 'Time limit exceeded, stopping.');
                break;
            }

            // Обработка следующей задачи
            $job = $driver->pop($queue);
            if ($job === null) {
                if ($this->options['stop_on_empty']) {
                    $this->log('info', 'Queue empty, stopping.');
                    break;
                }
                $this->log('debug', 'No jobs available, sleeping.');
                sleep($this->options['sleep']);
                continue;
            }

            $this->processJob($job, $driver, $queue);
        }

        $this->log('info', "Worker stopped. Processed {$this->processedJobs} jobs.");
    }

    public function stop(): void
    {
        $this->shouldStop = true;
        $this->log('info', 'Worker stop signal received.');
    }

    public function pause(int $seconds): void
    {
        $this->paused = true;
        $this->log('info', "Worker paused for {$seconds} seconds.");
        sleep($seconds);
        $this->resume();
    }

    public function resume(): void
    {
        $this->paused = false;
        $this->log('info', 'Worker resumed.');
    }

    public function status(): array
    {
        return [
            'running' => !$this->shouldStop,
            'paused' => $this->paused,
            'processed_jobs' => $this->processedJobs,
            'options' => $this->options,
        ];
    }

    public function processNextJob(string $queue = 'default'): bool
    {
        $driver = $this->queueManager->driver($this->options['connection'] ?? null);
        $job = $driver->pop($queue);
        if ($job === null) {
            return false;
        }

        $this->processJob($job, $driver, $queue);
        return true;
    }

    /**
     * Устанавливает конвейер middleware.
     */
    public function setMiddlewarePipeline(MiddlewarePipeline $pipeline): void
    {
        $this->middlewarePipeline = $pipeline;
    }

    /**
     * Обрабатывает одну задачу.
     */
    protected function processJob(JobInterface $job, QueueDriverInterface $driver, string $queue): void
    {
        $jobId = $job->getId();
        $this->log('info', "Processing job {$jobId}");

        $connection = $this->options['connection'] ?? 'default';

        // Событие перед выполнением
        $this->dispatchEvent(new JobProcessing($job, $queue, $connection));

        try {
            // Увеличиваем счётчик попыток
            $job->incrementAttempts();

            // Выполняем задачу через middleware, если они есть
            if ($this->middlewarePipeline !== null) {
                $this->middlewarePipeline->process($job, function (JobInterface $job) {
                    $job->handle();
                });
            } else {
                $job->handle();
            }

            // Подтверждаем успешное выполнение
            $driver->acknowledge($job, $queue);
            $this->log('info', "Job {$jobId} processed successfully.");
            $this->processedJobs++;

            // Событие после успешного выполнения
            $this->dispatchEvent(new JobProcessed($job, $queue, $connection));
        } catch (\Throwable $e) {
            $this->log('error', "Job {$jobId} failed: " . $e->getMessage());

            // Если превышено максимальное количество попыток, помечаем как неудачную
            if ($job->getAttempts() >= $job->getMaxAttempts()) {
                $driver->fail($job, $queue);
                $this->log('warning', "Job {$jobId} moved to failed queue after {$job->getAttempts()} attempts.");
                // Сохраняем в репозиторий неудачных задач, если он доступен
                $this->storeFailedJob($job, $queue, $e);
                // Событие неудачи
                $this->dispatchEvent(new JobFailed($job, $queue, $connection, $e));
            } else {
                // Иначе повторяем с задержкой
                $delay = $this->calculateRetryDelay($job->getAttempts());
                $driver->retry($job, $queue, $delay);
                $this->log('info', "Job {$jobId} scheduled for retry in {$delay} seconds.");
                // Событие повторной попытки
                $this->dispatchEvent(new JobRetrying($job, $queue, $connection, $delay, $job->getAttempts()));
            }
        }
    }

    /**
     * Сохраняет неудачную задачу в репозиторий.
     */
    protected function storeFailedJob(JobInterface $job, string $queue, \Throwable $exception): void
    {
        if ($this->failedJobRepository === null) {
            return;
        }

        $connection = $this->options['connection'] ?? 'default';
        try {
            $this->failedJobRepository->store($connection, $queue, $job, $exception);
            $this->log('info', "Job {$job->getId()} stored in failed jobs repository.");
        } catch (\Throwable $e) {
            $this->log('error', "Failed to store failed job: " . $e->getMessage());
        }
    }

    /**
     * Вычисляет задержку перед повторной попыткой (экспоненциальная).
     */
    protected function calculateRetryDelay(int $attempts): int
    {
        return (int) min(60 * 60, pow(2, $attempts) * 5);
    }

    /**
     * Проверяет, превышено ли максимальное количество задач.
     */
    protected function exceededMaxJobs(): bool
    {
        return $this->options['max_jobs'] > 0 && $this->processedJobs >= $this->options['max_jobs'];
    }

    /**
     * Проверяет, превышен ли лимит памяти.
     */
    protected function exceededMemoryLimit(): bool
    {
        $limit = $this->options['memory_limit'] * 1024 * 1024; // MB to bytes
        return memory_get_usage(true) > $limit;
    }

    /**
     * Проверяет, превышен ли лимит времени.
     */
    protected function exceededTimeLimit(int $startTime): bool
    {
        $timeout = $this->options['timeout'];
        return $timeout > 0 && (time() - $startTime) > $timeout;
    }

    /**
     * Логирует сообщение.
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->$level($message);
        }
        // Можно также выводить в stdout для отладки
        if ($level === 'error' || $level === 'warning') {
            error_log("[$level] $message");
        }
    }

    /**
     * Диспетчеризует событие, если диспетчер доступен.
     */
    protected function dispatchEvent(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}