<?php

declare(strict_types=1);

namespace App\Observability\Event;

use Psr\Log\LogLevel;

/**
 * How a single application event should appear in the log stream: severity,
 * the stable `event` field value, and the typed context to index alongside it.
 */
final readonly class DescribedLog
{
    /**
     * @param LogLevel::* $level
     * @param non-empty-string $name
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $level,
        public string $name,
        public array $context,
    ) {
    }
}
