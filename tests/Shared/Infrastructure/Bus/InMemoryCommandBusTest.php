<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Command\CommandNotRegistered;
use App\Shared\Infrastructure\Bus\InMemoryCommandBus;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Infrastructure\Bus\Fixture\FakeCommand;
use Tests\Shared\Infrastructure\Bus\Fixture\FakeCommandHandler;
use Tests\Shared\Infrastructure\Bus\Fixture\UnboundCommand;

final class InMemoryCommandBusTest extends TestCase
{
    public function testDispatchesToTheSingleBoundHandler(): void
    {
        $handler = new FakeCommandHandler();
        $bus = new InMemoryCommandBus([FakeCommand::class => $handler]);

        $command = new FakeCommand();
        $bus->dispatch($command);

        self::assertTrue($handler->handled);
        self::assertSame($command, $handler->received);
    }

    public function testUnboundCommandThrowsCommandNotRegisteredNamingTheCommand(): void
    {
        $bus = new InMemoryCommandBus([]);

        $this->expectException(CommandNotRegistered::class);
        $this->expectExceptionMessage(UnboundCommand::class);

        $bus->dispatch(new UnboundCommand());
    }
}
