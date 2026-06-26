<?php

declare(strict_types=1);

/**
 * Example 3: Fixing Domain → transport coupling (Slim Request/Response or gRPC)
 *
 * VIOLATION:
 *   Releases.Domain must not depend on Legacy.Infrastructure
 *     src/Releases/Sourcing/Domain/LatestRelease.php:9
 *       uses Grpc\ReleaseNotifier\V1\GetLatestReleaseRequest
 *
 * (Equivalent shape: Psr\Http\Message\ServerRequestInterface / ResponseInterface
 *  imported into Domain to read input or build a reply.)
 *
 * Fix: keep ALL transport in Infrastructure. A Slim controller or a RoadRunner
 * gRPC handler maps the wire type into a CQRS Command/Query, hands it to the
 * bus, and maps the typed Response back to the wire reply. Domain only ever
 * sees VOs, Commands, and Queries. We do NOT use API Platform or GraphQL — the
 * only transports are Slim 4 REST and RoadRunner gRPC.
 *
 * Wire-format protection: the JSON shape, the gRPC reply, and the Behat
 * assertions are public contract. Moving transport out of Domain keeps the
 * mapping byte-for-byte identical — it just lives where it belongs.
 */

// ============================================================================
// BEFORE (WRONG) — Domain touches gRPC + HTTP transport types
// ============================================================================

namespace Example\Releases\Sourcing\Domain;

use Grpc\ReleaseNotifier\V1\GetLatestReleaseRequest; // VIOLATION! gRPC wire type in Domain
use Grpc\ReleaseNotifier\V1\GetLatestReleaseReply;   // VIOLATION!
use Psr\Http\Message\ResponseInterface;              // VIOLATION! HTTP type in Domain

final class LatestReleaseBefore
{
    public function __construct(private ReleaseSource $source)
    {
    }

    // VIOLATION! Domain method shaped around the gRPC contract
    public function handle(GetLatestReleaseRequest $request): GetLatestReleaseReply
    {
        $release = $this->source->getLatestRelease(new RepositoryName($request->getRepository()));

        $reply = new GetLatestReleaseReply();
        if ($release !== null) {
            $reply->setTagName((string) $release->tagName);
        }

        return $reply;
    }
}

// ============================================================================
// AFTER (CORRECT) — Domain stays pure: a port + anemic VO snapshot
// ============================================================================

namespace Example\Releases\Sourcing\Domain;

use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Domain port — the only contract Domain/Application know about. The REAL port
 * is src/Releases/Sourcing/Domain/ReleaseSource.php.
 *
 * @psalm-api
 */
interface ReleaseSource
{
    public function repositoryExists(RepositoryName $repository): bool;

    public function getLatestRelease(RepositoryName $repository): ?Release;
}

namespace Example\Releases\Sourcing\Domain;

/**
 * Anemic, readonly VO snapshot — no identity, no lifecycle, no transport. This
 * is the REAL shape of src/Releases/Sourcing/Domain/Release.php.
 *
 * @psalm-api
 */
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
}

// ============================================================================
// APPLICATION — CQRS Query + Handler + Response (no transport types)
// ============================================================================

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class FetchLatestReleaseQuery implements Query
{
    public function __construct(public string $repository)
    {
    }
}

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class FetchLatestReleaseResponse implements Response
{
    public function __construct(public ?Release $release)
    {
    }
}

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * @implements QueryHandler<FetchLatestReleaseQuery, FetchLatestReleaseResponse>
 *
 * @psalm-api
 */
final readonly class FetchLatestReleaseHandler implements QueryHandler
{
    public function __construct(private ReleaseSource $source)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        /** @var FetchLatestReleaseQuery $query */
        return new FetchLatestReleaseResponse(
            $this->source->getLatestRelease(new RepositoryName($query->repository))
        );
    }
}

// ============================================================================
// INFRASTRUCTURE — OPTION 1: RoadRunner gRPC handler (transport stays here)
//
// gRPC method names are generated from proto/release_notifier.proto and keep
// their exact casing — that is the public contract.
// ============================================================================

namespace Example\Grpc;

use App\Shared\Domain\Bus\Query\QueryBus;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseQuery;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseResponse;
use Grpc\ReleaseNotifier\V1\GetLatestReleaseReply;
use Grpc\ReleaseNotifier\V1\GetLatestReleaseRequest;
use Spiral\RoadRunner\GRPC\ContextInterface;

/** @psalm-api */
final readonly class ReleaseNotifierService
{
    public function __construct(private QueryBus $queryBus)
    {
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function GetLatestRelease(ContextInterface $ctx, GetLatestReleaseRequest $request): GetLatestReleaseReply
    {
        // Map the wire type → Query, dispatch, map the typed Response → wire reply.
        /** @var FetchLatestReleaseResponse $response */
        $response = $this->queryBus->ask(
            new FetchLatestReleaseQuery($request->getRepository())
        );

        $reply = new GetLatestReleaseReply();
        if ($response->release !== null) {
            $reply->setTagName((string) $response->release->tagName);
            $reply->setName($response->release->name);
            $reply->setHtmlUrl($response->release->htmlUrl);
        }

        return $reply;
    }
    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}

// ============================================================================
// INFRASTRUCTURE — OPTION 2: Slim 4 REST controller (transport stays here)
// ============================================================================

namespace Example\Releases\Sourcing\Infrastructure\Http;

use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseQuery;
use App\Releases\Sourcing\Application\FetchLatestRelease\FetchLatestReleaseResponse;
use App\Shared\Domain\Bus\Query\QueryBus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class LatestReleaseController
{
    public function __construct(private QueryBus $queryBus)
    {
    }

    /** @param array{repository: string} $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var FetchLatestReleaseResponse $result */
        $result = $this->queryBus->ask(new FetchLatestReleaseQuery($args['repository']));

        // The exact JSON shape is the public contract (asserted by Behat).
        $payload = $result->release === null ? null : [
            'tag_name' => $result->release->tagName,
            'name' => $result->release->name,
            'html_url' => $result->release->htmlUrl,
            'published_at' => $result->release->publishedAt,
            'body' => $result->release->body,
        ];

        $response->getBody()->write((string) json_encode(['release' => $payload]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

// ============================================================================
// KEY DIFFERENCES:
//
// OPTION 1 (gRPC handler):
// - Lives in src/Grpc/ (Legacy.Infrastructure) — transport boundary
// - Maps GetLatestReleaseRequest → Query, Response → GetLatestReleaseReply
//
// OPTION 2 (Slim controller):
// - Lives in the context's Infrastructure/Http
// - Maps the route + request → Query, Response → JSON
//
// BOTH OPTIONS:
// - Domain has zero transport imports (no PSR-7, no Grpc\…, no API Platform)
// - Application uses the in-house Query/QueryBus; Domain owns the port + VO
// - The wire format (JSON / gRPC reply / Behat) is preserved exactly
// - Deptrac reports Violations 0
// ============================================================================
