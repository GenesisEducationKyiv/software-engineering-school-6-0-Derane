<?php

declare(strict_types=1);

namespace Tests\Integration\Migration;

use App\Migration\Migrator;
use PDO;
use Tests\Integration\IntegrationTestCase;

final class MigratorTest extends IntegrationTestCase
{
    public function testMigrationsTableExistsAfterBootstrap(): void
    {
        $exists = $this->c->get(PDO::class)
            ->query("SELECT to_regclass('public.migrations') IS NOT NULL")
            ->fetchColumn();

        $this->assertTrue((bool) $exists);
    }

    public function testAllMigrationFilesAreRecorded(): void
    {
        $recorded = $this->c->get(PDO::class)
            ->query('SELECT filename FROM migrations ORDER BY filename')
            ->fetchAll(PDO::FETCH_COLUMN);

        $available = array_map(
            'basename',
            glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: []
        );
        sort($available);

        $this->assertSame($available, $recorded);
    }

    public function testReRunIsNoOp(): void
    {
        $pdo = $this->c->get(PDO::class);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

        $this->c->get(Migrator::class)->migrate();

        $after = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame($before, $after);
    }

    public function testExpectedTablesExist(): void
    {
        $pdo = $this->c->get(PDO::class);
        foreach (['subscriptions', 'repositories', 'release_notifications'] as $table) {
            $exists = $pdo->query("SELECT to_regclass('public.{$table}') IS NOT NULL")->fetchColumn();
            $this->assertTrue((bool) $exists, "Table {$table} should exist");
        }
    }
}
