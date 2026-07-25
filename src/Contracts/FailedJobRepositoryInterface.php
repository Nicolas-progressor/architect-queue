<?php

namespace Architect\Queue\Contracts;

use Architect\Queue\Jobs\JobInterface;

interface FailedJobRepositoryInterface
{
    /**
     * Сохранить неудачную задачу.
     *
     * @param string $connection Имя подключения
     * @param string $queue Имя очереди
     * @param JobInterface $job Задача
     * @param \Throwable $exception Исключение
     * @return void
     */
    public function store(string $connection, string $queue, JobInterface $job, \Throwable $exception): void;

    /**
     * Получить список неудачных задач.
     *
     * @param int $limit
     * @return array
     */
    public function all(int $limit = 50): array;

    /**
     * Найти неудачную задачу по ID.
     *
     * @param int $id
     * @return array|null
     */
    public function find(int $id): ?array;

    /**
     * Удалить неудачную задачу.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Повторить неудачную задачу.
     *
     * @param int $id
     * @return bool
     */
    public function retry(int $id): bool;

    /**
     * Очистить все неудачные задачи.
     *
     * @return int Количество удалённых
     */
    public function flush(): int;
}
