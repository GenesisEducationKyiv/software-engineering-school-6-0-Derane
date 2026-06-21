<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Marker for an email-shaped Domain VO that an {@see EmailRenderer} can turn into
 * a {@see RenderedEmail}. Implemented by {@see ReleaseEmail} and {@see WelcomeEmail}
 * so a single renderer port can serve both message families without the welcome
 * path referencing the release VO (or vice versa). Each renderer narrows to the
 * concrete type it understands.
 */
interface RenderableEmail
{
}
