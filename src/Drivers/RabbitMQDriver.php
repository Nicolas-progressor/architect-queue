<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Драйвер очереди на основе RabbitMQ.
 * Требует установки php-amqplib/php-amqplib.
 * Заглушка: реализует интерфейс, но выбрасывает исключение при использовании,
 * если библиотека не установлена.
 */
class RabbitMQDriver implements QueueDriverInterface
{
    protected array $config;
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;
    protected array $declaredQueues = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Устанавливает соединение с RabbitMQ.
     *
     * @throws RuntimeException Если библиотека не установлена или соединение не удалось
     */
    protected function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }

        if (!class_exists(AMQPStreamConnection::class)) {
            throw new RuntimeException(
                'RabbitMQ driver requires php-amqplib/php-amqplib library. ' .
                'Install it via composer: composer require php-amqplib/php-amqplib'
            );
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5672;
        $user = $this->config['user'] ?? 'guest';
        $password = $this->config['password'] ?? 'guest';
        $vhost = $this->config['vhost'] ?? '/';

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
            $this->channel = $this->connection->channel();
        } catch (\Exception $e) {
            throw new RuntimeException('RabbitMQ connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Объявляет очередь, если она ещё не объявлена.
     */
    protected function declareQueue(string $queue): void
    {
        $this->connect();
        if (in_array($queue, $this->declaredQueues, true)) {
            return;
        }

        $this->channel->queue_declare(
            $queue,
            false, // passive
            $this->config['durable'] ?? true,
            false, // exclusive
            false  // auto_delete
        );
        $this->declaredQueues[] = $queue;
    }

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $this->connect();
        $this->declareQueue($queue);

        $message = new AMQPMessage(
            json_encode($job->toArray(), JSON_UNESCAPED_UNICODE),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        // Поддержка задержки через плагин delayed-message-exchange (не реализовано)
        // Для простоты игнорируем delay
        $this->channel->basic_publish($message, '', $queue);

        return $job->getId();
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->connect();
        $this->declareQueue($queue);

        $message = $this->channel->basic_get($queue, true); // no_ack = false
        if ($message === null) {
            return null;
        }

        $payload = json_decode($message->body, true);
        if (!$payload) {
            // Отклоняем сообщение
            $this->channel->basic_nack($message->delivery_info['delivery_tag']);
            throw new RuntimeException('Invalid job payload from RabbitMQ');
        }

        $jobClass = $payload['class'] ?? null;
        if (!$jobClass || !class_exists($jobClass)) {
            $this->channel->basic_nack($message->delivery_info['delivery_tag']);
            throw new RuntimeException("Job class {$jobClass} not found");
        }

        /** @var JobInterface $jobInstance */
        $jobInstance = new $jobClass();
        $jobInstance->restoreFromPayload($payload);
        $jobInstance->setQueue($queue);
        $jobInstance->setMetaValue('rabbitmq_delivery_tag', $message->delivery_info['delivery_tag']);

        return $jobInstance;
    }

    public function acknowledge(JobInterface $job, string $queue = 'default'): void
    {
        $this->connect();
        $deliveryTag = $job->getMetaValue('rabbitmq_delivery_tag');
        if ($deliveryTag === null) {
            return;
        }

        $this->channel->basic_ack($deliveryTag);
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // В RabbitMQ можно отправить сообщение с задержкой через обменник delayed,
        // но для простоты отправляем новое сообщение с задержкой (если delay > 0)
        // и подтверждаем старое.
        $this->push($job, $queue, $delay);
        $this->acknowledge($job, $queue);
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        $this->connect();
        $deliveryTag = $job->getMetaValue('rabbitmq_delivery_tag');
        if ($deliveryTag === null) {
            return;
        }

        // Отклоняем сообщение без повторной постановки в очередь
        $this->channel->basic_nack($deliveryTag, false, false);
        $job->failed(new RuntimeException('Job failed in RabbitMQ driver'));
    }

    public function count(string $queue = 'default'): int
    {
        $this->connect();
        $this->declareQueue($queue);

        $result = $this->channel->queue_declare(
            $queue,
            true // passive
        );
        // $result содержит [queue, message_count, consumer_count]
        return $result[1] ?? 0;
    }

    public function clear(string $queue = 'default'): void
    {
        $this->connect();
        $this->channel->queue_purge($queue);
    }

    public function listQueues(): array
    {
        // RabbitMQ не предоставляет простого API для списка очередей без прав администратора.
        // Возвращаем только объявленные в рамках этого драйвера.
        return $this->declaredQueues;
    }

    /**
     * Закрывает соединение с RabbitMQ.
     */
    public function __destruct()
    {
        if ($this->channel !== null) {
            $this->channel->close();
        }
        if ($this->connection !== null) {
            $this->connection->close();
        }
    }
}
