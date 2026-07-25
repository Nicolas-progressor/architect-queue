<?php

declare(strict_types=1);

namespace Architect\Queue\Events;

use Architect\Queue\Contracts\JobInterface;

/**
 * Событие, возникающее при повторной попытке выполнения задачи.
 */
class JobRetrying
{
    public function __construct(
        public readonly JobInterface $job,
        public readonly string $queue,
        public readonly string $connection,
        public readonly int $delay,
        public readonly int $attempt
    ) {}
}