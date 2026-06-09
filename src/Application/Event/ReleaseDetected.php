<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ReleaseDetected implements ApplicationEvent
{
    public function __construct(
        public string $repository,
        public string $tag,
        public ?string $previousTag
    ) {
    }
}
