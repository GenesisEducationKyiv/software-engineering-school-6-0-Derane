<?php

declare(strict_types=1);

namespace App\Observability\Logging;

/**
 * Masks email addresses before they reach a log sink, so raw subscriber PII is
 * not shipped to and indexed by Elasticsearch. Keeps the first local-part
 * character and the domain (`j***@example.com`) for coarse debuggability, and
 * falls back to a fully opaque value when the input is not a plausible address.
 *
 * @psalm-api
 */
final readonly class EmailMasker
{
    public function mask(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $domain = substr($email, $at + 1);
        if ($domain === '') {
            return '***';
        }

        return $email[0] . '***@' . $domain;
    }

    /**
     * Masks every email-like token inside a free-form string (e.g. an exception
     * message that embedded a recipient address), leaving the rest intact.
     */
    public function maskEmailsIn(string $text): string
    {
        $masked = preg_replace_callback(
            '/[^\s@]+@[^\s@]+\.[^\s@]+/',
            fn(array $m): string => $this->mask($m[0]),
            $text
        );

        return $masked ?? $text;
    }
}
