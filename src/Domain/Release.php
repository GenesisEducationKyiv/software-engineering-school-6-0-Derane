<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Release
{
    public function __construct(
        public ?string $tagName,
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body
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
