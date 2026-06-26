<?php

declare(strict_types=1);

namespace Tests\Integration\Migration;

use App\Shared\Infrastructure\Migration\Migrator;
use PDO;
use Tests\Integration\IntegrationTestCase;

final class NotificationMigratorTest extends IntegrationTestCase
{
    public function testMigrationsTableExistsAfterBootstrap(): void
    {
        $exists = $this->c->get(PDO::class)
            ->query("SELECT to_regclass('public.migrations') IS NOT NULL")
            ->fetchColumn();

        self::assertTrue((bool) $exists);
    }

    public function testAllMigrationFilesAreRecorded(): void
    {
        $recorded = $this->c->get(PDO::class)
            ->query('SELECT filename FROM migrations ORDER BY filename')
            ->fetchAll(PDO::FETCH_COLUMN);

        $available = array_map(
            'basename',
            glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: [],
        );
        sort($available);

        self::assertSame($available, $recorded);
    }

    public function testReRunIsNoOp(): void
    {
        $pdo = $this->c->get(PDO::class);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

        $this->migrator()->migrate();

        $after = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        self::assertSame($before, $after);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->c->get(PDO::class), dirname(__DIR__, 3) . '/migrations');
    }
}
