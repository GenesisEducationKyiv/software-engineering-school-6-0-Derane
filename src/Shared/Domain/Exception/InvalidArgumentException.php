<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Domain-layer invalid-argument exception for the Shared kernel.
 *
 * Self-contained: extends the SPL \InvalidArgumentException and has zero
 * dependency on App\Exception\* (the transport/HTTP-mapping namespace), so the
 * Shared Domain layer remains free of outer-layer coupling.
 */
final class InvalidArgumentException extends \InvalidArgumentException
{
}
