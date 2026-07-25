<?php

declare(strict_types=1);

namespace Architect\Queue;

use Architect\Queue\Contracts\QueueDriverInterface;
use Architect\Queue\Contracts\QueueManagerInterface;
use Architect\Queue\Support\DriverFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Менеджер очередей.
 * Управляет подключениями к драйверам и предоставляет доступ к ним.
 */
class QueueManager implements QueueManagerInterface
{
    protected ContainerInterface $container;
    protected DriverFactory $driverFactory;
    protected array $connections = [];
    protected array $drivers = [];
    protected string $defaultConnection;

    public function __construct(ContainerInterface $container, DriverFactory $driverFactory, array $config = [])
    {
        $this->container = $container;
        $this->driverFactory = $driverFactory;
        $this->defaultConnection = $config['default'] ?? 'sync';

        // Регистрируем подключения из конфигурации
        foreach ($config['connections'] ?? [] as $name => $connectionConfig) {
            $this->addConnection($name, $connectionConfig);
        }
    }

    public function driver(?string $connection = null): QueueDriverInterface
    {
        $connection = $connection ?? $this->defaultConnection;

        if (!isset($this->connections[$connection])) {
            throw new InvalidArgumentException("Queue connection [{$connection}] is not defined.");
        }

        // Ленивая загрузка драйвера
        if (!isset($this->drivers[$connection])) {
            $config = $this->connections[$connection];
            $this->drivers[$connection] = $this->driverFactory->make($config);
        }

        return $this->drivers[$connection];
    }

    public function addConnection(string $name, array $config): void
    {
        $this->connections[$name] = $config;
        // Удаляем закешированный драйвер, если он был
        unset($this->drivers[$name]);
    }

    public function getConnectionConfig(string $name): array
    {
        if (!isset($this->connections[$name])) {
            throw new InvalidArgumentException("Queue connection [{$name}] is not defined.");
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $name): void
    {
        if (!isset($this->connections[$name])) {
            throw new InvalidArgumentException("Queue connection [{$name}] is not defined.");
        }

        $this->defaultConnection = $name;
    }

    public function getConnections(): array
    {
        return array_keys($this->connections);
    }

    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Возвращает все зарегистрированные драйверы (кешированные).
     *
     * @return array<string, QueueDriverInterface>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Сбрасывает кеш драйверов.
     *
     * @return void
     */
    public function resetDrivers(): void
    {
        $this->drivers = [];
    }
}