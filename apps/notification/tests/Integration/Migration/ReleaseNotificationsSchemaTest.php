<?php

declare(strict_types=1);

namespace Tests\Integration\Migration;

use PDO;
use Tests\Integration\IntegrationTestCase;

final class ReleaseNotificationsSchemaTest extends IntegrationTestCase
{
    public function testReleaseNotificationsPreservesMonolithLedgerShapeWithoutSubscriptionForeignKey(): void
    {
        self::assertSame([
            'attempt_count' => ['integer', 'NO'],
            'claim_token' => ['character varying', 'YES'],
            'claimed_at' => ['timestamp with time zone', 'YES'],
            'created_at' => ['timestamp with time zone', 'YES'],
            'email' => ['character varying', 'YES'],
            'id' => ['integer', 'NO'],
            'last_error' => ['text', 'YES'],
            'repository' => ['character varying', 'NO'],
            'sent_at' => ['timestamp with time zone', 'YES'],
            'subscription_id' => ['integer', 'NO'],
            'tag_name' => ['character varying', 'NO'],
            'updated_at' => ['timestamp with time zone', 'YES'],
        ], $this->columns());

        self::assertSame([], $this->foreignKeys(), 'notification service must not reference monolith subscriptions');
        self::assertContains('UNIQUE (subscription_id, repository, tag_name)', $this->uniqueConstraints());
        self::assertContains('USING btree (subscription_id, repository, tag_name)', $this->indexes());
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function columns(): array
    {
        $rows = $this->c->get(PDO::class)->query(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'release_notifications'
             ORDER BY column_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            /** @var array{column_name: string, data_type: string, is_nullable: string} $row */
            $columns[$row['column_name']] = [$row['data_type'], $row['is_nullable']];
        }

        return $columns;
    }

    /** @return list<string> */
    private function foreignKeys(): array
    {
        return $this->constraintDefinitions('f');
    }

    /** @return list<string> */
    private function uniqueConstraints(): array
    {
        return $this->constraintDefinitions('u');
    }

    /** @return list<string> */
    private function constraintDefinitions(string $type): array
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'release_notifications'::regclass AND contype = :type
             ORDER BY conname"
        );
        $stmt->execute([':type' => $type]);

        return array_values(array_map(
            static fn(array $row): string => (string) $row['definition'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<string> */
    private function indexes(): array
    {
        $stmt = $this->c->get(PDO::class)->query(
            "SELECT indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = 'release_notifications'
             ORDER BY indexname"
        );

        return array_values(array_map(
            static function (array $row): string {
                preg_match('/USING [a-z]+ \(([^)]+)\)/', (string) $row['indexdef'], $matches);

                return isset($matches[0]) ? $matches[0] : (string) $row['indexdef'];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }
}
