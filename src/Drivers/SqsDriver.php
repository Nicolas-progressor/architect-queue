<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use RuntimeException;

/**
 * Драйвер очереди на основе Amazon SQS.
 * Требует установки aws/aws-sdk-php.
 * Заглушка: реализует интерфейс, но выбрасывает исключение при использовании,
 * если SDK не установлен.
 */
class SqsDriver implements QueueDriverInterface
{
    protected array $config;
    protected ?object $sqsClient = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Инициализирует клиент SQS.
     *
     * @throws RuntimeException Если AWS SDK не установлен
     */
    protected function initClient(): void
    {
        if ($this->sqsClient !== null) {
            return;
        }

        if (!class_exists(\Aws\Sqs\SqsClient::class)) {
            throw new RuntimeException(
                'SQS driver requires aws/aws-sdk-php library. ' .
                'Install it via composer: composer require aws/aws-sdk-php'
            );
        }

        $this->sqsClient = new \Aws\Sqs\SqsClient([
            'version' => 'latest',
            'region' => $this->config['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $this->config['key'] ?? '',
                'secret' => $this->config['secret'] ?? '',
            ],
            'endpoint' => $this->config['endpoint'] ?? null,
        ]);
    }

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $this->initClient();
        $queueUrl = $this->getQueueUrl($queue);

        $result = $this->sqsClient->sendMessage([
            'QueueUrl' => $queueUrl,
            'MessageBody' => json_encode($job->toArray(), JSON_UNESCAPED_UNICODE),
            'DelaySeconds' => $delay,
        ]);

        return $result->get('MessageId');
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->initClient();
        $queueUrl = $this->getQueueUrl($queue);

        $result = $this->sqsClient->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $this->config['wait_time'] ?? 0,
        ]);

        $messages = $result->get('Messages');
        if (empty($messages)) {
            return null;
        }

        $message = $messages[0];
        $payload = json_decode($message['Body'], true);
        if (!$payload) {
            // Удаляем некорректное сообщение
            $this->sqsClient->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $message['ReceiptHandle'],
            ]);
            throw new RuntimeException('Invalid job payload from SQS');
        }

        $jobClass = $payload['class'] ?? null;
        if (!$jobClass || !class_exists($jobClass)) {
            $this->sqsClient->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $message['ReceiptHandle'],
            ]);
            throw new RuntimeException("Job class {$jobClass} not found");
        }

        /** @var JobInterface $jobInstance */
        $jobInstance = new $jobClass();
        $jobInstance->restoreFromPayload($payload);
        $jobInstance->setQueue($queue);
        $jobInstance->setMetaValue('sqs_receipt_handle', $message['ReceiptHandle']);
        $jobInstance->setMetaValue('sqs_message_id', $message['MessageId']);

        return $jobInstance;
    }

    public function acknowledge(JobInterface $job, string $queue = 'default'): void
    {
        $this->initClient();
        $receiptHandle = $job->getMetaValue('sqs_receipt_handle');
        if (!$receiptHandle) {
            return;
        }

        $queueUrl = $this->getQueueUrl($queue);
        $this->sqsClient->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // В SQS нет встроенного механизма retry, кроме visibility timeout.
        // Мы можем изменить visibility timeout или отправить новое сообщение.
        // Для простоты отправляем новое сообщение с задержкой.
        $this->push($job, $queue, $delay);
        // Удаляем старое сообщение
        $this->acknowledge($job, $queue);
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        // В SQS можно переместить сообщение в dead-letter queue (DLQ),
        // но для простоты просто удаляем.
        $this->acknowledge($job, $queue);
        $job->failed(new RuntimeException('Job failed in SQS driver'));
    }

    public function count(string $queue = 'default'): int
    {
        $this->initClient();
        $queueUrl = $this->getQueueUrl($queue);
        $result = $this->sqsClient->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        $attributes = $result->get('Attributes');
        return (int) ($attributes['ApproximateNumberOfMessages'] ?? 0);
    }

    public function clear(string $queue = 'default'): void
    {
        $this->initClient();
        $queueUrl = $this->getQueueUrl($queue);
        // Очистка очереди SQS требует отдельного API вызова
        // Для простоты просто удаляем все сообщения по одному (неэффективно)
        // В реальной реализации следует использовать purgeQueue.
        if (method_exists($this->sqsClient, 'purgeQueue')) {
            $this->sqsClient->purgeQueue(['QueueUrl' => $queueUrl]);
        } else {
            // Fallback: получаем и удаляем сообщения
            while (true) {
                $result = $this->sqsClient->receiveMessage([
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => 10,
                    'VisibilityTimeout' => 0,
                ]);
                $messages = $result->get('Messages');
                if (empty($messages)) {
                    break;
                }
                foreach ($messages as $message) {
                    $this->sqsClient->deleteMessage([
                        'QueueUrl' => $queueUrl,
                        'ReceiptHandle' => $message['ReceiptHandle'],
                    ]);
                }
            }
        }
    }

    public function listQueues(): array
    {
        $this->initClient();
        $result = $this->sqsClient->listQueues();
        $urls = $result->get('QueueUrls') ?: [];
        $queues = [];
        foreach ($urls as $url) {
            // Извлекаем имя очереди из URL
            $parts = explode('/', $url);
            $queues[] = end($parts);
        }
        return $queues;
    }

    /**
     * Возвращает URL очереди по её имени.
     *
     * @param string $queue
     * @return string
     */
    protected function getQueueUrl(string $queue): string
    {
        // Если в конфигурации указан полный URL, используем его
        if (!empty($this->config['queue_url'])) {
            return $this->config['queue_url'];
        }

        // Иначе строим URL на основе региона и аккаунта
        $account = $this->config['account_id'] ?? '000000000000';
        $region = $this->config['region'] ?? 'us-east-1';
        $prefix = $this->config['prefix'] ?? '';

        $queueName = $prefix . $queue;
        return "https://sqs.{$region}.amazonaws.com/{$account}/{$queueName}";
    }
}