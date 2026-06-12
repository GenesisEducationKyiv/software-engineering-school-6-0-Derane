<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Migration;

use PDO;

final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationsPath,
    ) {
    }

    public function migrate(): void
    {
        $this->acquireLock();

        try {
            $this->ensureMigrationsTable();
            $executed = $this->getExecutedMigrations();

            foreach ($this->getMigrationFiles() as $file) {
                $filename = basename($file);
                if (in_array($filename, $executed, true)) {
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException(sprintf('Failed to read migration file: %s', $file));
                }

                try {
                    $this->pdo->beginTransaction();
                    $this->pdo->exec($sql);
                    $stmt = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
                    if ($stmt === false) {
                        throw new \RuntimeException('Failed to prepare migration ledger insert');
                    }

                    if (!$stmt->execute(['filename' => $filename])) {
                        throw new \RuntimeException('Failed to record applied migration');
                    }

                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }

                    throw $e;
                }
            }
        } finally {
            $this->releaseLock();
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
            )
        ');
    }

    /** @return list<string> */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT filename FROM migrations ORDER BY id');
        if ($stmt === false) {
            return [];
        }

        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            throw new \RuntimeException(sprintf('Failed to enumerate migrations in %s', $this->migrationsPath));
        }

        sort($files);
        return $files;
    }

    private function acquireLock(): void
    {
        $this->pdo->query("SELECT pg_advisory_lock(hashtext('github-release-notifier:notification:migrations'))");
    }

    private function releaseLock(): void
    {
        $this->pdo->query("SELECT pg_advisory_unlock(hashtext('github-release-notifier:notification:migrations'))");
    }
}
