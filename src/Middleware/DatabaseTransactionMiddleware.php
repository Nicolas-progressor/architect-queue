<?php

declare(strict_types=1);

namespace Architect\Queue\Middleware;

use Architect\Queue\Contracts\JobInterface;
use Axiom\Orm\Connection\ConnectionManager;

/**
 * Middleware, оборачивающий выполнение задачи в транзакцию базы данных.
 */
class DatabaseTransactionMiddleware implements JobMiddlewareInterface
{
    protected ConnectionManager $connectionManager;
    protected string $connectionName;

    public function __construct(ConnectionManager $connectionManager, string $connectionName = 'default')
    {
        $this->connectionManager = $connectionManager;
        $this->connectionName = $connectionName;
    }

    public function handle(JobInterface $job, callable $next): void
    {
        $connection = $this->connectionManager->getConnection($this->connectionName);
        $connection->beginTransaction();

        try {
            $next($job);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}