<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Domain;

use App\Saga\Enrollment\Domain\SagaState;
use PHPUnit\Framework\TestCase;

final class SagaStateTest extends TestCase
{
    public function testHasExactlyTheFiveLifecycleCases(): void
    {
        $values = array_map(static fn (SagaState $s): string => $s->value, SagaState::cases());

        $this->assertSame(
            ['started', 'awaiting_confirmation', 'completed', 'compensating', 'compensated'],
            $values
        );
    }

    public function testBackingValuesAreStable(): void
    {
        $this->assertSame('started', SagaState::Started->value);
        $this->assertSame('awaiting_confirmation', SagaState::AwaitingConfirmation->value);
        $this->assertSame('completed', SagaState::Completed->value);
        $this->assertSame('compensating', SagaState::Compensating->value);
        $this->assertSame('compensated', SagaState::Compensated->value);
    }

    public function testIsTerminalOnlyForCompletedAndCompensated(): void
    {
        $this->assertTrue(SagaState::Completed->isTerminal());
        $this->assertTrue(SagaState::Compensated->isTerminal());
        $this->assertFalse(SagaState::Started->isTerminal());
        $this->assertFalse(SagaState::AwaitingConfirmation->isTerminal());
        $this->assertFalse(SagaState::Compensating->isTerminal());
    }

    public function testStartedAndAwaitingDistinguishThePublishStateMarker(): void
    {
        // Started = not yet published; AwaitingConfirmation = published + confirmed.
        $this->assertFalse(SagaState::Started->isPublished());
        $this->assertTrue(SagaState::AwaitingConfirmation->isPublished());
    }
}
