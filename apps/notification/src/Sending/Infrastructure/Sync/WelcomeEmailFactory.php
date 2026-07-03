<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Sync;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;
use App\Sending\Infrastructure\Error\WelcomeRequestValidationException;
use Notification\Welcome\V1\SendWelcomeEmailRequest;

/**
 * Builds the {@see WelcomeEmail} VO from a sync transport request, constructing it
 * identically to the async path so the handler receives the same VO regardless of transport.
 */
final readonly class WelcomeEmailFactory
{
    public function fromGrpc(SendWelcomeEmailRequest $request): WelcomeEmail
    {
        // int64 subscription_id is int|string from protobuf; the (int) cast won't truncate on 64-bit PHP.
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
