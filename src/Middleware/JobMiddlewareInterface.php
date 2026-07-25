<?php

declare(strict_types=1);

namespace Architect\Queue\Middleware;

use Architect\Queue\Contracts\JobInterface;
use Closure;

/**
 * Интерфейс middleware для задач.
 */
interface JobMiddlewareInterface
{
    /**
     * Обрабатывает задачу.
     *
     * @param JobInterface $job Задача
     * @param Closure $next Следующий middleware или обработчик
     * @return void
     */
    public function handle(JobInterface $job, Closure $next): void;
}