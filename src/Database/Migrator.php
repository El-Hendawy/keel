<?php

declare(strict_types=1);

namespace Keel\Database;

/**
 * Applies `*.sql` files from a directory in filename order, once each, recording them in
 * `keel_migrations`. Forward-only by design: to undo, write a new migration.
 */
final readonly class Migrator
{
    public function __construct(private \PDO $pdo, private string $directory)
    {
    }

    /** @return list<string> the migrations applied by this call */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS keel_migrations (name VARCHAR(255) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $statement = $this->pdo->query('SELECT name FROM keel_migrations');
        $done = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

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
