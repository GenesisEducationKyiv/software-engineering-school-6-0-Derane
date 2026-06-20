<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Immutable, self-validating identity value object for a saga instance.
 *
 * A lowercase, canonical RFC 4122 §4.4 UUID v4. Promoted to the Shared kernel so
 * Saga.Domain can mint ids with no cross-context edge to a generator port. Minted
 * from random_bytes(16) with the version/variant bits set — the same pure-PHP
 * approach as Notification\Publishing's UuidV4EventIdGenerator, with no new
 * Composer dependency.
 *
 * @psalm-api
 */
final readonly class SagaId implements \Stringable
{
    private const string PATTERN =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(private string $value)
    {
        if (preg_match(self::PATTERN, $this->value) !== 1) {
            throw new InvalidArgumentException("Invalid saga id: not a UUID v4: {$this->value}");
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function generate(): self
    {
        // RFC 4122 §4.4 UUID v4: set version bits (byte 6 -> 0x4x) and variant
        // bits (byte 8 -> 0x8x/0x9x/0xax/0xbx) before hex-encoding.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        /** @var list<string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks));
    }

    public function value(): string
    {
        return $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
