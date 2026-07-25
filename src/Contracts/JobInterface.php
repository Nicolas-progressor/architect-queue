<?php

declare(strict_types=1);

namespace Architect\Queue\Contracts;

/**
 * Интерфейс задачи, которая может быть поставлена в очередь.
 */
interface JobInterface
{
    /**
     * Выполняет задачу.
     *
     * @return void
     */
    public function handle(): void;

    /**
     * Обрабатывает неудачное выполнение задачи.
     *
     * @param \Throwable $exception Исключение, вызвавшее сбой
     * @return void
     */
    public function failed(\Throwable $exception): void;

    /**
     * Возвращает максимальное количество попыток выполнения задачи.
     *
     * @return int
     */
    public function getMaxAttempts(): int;

    /**
     * Возвращает текущее количество попыток.
     *
     * @return int
     */
    public function getAttempts(): int;

    /**
     * Увеличивает счётчик попыток.
     *
     * @return void
     */
    public function incrementAttempts(): void;

    /**
     * Возвращает имя очереди, в которую была отправлена задача.
     *
     * @return string
     */
    public function getQueue(): string;

    /**
     * Устанавливает имя очереди.
     *
     * @param string $queue
     * @return void
     */
    public function setQueue(string $queue): void;

    /**
     * Возвращает задержку выполнения в секундах.
     *
     * @return int
     */
    public function getDelay(): int;

    /**
     * Устанавливает задержку выполнения.
     *
     * @param int $delay
     * @return void
     */
    public function setDelay(int $delay): void;

    /**
     * Возвращает уникальный идентификатор задачи.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Возвращает данные задачи (сериализуемые).
     *
     * @return array
     */
    public function getPayload(): array;

    /**
     * Восстанавливает задачу из данных.
     *
     * @param array $payload
     * @return void
     */
    public function restoreFromPayload(array $payload): void;

    /**
     * Возвращает метаданные задачи (внутренние данные драйвера).
     *
     * @return array
     */
    public function getMeta(): array;

    /**
     * Устанавливает метаданные задачи.
     *
     * @param array $meta
     * @return void
     */
    public function setMeta(array $meta): void;

    /**
     * Получает значение метаданных по ключу.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMetaValue(string $key, mixed $default = null): mixed;

    /**
     * Устанавливает значение метаданных по ключу.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setMetaValue(string $key, mixed $value): void;
}