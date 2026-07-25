<?php

declare(strict_types=1);

namespace Architect\Queue\Contracts;

use Architect\Queue\Contracts\JobInterface;

/**
 * Интерфейс драйвера очереди.
 * Определяет методы для взаимодействия с бэкендом очереди.
 */
interface QueueDriverInterface
{
    /**
     * Помещает задачу в очередь.
     *
     * @param JobInterface $job Задача
     * @param string $queue Название очереди (опционально)
     * @param int $delay Задержка в секундах (опционально)
     * @return string Идентификатор задачи (если поддерживается)
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string;

    /**
     * Извлекает следующую задачу из очереди.
     *
     * @param string $queue Название очереди
     * @return JobInterface|null Задача или null, если очередь пуста
     */
    public function pop(string $queue = 'default'): ?JobInterface;

    /**
     * Подтверждает успешную обработку задачи.
     *
     * @param JobInterface $job Задача
     * @param string $queue Название очереди
     */
    public function acknowledge(JobInterface $job, string $queue = 'default'): void;

    /**
     * Возвращает задачу в очередь для повторной обработки.
     *
     * @param JobInterface $job Задача
     * @param string $queue Название очереди
     * @param int $delay Задержка в секундах
     */
    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void;

    /**
     * Помечает задачу как неудачную (перемещает в failed queue).
     *
     * @param JobInterface $job Задача
     * @param string $queue Название очереди
     */
    public function fail(JobInterface $job, string $queue = 'default'): void;

    /**
     * Возвращает количество задач в очереди.
     *
     * @param string $queue Название очереди
     * @return int Количество задач
     */
    public function count(string $queue = 'default'): int;

    /**
     * Очищает очередь.
     *
     * @param string $queue Название очереди
     */
    public function clear(string $queue = 'default'): void;

    /**
     * Возвращает список доступных очередей (если поддерживается).
     *
     * @return array<string>
     */
    public function listQueues(): array;
}