<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Error;

use Fig\Http\Message\StatusCodeInterface;

final readonly class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof \RuntimeException,
            $e instanceof \PDOException => StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
            default => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
        };
    }

    public function toClientMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof \RuntimeException,
            $e instanceof \PDOException => 'Service unavailable',
            default => 'Internal server error',
        };
    }
}
