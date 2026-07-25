<?php

declare(strict_types=1);

namespace Architect\Queue;

use Architect\Queue\Contracts\DispatcherInterface;
use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Диспетчер задач.
 * Отвечает за постановку задач в очередь.
 */
class Dispatcher implements DispatcherInterface
{
    protected QueueManagerInterface $queueManager;
    protected ContainerInterface $container;

    public function __construct(QueueManagerInterface $queueManager, ContainerInterface $container)
    {
        $this->queueManager = $queueManager;
        $this->container = $container;
    }

    public function dispatch(JobInterface|string $job, string $queue = 'default'): string
    {
        $jobInstance = $this->resolveJob($job);
        $jobInstance->setQueue($queue);

        $driver = $this->queueManager->driver();
        return $driver->push($jobInstance, $queue);
    }

    public function dispatchAfter(JobInterface|string $job, int $delay, string $queue = 'default'): string
    {
        $jobInstance = $this->resolveJob($job);
        $jobInstance->setQueue($queue);
        $jobInstance->setDelay($delay);

        $driver = $this->queueManager->driver();
        return $driver->push($jobInstance, $queue, $delay);
    }

    public function dispatchNow(JobInterface|string $job): mixed
    {
        $jobInstance = $this->resolveJob($job);
        $jobInstance->handle();
        return null;
    }

    public function dispatchAt(JobInterface|string $job, \DateTimeInterface $datetime, string $queue = 'default'): string
    {
        $delay = $datetime->getTimestamp() - time();
        if ($delay < 0) {
            $delay = 0;
        }
        return $this->dispatchAfter($job, $delay, $queue);
    }

    public function dispatchBatch(array $jobs, string $queue = 'default'): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            $ids[] = $this->dispatch($job, $queue);
        }
        return $ids;
    }

    /**
     * Преобразует переданный аргумент в экземпляр JobInterface.
     *
     * @param JobInterface|string $job
     * @return JobInterface
     * @throws InvalidArgumentException
     */
    protected function resolveJob(JobInterface|string $job): JobInterface
    {
        if ($job instanceof JobInterface) {
            return $job;
        }

        if (is_string($job) && class_exists($job)) {
            // Пытаемся создать через контейнер
            if ($this->container->has($job)) {
                $instance = $this->container->get($job);
            } else {
                $instance = new $job();
            }

            if (!$instance instanceof JobInterface) {
                throw new InvalidArgumentException("Class {$job} must implement JobInterface.");
            }

            return $instance;
        }

        throw new InvalidArgumentException('Job must be an instance of JobInterface or a class name string.');
    }
}
