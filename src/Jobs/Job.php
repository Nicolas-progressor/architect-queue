<?php

declare(strict_types=1);

namespace Architect\Queue\Jobs;

use Architect\Queue\Contracts\JobInterface;

/**
 * Абстрактный базовый класс задачи.
 * Реализует общую логику для задач, помещаемых в очередь.
 */
abstract class Job implements JobInterface
{
    protected string $id = '';
    protected string $queue = 'default';
    protected int $delay = 0;
    protected int $attempts = 0;
    protected int $maxAttempts = 3;
    protected array $payload = [];
    protected array $meta = [];

    public function __construct()
    {
        if ($this->id === '') {
            $this->id = uniqid('job_', true);
        }
    }

    public function handle(): void
    {
        // Реализация должна быть переопределена в дочерних классах
    }

    public function failed(\Throwable $exception): void
    {
        // По умолчанию ничего не делаем, можно переопределить
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function setQueue(string $queue): void
    {
        $this->queue = $queue;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function setDelay(int $delay): void
    {
        $this->delay = $delay;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function restoreFromPayload(array $payload): void
    {
        $this->id = $payload['id'] ?? $this->id;
        $this->queue = $payload['queue'] ?? $this->queue;
        $this->delay = $payload['delay'] ?? $this->delay;
        $this->attempts = $payload['attempts'] ?? $this->attempts;
        $this->maxAttempts = $payload['maxAttempts'] ?? $this->maxAttempts;
        $this->payload = $payload['payload'] ?? [];
        $this->meta = $payload['meta'] ?? [];
    }

    /**
     * Подготавливает данные для сериализации.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'delay' => $this->delay,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'payload' => $this->payload,
            'meta' => $this->meta,
            'class' => static::class,
        ];
    }

    /**
     * Создаёт экземпляр задачи из массива данных.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $job = new static();
        $job->restoreFromPayload($data);
        return $job;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function setMetaValue(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }
}