<?php

declare(strict_types=1);

namespace App\Service;

interface GitHubServiceInterface
{
    public function repositoryExists(string $repository): bool;

    /** @return array{tag_name: string|null, name: string, html_url: string, published_at: string, body: string}|null */
    public function getLatestRelease(string $repository): ?array;
}
