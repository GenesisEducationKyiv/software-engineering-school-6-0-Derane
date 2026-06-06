<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Application\Register;

use App\RepositoryTracking\Repositories\Application\Register\RegisterRepositoryCommand;
use App\RepositoryTracking\Repositories\Application\Register\RegisterRepositoryCommandHandler;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RegisterRepositoryCommandHandlerTest extends TestCase
{
    private TrackedRepositoryRegistrar&MockObject $registrar;
    private RegisterRepositoryCommandHandler $handler;

    protected function setUp(): void
    {
        $this->registrar = $this->createMock(TrackedRepositoryRegistrar::class);
        $this->handler = new RegisterRepositoryCommandHandler($this->registrar);
    }

    public function testHandlerCallsEnsureExists(): void
    {
        $this->registrar->expects($this->once())
            ->method('ensureExists')
            ->with('golang/go');

        ($this->handler)(new RegisterRepositoryCommand('golang/go'));
    }
}
