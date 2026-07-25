<?php

// Используем автозагрузчик проекта
require_once __DIR__ . '/../../vendor/autoload.php';

use Architect\Core\Container;
use Architect\Queue\Providers\QueueServiceProvider;
use Architect\Queue\Jobs\Job;

// Создаём контейнер
$container = new Container();

// Регистрируем сервис-провайдер очередей
$provider = new QueueServiceProvider($container);
$provider->register($container);
$provider->boot($container);

// Создаём простую задачу
class ExampleJob extends Job
{
    protected string $queue = 'default';
    protected int $maxAttempts = 3;

    public function __construct(
        protected string $message
    ) {}

    public function handle(): void
    {
        echo "Processing job: {$this->message}\n";
        sleep(1);
        echo "Job completed.\n";
    }
}

// Получаем диспетчер
$dispatcher = $container->get('queue.dispatcher');

echo "Dispatching job...\n";
$dispatcher->dispatch(new ExampleJob('Hello, Queue!'));

// Если используется sync-драйвер, задача выполнится немедленно
// Для других драйверов нужно запустить воркер

// Запускаем воркер для обработки одной задачи (только для демонстрации)
$worker = $container->get('queue.worker');
$worker->processNextJob('default');

echo "Done.\n";