<?php

declare(strict_types=1);

namespace Architect\Queue\Contracts;

/**
 * Интерфейс диспетчера задач.
 * Отвечает за постановку задач в очередь.
 */
interface DispatcherInterface
{
    /**
     * Отправляет задачу в очередь.
     *
     * @param JobInterface|string $job Экземпляр задачи или имя класса
     * @param string $queue Имя очереди (опционально)
     * @return string Идентификатор задачи
     */
    public function dispatch(JobInterface|string $job, string $queue = 'default'): string;

    /**
     * Отправляет задачу с задержкой.
     *
     * @param JobInterface|string $job Экземпляр задачи или имя класса
     * @param int $delay Задержка в секундах
     * @param string $queue Имя очереди (опционально)
     * @return string Идентификатор задачи
     */
    public function dispatchAfter(JobInterface|string $job, int $delay, string $queue = 'default'): string;

    /**
     * Отправляет задачу немедленно (синхронно).
     *
     * @param JobInterface|string $job Экземпляр задачи или имя класса
     * @return mixed Результат выполнения задачи
     */
    public function dispatchNow(JobInterface|string $job): mixed;

    /**
     * Отправляет задачу в указанное время.
     *
     * @param JobInterface|string $job Экземпляр задачи или имя класса
     * @param \DateTimeInterface $datetime Время выполнения
     * @param string $queue Имя очереди (опционально)
     * @return string Идентификатор задачи
     */
    public function dispatchAt(JobInterface|string $job, \DateTimeInterface $datetime, string $queue = 'default'): string;

    /**
     * Отправляет массив задач в очередь.
     *
     * @param array $jobs Массив задач
     * @param string $queue Имя очереди (опционально)
     * @return array<string> Идентификаторы задач
     */
    public function dispatchBatch(array $jobs, string $queue = 'default'): array;
}