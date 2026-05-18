<?php

declare(strict_types=1);

namespace App\Migration;

use PDO;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationsPath,
        private LoggerInterface $logger
    ) {
    }

    public function migrate(): void
    {
        $this->acquireLock();

        try {
            $this->ensureMigrationsTable();
            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();

            foreach ($files as $file) {
                $filename = basename($file);
                if (in_array($filename, $executed, true)) {
                    continue;
                }

                $this->logger->info("Running migration: {$filename}");
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException("Cannot read migration file: {$file}");
                }

                try {
                    $this->pdo->beginTransaction();
                    $this->pdo->exec($sql);
                    $stmt = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
                    $stmt->execute(['filename' => $filename]);
                    $this->pdo->commit();
                    $this->logger->info("Migration {$filename} completed successfully");
                } catch (\PDOException $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->logger->error("Migration {$filename} failed: " . $e->getMessage());
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
            return [];
        }
        sort($files);
        return $files;
    }

    private function acquireLock(): void
    {
        $this->pdo->query("SELECT pg_advisory_lock(hashtext('github-release-notifier:migrations'))");
    }

    private function releaseLock(): void
    {
        $this->pdo->query("SELECT pg_advisory_unlock(hashtext('github-release-notifier:migrations'))");
    }
}
