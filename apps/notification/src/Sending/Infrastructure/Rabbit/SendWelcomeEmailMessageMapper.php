<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\EmailAddress;
use App\Sending\Domain\RepositoryName;
use App\Sending\Domain\WelcomeEmail;

/**
 * Maps a `SendWelcomeEmail/v1` wire-format JSON body to a {@see WelcomeEmail}.
 *
 * Anti-corruption boundary: untrusted bytes where "malformed" is an expected
 * outcome the consumer routes to the DLQ. Unknown fields (e.g. `occurredAt`,
 * future additions) are tolerated — only the required fields are read.
 *
 * Wire-format mapping (arch §7 / contracts/send-welcome-email.v1.json):
 *
 * | WelcomeEmail property | wire JSON path  | required |
 * |-----------------------|-----------------|----------|
 * | sagaId                | sagaId          | yes      |
 * | subscriptionId        | subscriptionId  | yes      |
 * | recipientEmail        | email           | yes      |
 * | repository            | repository      | yes      |
 */
final readonly class SendWelcomeEmailMessageMapper
{
    private const EXPECTED_SCHEMA = 'SendWelcomeEmail/v1';

    private JsonMessageReader $reader;

    public function __construct()
    {
        $this->reader = new JsonMessageReader(
            self::EXPECTED_SCHEMA,
            static fn (string $message, ?\Throwable $previous): \RuntimeException
                => new MalformedWelcomeEmailMessageException($message, previous: $previous),
        );
    }

    public function fromJson(string $json): WelcomeEmail
    {
        $payload = $this->reader->decode($json);

        $schema = $this->reader->requireString($payload, 'schema');
        if ($schema !== self::EXPECTED_SCHEMA) {
            throw new MalformedWelcomeEmailMessageException(sprintf(
                'SendWelcomeEmail/v1 message has unknown schema "%s"; expected "%s".',
                $schema,
                self::EXPECTED_SCHEMA,
            ));
        }

        // Extract every primitive first (these throw and propagate), so the only
        // code inside the value-object try below is VO construction — a future bug
        // elsewhere can never be silently reclassified as a poison message.
        $sagaId = $this->reader->requireString($payload, 'sagaId');
        $subscriptionId = $this->reader->requireInt($payload, 'subscriptionId');
        $email = $this->reader->requireString($payload, 'email');
        $repository = $this->reader->requireString($payload, 'repository');

        // A self-validating VO rejecting a present-but-invalid value (bad email,
        // malformed repo) is just another flavour of poison message — translate
        // ONLY that to MalformedWelcomeEmailMessageException.
        try {
            $recipientEmail = new EmailAddress($email);
            $repositoryName = new RepositoryName($repository);
        } catch (\InvalidArgumentException $e) {
            throw new MalformedWelcomeEmailMessageException(
                'SendWelcomeEmail/v1 message has an invalid field: ' . $e->getMessage(),
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
}
