<?php

declare(strict_types=1);

namespace Architect\Queue\Events;

use Architect\Queue\Contracts\JobInterface;

/**
 * Событие, возникающее при неудачном выполнении задачи.
 */
class JobFailed
{
    public function __construct(
        public readonly JobInterface $job,
        public readonly string $queue,
        public readonly string $connection,
        public readonly \Throwable $exception
    ) {}
}