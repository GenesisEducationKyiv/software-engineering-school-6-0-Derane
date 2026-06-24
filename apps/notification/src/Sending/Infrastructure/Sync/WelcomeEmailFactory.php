<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Sync;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Infrastructure\Error\WelcomeRequestValidationException;
use Notification\Welcome\V1\SendWelcomeEmailRequest;

/**
 * Builds the {@see WelcomeEmail} VO from a sync transport request — a gRPC
 * {@see SendWelcomeEmailRequest} message or a REST JSON payload — constructing it
 * EXACTLY as {@see \App\Sending\Infrastructure\Rabbit\SendWelcomeEmailMessageMapper}
 * does on the async path, so the unchanged {@see \App\Sending\Application\SendWelcomeEmailHandler}
 * receives an identical VO regardless of transport (FR5).
 *
 * Lives in the transport-neutral Sync namespace (not under Http) because it is shared
 * by BOTH synchronous surfaces; neither the REST controller nor the gRPC service should
 * be subordinate to the other's namespace.
 *
 * VO construction IS the field validation: a malformed email/repository (or a bad
 * request shape on the REST path) is translated to a single
 * {@see WelcomeRequestValidationException} → gRPC INVALID_ARGUMENT / HTTP 400 (RD6).
 */
final readonly class WelcomeEmailFactory
{
    public function fromGrpc(SendWelcomeEmailRequest $request): WelcomeEmail
    {
        // int64 subscription_id is typed int|string by the protobuf runtime; the
        // ledger key is an int (RD10a — no truncation: PHP ints are 64-bit here).
        return $this->build(
            $request->getSagaId(),
            (int) $request->getSubscriptionId(),
            $request->getEmail(),
            $request->getRepository(),
        );
    }

    /** @param array<array-key,mixed> $payload */
    public function fromArray(array $payload): WelcomeEmail
    {
        return $this->build(
            $this->requireString($payload, 'sagaId'),
            $this->requireInt($payload, 'subscriptionId'),
            $this->requireString($payload, 'email'),
            $this->requireString($payload, 'repository'),
        );
    }

    private function build(string $sagaId, int $subscriptionId, string $email, string $repository): WelcomeEmail
    {
        try {
            $recipientEmail = new EmailAddress($email);
            $repositoryName = new RepositoryName($repository);
        } catch (\InvalidArgumentException $e) {
            throw new WelcomeRequestValidationException(
                'Welcome email request has an invalid field: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return new WelcomeEmail(
            sagaId: $sagaId,
            subscriptionId: $subscriptionId,
            recipientEmail: $recipientEmail,
            repository: $repositoryName,
        );
    }

    /** @param array<array-key,mixed> $payload */
    private function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new WelcomeRequestValidationException(
                sprintf('Welcome email request is missing required string field "%s".', $field),
            );
        }

        return $value;
    }

    /** @param array<array-key,mixed> $payload */
    private function requireInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value)) {
            throw new WelcomeRequestValidationException(
                sprintf('Welcome email request field "%s" must be an integer.', $field),
            );
        }

        return $value;
    }
}
