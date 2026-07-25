<?php

declare(strict_types=1);

namespace Architect\Queue\Middleware;

use Architect\Queue\Contracts\JobInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware для логирования обработки задач.
 */
class LoggingMiddleware implements JobMiddlewareInterface
{
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(JobInterface $job, callable $next): void
    {
        $jobClass = get_class($job);
        $queue = $job->getQueue();

        $this->logger->info('Job processing started', [
            'job' => $jobClass,
            'queue' => $queue,
            'id' => $job->getJobId(),
        ]);

        try {
            $next($job);
            $this->logger->info('Job processing completed', [
                'job' => $jobClass,
                'queue' => $queue,
                'id' => $job->getJobId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Job processing failed', [
                'job' => $jobClass,
                'queue' => $queue,
                'id' => $job->getJobId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}