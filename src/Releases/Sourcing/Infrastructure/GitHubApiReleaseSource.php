<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\RateLimitException;
use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Releases\Sourcing\Infrastructure\Cache\LatestReleaseCacheInterface;
use App\Releases\Sourcing\Infrastructure\Cache\RepositoryExistenceCacheInterface;
use App\Releases\Sourcing\Infrastructure\Factory\ReleaseFactoryInterface;
use App\Shared\Domain\ValueObject\RepositoryName;
use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class GitHubApiReleaseSource implements ReleaseSource
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
    public function repositoryExists(RepositoryName $repository): bool
    {
        $repositoryName = $repository->value();

        $cached = $this->repositoryCache->getExists($repositoryName);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->apiClient->getRepository($repositoryName);
            $this->repositoryCache->putExists($repositoryName, true);
            return true;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === StatusCodeInterface::STATUS_NOT_FOUND) {
                $this->repositoryCache->putExists($repositoryName, false);
                return false;
            }
            if ($statusCode === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repositoryName}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        $repositoryName = $repository->value();

        $cached = $this->releaseCache->getLatestRelease($repositoryName);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $payload = $this->apiClient->getLatestRelease($repositoryName);
            $release = $this->releaseFactory->fromGitHubPayload($payload);

            $this->releaseCache->putLatestRelease($repositoryName, $release);

            return $release;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_NOT_FOUND) {
                return null;
            }
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repositoryName}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }
}
