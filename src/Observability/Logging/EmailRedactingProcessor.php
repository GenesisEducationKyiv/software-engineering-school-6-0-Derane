<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Central PII redaction for every record that flows through the Monolog logger:
 * masks email addresses in the message and in all (possibly nested) context and
 * extra string values via {@see EmailMasker}. Keeping redaction here — not in
 * individual sinks — means generic error paths ({@see \App\Middleware\ErrorHandlerMiddleware},
 * {@see \App\Observability\Metrics\MeasuredInvoker}) get the same protection as the
 * structured event logs, so PII leakage no longer depends on the call site.
 *
 * @psalm-api
 */
final readonly class EmailRedactingProcessor implements ProcessorInterface
{
    public function __construct(private EmailMasker $emailMasker)
    {
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->emailMasker->maskEmailsIn($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        return array_map(
            fn(mixed $value): mixed => match (true) {
                is_string($value) => $this->emailMasker->maskEmailsIn($value),
                is_array($value) => $this->redact($value),
                default => $value,
            },
            $data
        );
    }
}
