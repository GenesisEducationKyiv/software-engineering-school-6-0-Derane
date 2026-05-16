<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Factory\ReleaseFactoryInterface;
use App\Domain\Release;
use App\Exception\RateLimitException;
use App\GitHub\GitHubApiClientInterface;
use App\GitHub\LatestReleaseCacheInterface;
use App\GitHub\RepositoryExistenceCacheInterface;
use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class GitHubService implements GitHubServiceInterface
{
    public function __construct(
        private GitHubApiClientInterface $apiClient,
        private RepositoryExistenceCacheInterface $repositoryCache,
        private LatestReleaseCacheInterface $releaseCache,
        private ReleaseFactoryInterface $releaseFactory,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function repositoryExists(string $repository): bool
    {
        $cached = $this->repositoryCache->getExists($repository);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->apiClient->getRepository($repository);
            $this->repositoryCache->putExists($repository, true);
            return true;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === StatusCodeInterface::STATUS_NOT_FOUND) {
                $this->repositoryCache->putExists($repository, false);
                return false;
            }
            if ($statusCode === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repository}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }

    #[\Override]
    public function getLatestRelease(string $repository): ?Release
    {
        $cached = $this->releaseCache->getLatestRelease($repository);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $payload = $this->apiClient->getLatestRelease($repository);
            $release = $this->releaseFactory->fromGitHubPayload($payload);

            $this->releaseCache->putLatestRelease($repository, $release);

            return $release;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_NOT_FOUND) {
                return null;
            }
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repository}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }
}
