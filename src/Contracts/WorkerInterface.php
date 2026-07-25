<?php

declare(strict_types=1);

namespace Architect\Queue\Contracts;

/**
 * Интерфейс воркера, обрабатывающего задачи из очереди.
 */
interface WorkerInterface
{
    /**
     * Запускает воркер для обработки задач из указанной очереди.
     *
     * @param string $queue Имя очереди
     * @param array $options Опции (timeout, sleep, maxJobs и т.д.)
     * @return void
     */
    public function run(string $queue = 'default', array $options = []): void;

    /**
     * Останавливает воркер.
     *
     * @return void
     */
    public function stop(): void;

    /**
     * Приостанавливает воркер на указанное количество секунд.
     *
     * @param int $seconds
     * @return void
     */
    public function pause(int $seconds): void;

    /**
     * Возобновляет работу воркера после паузы.
     *
     * @return void
     */
    public function resume(): void;

    /**
     * Возвращает статус воркера.
     *
     * @return array Статус (running, paused, stopped, processed jobs, etc.)
     */
    public function status(): array;

    /**
     * Обрабатывает одну задачу из очереди.
     *
     * @param string $queue Имя очереди
     * @return bool true если задача была обработана, false если очередь пуста
     */
    public function processNextJob(string $queue = 'default'): bool;
}