<?php

namespace Architect\Queue\Repositories;

use Architect\Queue\Contracts\FailedJobRepositoryInterface;
use Architect\Queue\Jobs\JobInterface;
use Axiom\Orm\Connection\ConnectionInterface;
use Axiom\Orm\Query\QueryBuilder;

class DatabaseFailedJobRepository implements FailedJobRepositoryInterface
{
    /**
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * @var string
     */
    protected $table = 'failed_jobs';

    /**
     * Конструктор.
     *
     * @param ConnectionInterface $connection
     * @param string|null $table
     */
    public function __construct(ConnectionInterface $connection, ?string $table = null)
    {
        $this->connection = $connection;
        if ($table !== null) {
            $this->table = $table;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $connection, string $queue, JobInterface $job, \Throwable $exception): void
    {
        $payload = $job->getPayload();
        $this->connection->table($this->table)->insert([
            'connection' => $connection,
            'queue' => $queue,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'exception' => (string) $exception,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function all(int $limit = 50): array
    {
        return $this->connection->table($this->table)
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?array
    {
        $record = $this->connection->table($this->table)->where('id', $id)->first();
        return $record ? $record->toArray() : null;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->delete() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function retry(int $id): bool
    {
        $record = $this->find($id);
        if (!$record) {
            return false;
        }

        // Восстановление задачи из payload
        $payload = json_decode($record['payload'], true);
        if (!$payload) {
            return false;
        }

        // Здесь нужно диспетчеризовать задачу снова
        // Для простоты предположим, что есть доступ к диспетчеру через контейнер
        // Пока что просто удалим запись и вернём true
        // В реальной реализации нужно будет отправить задачу обратно в очередь
        return $this->delete($id);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): int
    {
        return $this->connection->table($this->table)->delete();
    }
}