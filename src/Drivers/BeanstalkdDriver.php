<?php

declare(strict_types=1);

namespace Architect\Queue\Drivers;

use Architect\Queue\Contracts\JobInterface;
use Architect\Queue\Contracts\QueueDriverInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Contract\PheanstalkInterface;
use RuntimeException;

/**
 * Драйвер очереди на основе Beanstalkd.
 * Использует библиотеку pheanstalk/pheanstalk.
 */
class BeanstalkdDriver implements QueueDriverInterface
{
    protected PheanstalkInterface $client;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Устанавливает соединение с Beanstalkd.
     *
     * @throws RuntimeException Если не удалось подключиться или библиотека отсутствует
     */
    protected function connect(): void
    {
        if (!class_exists(Pheanstalk::class)) {
            throw new RuntimeException(
                'Beanstalkd driver requires pheanstalk/pheanstalk library. ' .
                'Install it via composer: composer require pheanstalk/pheanstalk'
            );
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 11300;
        $timeout = $this->config['timeout'] ?? 10;

        try {
            $this->client = Pheanstalk::create($host, $port, $timeout);
        } catch (\Exception $e) {
            throw new RuntimeException("Beanstalkd connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $tube = $this->normalizeTube($queue);
        $payload = json_encode($job->toArray(), JSON_UNESCAPED_UNICODE);
        $priority = $this->config['priority'] ?? PheanstalkInterface::DEFAULT_PRIORITY;
        $ttr = $this->config['ttr'] ?? 60; // time to run

        $jobId = $this->client->useTube($tube)->put($payload, $priority, $delay, $ttr);
        return (string) $jobId;
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $tube = $this->normalizeTube($queue);
        $this->client->watch($tube);
        $this->client->ignore('default');

        $beanstalkJob = $this->client->reserveWithTimeout(0); // неблокирующий
        if ($beanstalkJob === null) {
            return null;
        }

        $payload = json_decode($beanstalkJob->getData(), true);
        if (!$payload) {
            $this->client->bury($beanstalkJob);
            throw new RuntimeException('Invalid job payload from Beanstalkd');
        }

        $jobClass = $payload['class'] ?? null;
        if (!$jobClass || !class_exists($jobClass)) {
            $this->client->bury($beanstalkJob);
            throw new RuntimeException("Job class {$jobClass} not found");
        }

        /** @var JobInterface $jobInstance */
        $jobInstance = new $jobClass();
        $jobInstance->restoreFromPayload($payload);
        $jobInstance->setQueue($queue);

        // Сохраняем идентификатор Beanstalkd для последующего подтверждения
        $jobInstance->setMetaValue('beanstalkd_id', $beanstalkJob->getId());

        return $jobInstance;
    }

    public function acknowledge(JobInterface $job, string $queue = 'default'): void
    {
        $jobId = $job->getMetaValue('beanstalkd_id');
        if ($jobId === null) {
            return;
        }

        $tube = $this->normalizeTube($queue);
        $this->client->useTube($tube);
        $beanstalkJob = $this->client->peek($jobId);
        if ($beanstalkJob) {
            $this->client->delete($beanstalkJob);
        }
    }

    public function retry(JobInterface $job, string $queue = 'default', int $delay = 0): void
    {
        // Освобождаем задачу с задержкой (release)
        $jobId = $job->getMetaValue('beanstalkd_id');
        if ($jobId === null) {
            // Если нет ID, просто отправляем заново
            $this->push($job, $queue, $delay);
            return;
        }

        $tube = $this->normalizeTube($queue);
        $this->client->useTube($tube);
        $beanstalkJob = $this->client->peek($jobId);
        if ($beanstalkJob) {
            $this->client->release($beanstalkJob, $delay);
        }
    }

    public function fail(JobInterface $job, string $queue = 'default'): void
    {
        // Помещаем задачу в buried state
        $jobId = $job->getMetaValue('beanstalkd_id');
        if ($jobId === null) {
            return;
        }

        $tube = $this->normalizeTube($queue);
        $this->client->useTube($tube);
        $beanstalkJob = $this->client->peek($jobId);
        if ($beanstalkJob) {
            $this->client->bury($beanstalkJob);
        }

        // Вызываем метод failed у задачи
        $job->failed(new RuntimeException('Job failed in Beanstalkd driver'));
    }

    public function count(string $queue = 'default'): int
    {
        $tube = $this->normalizeTube($queue);
        $stats = $this->client->statsTube($tube);
        return (int) ($stats['current-jobs-ready'] ?? 0);
    }

    public function clear(string $queue = 'default'): void
    {
        $tube = $this->normalizeTube($queue);
        $this->client->useTube($tube);

        while (true) {
            $job = $this->client->peekReady();
            if ($job === null) {
                break;
            }
            $this->client->delete($job);
        }
    }

    public function listQueues(): array
    {
        // Beanstalkd не предоставляет список тубов через стандартный API,
        // но можно использовать stats-tube для известных тубов из конфигурации.
        // Для простоты вернём массив с default.
        return ['default'];
    }

    /**
     * Нормализует имя очереди для использования в качестве tube.
     * Beanstalkd допускает только ASCII символы, цифры, дефисы и подчёркивания.
     */
    protected function normalizeTube(string $queue): string
    {
        // Заменяем недопустимые символы на подчёркивания
        $tube = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $queue);
        return $tube ?: 'default';
    }
}