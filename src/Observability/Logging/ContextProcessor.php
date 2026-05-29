<?php

declare(strict_types=1);

namespace App\Observability\Logging;

use App\Observability\CorrelationContextInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Enriches every log record with the fields needed for structured search and
 * aggregation in Kibana: the originating component (api|grpc|scanner), the
 * environment, and the current correlation id.
 *
 * @psalm-api
 */
final readonly class ContextProcessor implements ProcessorInterface
{
    public function __construct(
        private CorrelationContextInterface $correlation,
        private string $component,
        private string $env
    ) {
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['component'] = $this->component;
        $record->extra['env'] = $this->env;

        $correlationId = $this->correlation->id();
        if ($correlationId !== null) {
            $record->extra['correlation_id'] = $correlationId;
        }

        return $record;
    }
}
