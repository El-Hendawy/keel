<?php

declare(strict_types=1);

namespace Keel\Database;

/**
 * Applies `*.sql` files in filename order, once each, recording them in `keel_migrations`.
 * Forward-only by design: to undo, write a new migration.
 *
 * Directories are applied in the order given; framework features (auth, queue, ...)
 * contribute directories of their own, listed in the build manifest, applied first.
 */
final readonly class Migrator
{
    /** @var list<string> */
    private array $directories;

    public function __construct(private \PDO $pdo, string $directory, string ...$more)
    {
        $this->directories = [$directory, ...array_values($more)];
    }

    /** The framework's migrations for the application's features, then the application's own. */
    public static function forApplication(\Keel\Runtime\Application $app, string $directory): self
    {
        $pdo = $app->container()->get(ConnectionManager::class)->writer();

        return new self($pdo, ...[...$app->manifest->migrations(), $directory]);
    }

    /** @return list<string> the migrations applied by this call */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS keel_migrations (name VARCHAR(255) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $statement = $this->pdo->query('SELECT name FROM keel_migrations');
        $done = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        $applied = [];
        foreach ($this->directories as $directory) {
            $files = glob($directory . '/*.sql') ?: [];
            sort($files);
            $applied = [...$applied, ...$this->apply($files, $done)];
            $done = [...$done, ...$applied];
        }

        return $applied;
    }

    /**
     * @param list<string> $files
     * @param array<mixed> $done
     * @return list<string>
     */
    private function apply(array $files, array $done): array
    {
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (str_starts_with($name, '._') || \in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $insert = $this->pdo->prepare('INSERT INTO keel_migrations (name, applied_at) VALUES (?, ?)');
                $insert->execute([$name, gmdate('Y-m-d\TH:i:s\Z')]);
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new \RuntimeException("Migration $name failed: {$e->getMessage()}", 0, $e);
            }
            $applied[] = $name;
        }

        return $applied;
    }
}
