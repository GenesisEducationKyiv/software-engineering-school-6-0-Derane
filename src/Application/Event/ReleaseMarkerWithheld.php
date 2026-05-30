<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ReleaseMarkerWithheld implements ApplicationEvent
{
    public function __construct(
        public string $repository,
        public ?string $tag
    ) {
    }
}
