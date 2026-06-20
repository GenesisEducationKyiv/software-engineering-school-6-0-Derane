<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface EmailRenderer
{
    public function render(RenderableEmail $email): RenderedEmail;
}
