<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\SagaId;
use PHPUnit\Framework\TestCase;

final class SagaIdTest extends TestCase
{
    public function testFromStringRoundTrips(): void
    {
        $uuid = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

        $sagaId = SagaId::fromString($uuid);

        $this->assertSame($uuid, $sagaId->value());
        $this->assertSame($uuid, (string) $sagaId);
    }

    public function testGenerateProducesAValidV4Uuid(): void
    {
        $sagaId = SagaId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sagaId->value()
        );
        // Round-trips through fromString without throwing.
        $this->assertTrue($sagaId->equals(SagaId::fromString($sagaId->value())));
    }

    public function testGenerateProducesUniqueValues(): void
    {
        $this->assertNotSame(SagaId::generate()->value(), SagaId::generate()->value());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'too short' => ['5f1c0e2a-9b3d-4c7a-8e21'];
        yield 'wrong version nibble' => ['5f1c0e2a-9b3d-1c7a-8e21-7c9b0d4e1f33'];
        yield 'wrong variant nibble' => ['5f1c0e2a-9b3d-4c7a-0e21-7c9b0d4e1f33'];
        yield 'uppercase rejected' => ['5F1C0E2A-9B3D-4C7A-8E21-7C9B0D4E1F33'];
        yield 'trailing junk' => ['5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33x'];
    }

    /**
     * @dataProvider malformedProvider
     */
    public function testFromStringRejectsMalformedUuid(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        SagaId::fromString($input);
    }

    public function testConstructorRejectsMalformedUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SagaId('definitely-not-a-uuid');
    }

    public function testEquals(): void
    {
        $a = SagaId::fromString('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33');
        $b = SagaId::fromString('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33');
        $c = SagaId::fromString('11111111-1111-4111-8111-111111111111');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
