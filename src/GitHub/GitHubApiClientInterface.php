<?php

declare(strict_types=1);

namespace App\GitHub;

/** @psalm-api */
interface GitHubApiClientInterface
{
    /** @return array<string, mixed> */
    public function getRepository(string $repository): array;

    /** @return array<string, mixed> */
    public function getLatestRelease(string $repository): array;
}
