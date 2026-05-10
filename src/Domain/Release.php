<?php

declare(strict_types=1);

namespace App\Domain;

final class Release
{
    public function __construct(
        public readonly ?string $tagName,
        public readonly string $name,
        public readonly string $htmlUrl,
        public readonly string $publishedAt,
        public readonly string $body
    ) {
    }

    /** @return array{tag_name: string|null, name: string, html_url: string, published_at: string, body: string} */
    public function toArray(): array
    {
        return [
            'tag_name' => $this->tagName,
            'name' => $this->name,
            'html_url' => $this->htmlUrl,
            'published_at' => $this->publishedAt,
            'body' => $this->body,
        ];
    }
}
