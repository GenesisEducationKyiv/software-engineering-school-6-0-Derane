<?php

declare(strict_types=1);

namespace App\Exception;

final class RateLimitException extends \RuntimeException
{
    public function __construct(string $retryAfter = '')
    {
        $message = 'GitHub API rate limit exceeded.';
        if ($retryAfter !== '') {
            $message .= " Retry after: {$retryAfter}s";
        }
        parent::__construct($message);
    }
}
