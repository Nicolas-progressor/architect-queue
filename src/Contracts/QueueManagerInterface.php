<?php

declare(strict_types=1);

namespace Architect\Queue\Contracts;

/**
 * Интерфейс менеджера очередей.
 * Управляет подключениями к драйверам и предоставляет доступ к ним.
 */
interface QueueManagerInterface
{
    /**
     * Возвращает драйвер для указанного подключения.
     *
     * @param string|null $connection Имя подключения (null для использования default)
     * @return QueueDriverInterface
     * @throws \InvalidArgumentException Если подключение не найдено
     */
    public function driver(?string $connection = null): QueueDriverInterface;

    /**
     * Добавляет новое подключение.
     *
     * @param string $name Имя подключения
     * @param array $config Конфигурация драйвера
     * @return void
     */
    public function addConnection(string $name, array $config): void;

    /**
     * Возвращает конфигурацию подключения.
     *
     * @param string $name Имя подключения
     * @return array
     */
    public function getConnectionConfig(string $name): array;

    /**
     * Возвращает имя подключения по умолчанию.
     *
     * @return string
     */
    public function getDefaultConnection(): string;

    /**
     * Устанавливает подключение по умолчанию.
     *
     * @param string $name Имя подключения
     * @return void
     */
    public function setDefaultConnection(string $name): void;

    /**
     * Возвращает список всех зарегистрированных подключений.
     *
     * @return array<string>
     */
    public function getConnections(): array;

    /**
     * Проверяет, существует ли подключение.
     *
     * @param string $name Имя подключения
     * @return bool
     */
    public function hasConnection(string $name): bool;
}
