<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface EmailRenderer
{
    public function render(ReleaseEmail $email): RenderedEmail;
}
