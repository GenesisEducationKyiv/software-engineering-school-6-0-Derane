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

    /**
     * @return list<array{table: string, columns: array<int, string>}>
     */
    public static function uniqueConstraintProvider(): array
    {
        return [
            'subscriptions(email, repository)' => [
                ['table' => 'subscriptions', 'columns' => ['email', 'repository']],
            ],
            'release_notifications(subscription_id, repository, tag_name)' => [
                ['table' => 'release_notifications', 'columns' => ['subscription_id', 'repository', 'tag_name']],
            ],
        ];
    }

    /**
     * @param array{table: string, columns: array<int, string>} $spec
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uniqueConstraintProvider')]
    public function testUniqueConstraintExists(array $spec): void
    {
        $pdo = $this->c->get(PDO::class);

        $stmt = $pdo->prepare(
            "SELECT array_agg(a.attname::text ORDER BY array_position(c.conkey, a.attnum)) AS columns
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
              WHERE c.contype = 'u'
                AND t.relname = :table
              GROUP BY c.oid
             HAVING array_agg(a.attname::text ORDER BY array_position(c.conkey, a.attnum)) = :columns::text[]"
        );
        $stmt->execute([
            'table' => $spec['table'],
            'columns' => '{' . implode(',', $spec['columns']) . '}',
        ]);

        $this->assertNotFalse(
            $stmt->fetchColumn(),
            sprintf(
                'Expected a UNIQUE constraint on %s(%s)',
                $spec['table'],
                implode(', ', $spec['columns'])
            )
        );
    }

    public function testReleaseNotificationsCascadeOnSubscriptionDelete(): void
    {
        $pdo = $this->c->get(PDO::class);

        $stmt = $pdo->query(
            "SELECT c.confdeltype
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
               JOIN pg_class r ON r.oid = c.confrelid
              WHERE c.contype = 'f'
                AND t.relname = 'release_notifications'
                AND a.attname = 'subscription_id'
                AND r.relname = 'subscriptions'"
        );
        $action = $stmt !== false ? $stmt->fetchColumn() : false;

        $this->assertSame(
            'c',
            $action,
            'release_notifications.subscription_id FK should be ON DELETE CASCADE'
        );
    }
}
