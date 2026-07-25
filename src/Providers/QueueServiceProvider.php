<?php

declare(strict_types=1);

namespace Architect\Queue\Providers;

use Architect\Contracts\ServiceProviderInterface;
use Architect\Core\Contracts\ContainerInterface;
use Architect\Queue\Contracts\FailedJobRepositoryInterface;
use Architect\Queue\Dispatcher;
use Architect\Queue\Events\EventDispatcherInterface;
use Architect\Queue\Events\SimpleEventDispatcher;
use Architect\Queue\Middleware\DatabaseTransactionMiddleware;
use Architect\Queue\Middleware\LoggingMiddleware;
use Architect\Queue\Middleware\MiddlewarePipeline;
use Architect\Queue\QueueManager;
use Architect\Queue\Repositories\DatabaseFailedJobRepository;
use Architect\Queue\Support\DriverFactory;
use Architect\Queue\Worker;

/**
 * Сервис-провайдер для системы очередей.
 */
class QueueServiceProvider implements ServiceProviderInterface
{
    protected ?ContainerInterface $container = null;

    public function __construct(?ContainerInterface $container = null)
    {
        if ($container !== null) {
            $this->container = $container;
        }
    }

    /**
     * Логирование сообщения.
     */
    private function log(string $message, ContainerInterface $container): void
    {
        if ($container->has('logger')) {
            $logger = $container->get('logger');
            $logger->debug($message);
        } else {
            error_log($message);
        }
    }

    public function register(ContainerInterface $container): void
    {
        $this->log('[QueueServiceProvider] register called', $container);
        if ($this->container === null) {
            $this->container = $container;
        }

        // Регистрируем фабрику драйверов
        $this->container->factory('queue.driver_factory', function ($container) {
            return new DriverFactory($container);
        });

        // Регистрируем менеджер очередей
        $this->container->factory('queue.manager', function ($container) {
            $config = $this->loadQueueConfig($container);
            $driverFactory = $container->get('queue.driver_factory');
            return new QueueManager($container, $driverFactory, $config);
        });

        // Регистрируем диспетчер
        $this->container->factory('queue.dispatcher', function ($container) {
            $manager = $container->get('queue.manager');
            return new Dispatcher($manager, $container);
        });

        // Регистрируем репозиторий неудачных задач
        $this->container->factory(FailedJobRepositoryInterface::class, function ($container) {
            $config = $this->loadQueueConfig($container);
            $connectionName = $config['failed']['connection'] ?? 'default';
            $table = $config['failed']['table'] ?? 'failed_jobs';

            // Получаем соединение с БД через Axiom
            if ($container->has('db.connection.' . $connectionName)) {
                $connection = $container->get('db.connection.' . $connectionName);
            } elseif ($container->has('db.connection')) {
                $connection = $container->get('db.connection');
            } else {
                throw new \RuntimeException('Database connection not found for failed jobs repository.');
            }

            return new DatabaseFailedJobRepository($connection, $table);
        });

        // Регистрируем диспетчер событий
        $this->container->factory(EventDispatcherInterface::class, function ($container) {
            return new SimpleEventDispatcher();
        });

        // Регистрируем конвейер middleware
        $this->container->factory('queue.middleware.pipeline', function ($container) {
            $pipeline = new MiddlewarePipeline();
            // Добавляем middleware по умолчанию из конфигурации
            $config = $this->loadQueueConfig($container);
            $middlewareClasses = $config['middleware'] ?? [];
            foreach ($middlewareClasses as $className) {
                if (class_exists($className)) {
                    $pipeline->push($container->has($className) ? $container->get($className) : new $className());
                }
            }
            return $pipeline;
        });

        // Регистрируем middleware по умолчанию
        $this->container->factory(LoggingMiddleware::class, function ($container) {
            $logger = $container->has('logger') ? $container->get('logger') : null;
            return new LoggingMiddleware($logger);
        });

        $this->container->factory(DatabaseTransactionMiddleware::class, function ($container) {
            $connectionManager = $container->has('db.connection_manager')
                ? $container->get('db.connection_manager')
                : null;
            if (!$connectionManager) {
                throw new \RuntimeException('ConnectionManager not found for DatabaseTransactionMiddleware');
            }
            return new DatabaseTransactionMiddleware($connectionManager, 'default');
        });

        // Регистрируем воркер
        $this->container->factory('queue.worker', function ($container) {
            $manager = $container->get('queue.manager');
            $logger = $container->has('logger') ? $container->get('logger') : null;
            $failedRepo = $container->has(FailedJobRepositoryInterface::class)
                ? $container->get(FailedJobRepositoryInterface::class)
                : null;
            $eventDispatcher = $container->has(EventDispatcherInterface::class)
                ? $container->get(EventDispatcherInterface::class)
                : null;
            $middlewarePipeline = $container->has('queue.middleware.pipeline')
                ? $container->get('queue.middleware.pipeline')
                : null;
            return new Worker($manager, $container, $logger, $failedRepo, $eventDispatcher, $middlewarePipeline);
        });

        // Алиасы для удобства
        $this->container->factory('queue', fn($container) => $container->get('queue.manager'));
        $this->container->factory('queue.driver', fn($container) => $container->get('queue.manager')->driver());
    }

    public function boot(ContainerInterface $container): void
    {
        $this->log('[QueueServiceProvider] boot called', $container);
        // Загружаем конфигурацию и регистрируем кастомные драйверы, если есть
        $config = $this->loadQueueConfig($container);
        $this->registerCustomDrivers($config['custom_drivers'] ?? []);

        // Регистрируем консольные команды, если доступен CommandRegistry
        $this->registerConsoleCommands($container);
    }

    /**
     * Регистрирует консольные команды для очередей.
     */
    protected function registerConsoleCommands(ContainerInterface $container): void
    {
        if (!$container->has('console.registry')) {
            $this->log('[QueueServiceProvider] console.registry not found in container', $container);
            return;
        }

        $registry = $container->get('console.registry');
        $this->log('[QueueServiceProvider] console.registry found, registering queue commands', $container);

        $commands = [
            new \Architect\Queue\Console\Commands\QueueWorkCommand($container),
            new \Architect\Queue\Console\Commands\QueueStatusCommand($container),
            new \Architect\Queue\Console\Commands\QueueFlushCommand($container),
            new \Architect\Queue\Console\Commands\QueueRetryCommand($container),
            new \Architect\Queue\Console\Commands\MakeQueueMigrationCommand($container),
        ];

        foreach ($commands as $command) {
            $registry->register($command);
            $this->log('[QueueServiceProvider] Registered command: ' . get_class($command), $container);
        }
    }

    /**
     * Загружает конфигурацию очередей.
     *
     * @param ContainerInterface $container
     * @return array
     */
    protected function loadQueueConfig(ContainerInterface $container): array
    {
        if ($container->has('config.loader')) {
            $loader = $container->get('config.loader');
            // Пытаемся загрузить конфигурацию queue.json
            try {
                $configService = $loader->load('queue');
                return $configService->all();
            } catch (\Exception $e) {
                // Если файл не найден, возвращаем конфигурацию по умолчанию
            }
        }

        return $this->getDefaultConfig();
    }

    /**
     * Возвращает конфигурацию по умолчанию.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'default' => 'sync',
            'connections' => [
                'sync' => ['driver' => 'sync'],
                'database' => ['driver' => 'database', 'table' => 'queue_jobs'],
                'redis' => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
            ],
            'failed' => [
                'connection' => 'default',
                'table' => 'failed_jobs',
            ],
            'middleware' => [
                // По умолчанию можно добавить LoggingMiddleware, но для простоты оставим пустым
                // 'Architect\Queue\Middleware\LoggingMiddleware',
            ],
        ];
    }

    /**
     * Регистрирует кастомные драйверы из конфигурации.
     *
     * @param array $customDrivers
     * @return void
     */
    protected function registerCustomDrivers(array $customDrivers): void
    {
        if (empty($customDrivers) || !$this->container->has('queue.driver_factory')) {
            return;
        }

        $factory = $this->container->get('queue.driver_factory');
        foreach ($customDrivers as $name => $className) {
            $factory->extend($name, $className);
        }
    }
}
