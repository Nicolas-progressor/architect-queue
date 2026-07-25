<?php

declare(strict_types=1);

namespace Architect\Queue\Console\Commands;

use Architect\Console\BaseCommand;
use Architect\Console\CommandInterface;
use Psr\Container\ContainerInterface;

/**
 * Создаёт миграции для таблиц очередей (queue_jobs и failed_jobs) в проекте.
 */
class MakeQueueMigrationCommand extends BaseCommand implements CommandInterface
{
    protected string $name = 'make:queue-migration';
    protected string $description = 'Create migration files for queue system tables (queue_jobs and failed_jobs)';

    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->container = $container;
    }

    public function getOptions(): array
    {
        return [
            ['--table', 'Table name (queue_jobs or failed_jobs). If not specified, creates both.', null],
            ['--path', 'Custom directory for migration files (relative to project root).', null],
        ];
    }

    public function execute(array $arguments, array $options): int
    {
        $tableOption = $options['table'] ?? null;
        $customPath = $options['path'] ?? null;

        $tables = [];
        if ($tableOption === 'queue_jobs') {
            $tables = ['queue_jobs'];
        } elseif ($tableOption === 'failed_jobs') {
            $tables = ['failed_jobs'];
        } else {
            $tables = ['queue_jobs', 'failed_jobs'];
        }

        $migrationsPath = $this->getMigrationsPath($customPath);
        $this->info("Migrations directory: {$migrationsPath}");

        foreach ($tables as $table) {
            $this->createMigrationForTable($table, $migrationsPath);
        }

        $this->success('Migrations created successfully.');
        $this->line('Run migrations with: <comment>php bin/arc db:migrate</comment>');

        return 0;
    }

    /**
     * Определяет путь к директории миграций.
     */
    private function getMigrationsPath(?string $customPath): string
    {
        if ($customPath !== null) {
            $path = getcwd() . '/' . ltrim($customPath, '/');
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            return $path;
        }

        $possiblePaths = [
            getcwd() . '/migrations',
            getcwd() . '/database/migrations',
            __DIR__ . '/../../../../migrations',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Если не найдено, создаём в текущей рабочей директории
        $defaultPath = getcwd() . '/migrations';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }
        return $defaultPath;
    }

    /**
     * Создаёт файл миграции для указанной таблицы.
     */
    private function createMigrationForTable(string $table, string $migrationsPath): void
    {
        $timestamp = date('Y_m_d_His');
        $className = 'Create' . $this->studly($table) . 'Table' . $timestamp;
        $fileName = "{$timestamp}_create_{$table}_table.php";

        $filePath = $migrationsPath . DIRECTORY_SEPARATOR . $fileName;

        if (file_exists($filePath)) {
            $this->warning("Migration file already exists: {$fileName}");
            return;
        }

        $content = $this->generateMigrationContent($table, $className);
        file_put_contents($filePath, $content);
        $this->info("Created migration: {$fileName}");
    }

    /**
     * Генерирует содержимое миграции.
     */
    private function generateMigrationContent(string $table, string $className): string
    {
        if ($table === 'queue_jobs') {
            return $this->queueJobsMigration($className);
        }

        if ($table === 'failed_jobs') {
            return $this->failedJobsMigration($className);
        }

        throw new \InvalidArgumentException("Unknown table: {$table}");
    }

    /**
     * Миграция для таблицы queue_jobs.
     */
    private function queueJobsMigration(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Axiom\Migration\Migration;
use Axiom\Migration\Blueprint;

class {$className} extends Migration
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        \$this->create('queue_jobs', function (Blueprint \$table) {
            \$table->id();
            \$table->string('queue')->index();
            \$table->longText('payload');
            \$table->unsignedTinyInteger('attempts')->default(0);
            \$table->unsignedInteger('reserved_at')->nullable();
            \$table->unsignedInteger('available_at');
            \$table->unsignedInteger('created_at');
            \$table->index(['queue', 'reserved_at']);
        });
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        \$this->drop('queue_jobs');
    }
}
PHP;
    }

    /**
     * Миграция для таблицы failed_jobs.
     */
    private function failedJobsMigration(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Axiom\Migration\Migration;
use Axiom\Migration\Blueprint;

class {$className} extends Migration
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        \$this->create('failed_jobs', function (Blueprint \$table) {
            \$table->id();
            \$table->string('queue');
            \$table->longText('payload');
            \$table->longText('exception');
            \$table->unsignedInteger('failed_at');
            \$table->index(['queue']);
        });
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        \$this->drop('failed_jobs');
    }
}
PHP;
    }

    /**
     * Преобразует строку в StudlyCase.
     */
    private function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }
}