<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Self-contained: extends the SPL \InvalidArgumentException with zero dependency
 * on Shared\Infrastructure\Error\*, keeping the Domain layer free of outer-layer coupling.
 */
final class InvalidArgumentException extends \InvalidArgumentException
{
}
