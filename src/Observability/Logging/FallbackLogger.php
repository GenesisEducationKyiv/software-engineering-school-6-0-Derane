<?php

declare(strict_types=1);

namespace App\Observability\Logging;

/**
 * Last-resort sink for observability failures (a listener, or the listener
 * provider, that threw). Writes a single JSON line straight to `php://stderr`,
 * bypassing Monolog and the event listeners — which is what failed in the first
 * place. It never throws.
 *
 * The shape mirrors a Monolog record (`extra.component`/`extra.env`, fields under
 * `context`) on purpose: Filebeat keeps only events that carry `extra.component`,
 * so a top-level `component` would be silently dropped before reaching Kibana —
 * losing exactly the logs that signal observability is broken.
 *
 * @psalm-api
 */
final readonly class FallbackLogger
{
    public function __construct(
        private string $component,
        private string $env,
        private EmailMasker $emailMasker
    ) {
    }

    public function listenerFailed(string $listenerEventClass, \Throwable $error): void
    {
        @file_put_contents('php://stderr', $this->format($listenerEventClass, $error) . "\n");
    }

    public function format(string $listenerEventClass, \Throwable $error): string
    {
        $record = [
            'message' => 'observability.listener_failed',
            'level_name' => 'ERROR',
            'channel' => $this->component,
            'context' => [
                'event' => 'observability.listener_failed',
                'listener_event_class' => $listenerEventClass,
                'error_class' => $error::class,
                'error' => $this->emailMasker->maskEmailsIn($error->getMessage()),
            ],
            'extra' => [
                'component' => $this->component,
                'env' => $this->env,
            ],
        ];

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false
            ? '{"message":"observability.listener_failed","extra":{"component":"observability"}}'
            : $json;
    }
}
