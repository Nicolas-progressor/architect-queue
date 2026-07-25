<?php

declare(strict_types=1);

namespace Architect\Queue\Middleware;

use Architect\Queue\Contracts\JobInterface;
use Closure;

/**
 * Конвейер middleware для задач.
 */
class MiddlewarePipeline
{
    /**
     * @var JobMiddlewareInterface[]
     */
    protected array $middlewares = [];

    /**
     * Добавляет middleware в конвейер.
     *
     * @param JobMiddlewareInterface $middleware
     * @return self
     */
    public function push(JobMiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Добавляет несколько middleware.
     *
     * @param JobMiddlewareInterface[] $middlewares
     * @return self
     */
    public function pushMany(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->push($middleware);
        }
        return $this;
    }

    /**
     * Запускает конвейер для задачи.
     *
     * @param JobInterface $job
     * @param Closure $finalHandler Финальный обработчик (обычно вызов $job->handle())
     * @return void
     */
    public function process(JobInterface $job, Closure $finalHandler): void
    {
        $pipeline = $this->createPipeline($finalHandler);
        $pipeline($job);
    }

    /**
     * Создаёт замыкание, представляющее цепочку middleware.
     *
     * @param Closure $finalHandler
     * @return Closure
     */
    protected function createPipeline(Closure $finalHandler): Closure
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function (Closure $next, JobMiddlewareInterface $middleware) {
                return function (JobInterface $job) use ($middleware, $next) {
                    return $middleware->handle($job, $next);
                };
            },
            $finalHandler
        );

        return $pipeline;
    }
}
