<?php

declare(strict_types=1);

namespace Architect\Queue\Support;

use Architect\Queue\Contracts\QueueDriverInterface;
use Architect\Queue\Drivers\DatabaseDriver;
use Architect\Queue\Drivers\RedisDriver;
use Architect\Queue\Drivers\SyncDriver;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Фабрика для создания драйверов очередей.
 */
class DriverFactory
{
    protected ContainerInterface $container;

    /**
     * Карта встроенных драйверов.
     */
    protected array $builtInDrivers = [
        'sync' => SyncDriver::class,
        'database' => DatabaseDriver::class,
        'redis' => RedisDriver::class,
        'beanstalkd' => \Architect\Queue\Drivers\BeanstalkdDriver::class,
        'sqs' => \Architect\Queue\Drivers\SqsDriver::class,
        'rabbitmq' => \Architect\Queue\Drivers\RabbitMQDriver::class,
        // Другие драйверы будут добавлены позже
    ];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Создаёт экземпляр драйвера на основе конфигурации.
     *
     * @param array $config Конфигурация драйвера
     * @return QueueDriverInterface
     * @throws InvalidArgumentException Если драйвер не поддерживается
     */
    public function make(array $config): QueueDriverInterface
    {
        $driver = $config['driver'] ?? null;

        if (!$driver) {
            throw new InvalidArgumentException('Driver configuration must specify a driver.');
        }

        // Если указан кастомный класс
        if (isset($config['class']) && class_exists($config['class'])) {
            $driverClass = $config['class'];
        } elseif (isset($this->builtInDrivers[$driver])) {
            $driverClass = $this->builtInDrivers[$driver];
        } else {
            throw new InvalidArgumentException("Unsupported queue driver: {$driver}");
        }

        // Проверяем, реализует ли класс нужный интерфейс
        if (!is_subclass_of($driverClass, QueueDriverInterface::class)) {
            throw new InvalidArgumentException("Driver class {$driverClass} must implement QueueDriverInterface.");
        }

        // Создаём экземпляр через контейнер, если возможно, иначе напрямую
        if ($this->container->has($driverClass)) {
            $instance = $this->container->get($driverClass);
        } else {
            $instance = new $driverClass($config);
        }

        if (!$instance instanceof QueueDriverInterface) {
            throw new InvalidArgumentException("Driver class {$driverClass} must implement QueueDriverInterface.");
        }

        return $instance;
    }

    /**
     * Регистрирует кастомный драйвер.
     *
     * @param string $name Имя драйвера
     * @param string $className Полное имя класса
     * @return void
     */
    public function extend(string $name, string $className): void
    {
        $this->builtInDrivers[$name] = $className;
    }

    /**
     * Возвращает список зарегистрированных драйверов.
     *
     * @return array<string, string>
     */
    public function getRegisteredDrivers(): array
    {
        return $this->builtInDrivers;
    }
}
