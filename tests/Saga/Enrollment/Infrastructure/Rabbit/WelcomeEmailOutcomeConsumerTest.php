<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeConsumer;
use App\Saga\Enrollment\Infrastructure\Rabbit\WelcomeEmailOutcomeMessageMapper;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The reply consumer's ack/nack + metric disposition is exercised against the
 * MessageConsumer port and CommandBus (mocked) with the real mapper. The reply
 * queue has no DLX (arch §7): malformed -> ack-drop (no consumed count); well-formed
 * -> consumed count + dispatch + ack; environmental throw -> nack(requeue:true).
 */
final class WelcomeEmailOutcomeConsumerTest extends TestCase
{
    private const SAGA_ID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    private MessageConsumer&MockObject $messageConsumer;
    private CommandBus&MockObject $commandBus;
    private SagaMetricsRecorder&MockObject $metrics;

    protected function setUp(): void
    {
        $this->messageConsumer = $this->createMock(MessageConsumer::class);
        $this->commandBus = $this->createMock(CommandBus::class);
        $this->metrics = $this->createMock(SagaMetricsRecorder::class);
    }

    public function testWellFormedReplyCountsConsumedDispatchesThenAcks(): void
    {
        $message = $this->message($this->body('sent'));

        $this->metrics->expects(self::once())->method('recordWelcomeReplyConsumed');
        $this->commandBus->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(HandleWelcomeEmailOutcomeCommand::class));
        $this->messageConsumer->expects(self::once())->method('ack')->with($message);
        $this->messageConsumer->expects(self::never())->method('nack');

        $this->consumer()->handleDelivery($message);
    }

    public function testMalformedReplyIsAckedAndDroppedWithoutCountingConsumed(): void
    {
        $message = $this->message('{not json');

        $this->metrics->expects(self::never())->method('recordWelcomeReplyConsumed');
        $this->commandBus->expects(self::never())->method('dispatch');
        $this->messageConsumer->expects(self::once())->method('ack')->with($message);
        $this->messageConsumer->expects(self::never())->method('nack');

        $this->consumer()->handleDelivery($message);
    }

    public function testUnknownSchemaIsAckedAndDropped(): void
    {
        $message = $this->message(json_encode([
            'schema' => 'WelcomeEmailOutcome/v2',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => 'sent',
        ], JSON_THROW_ON_ERROR));

        $this->metrics->expects(self::never())->method('recordWelcomeReplyConsumed');
        $this->messageConsumer->expects(self::once())->method('ack')->with($message);

        $this->consumer()->handleDelivery($message);
    }

    public function testEnvironmentalDispatchFailureLeavesTheReplyUnackedForRedelivery(): void
    {
        $message = $this->message($this->body('sent'));

        // consumed still counts (the reply was well-formed and dispatched).
        $this->metrics->expects(self::once())->method('recordWelcomeReplyConsumed');
        $this->commandBus->method('dispatch')->willThrowException(new \RuntimeException('db down'));
        $this->messageConsumer->expects(self::never())->method('ack');
        $this->messageConsumer->expects(self::once())->method('nack')->with($message, true);

        $this->consumer()->handleDelivery($message);
    }

    private function body(string $outcome): string
    {
        return json_encode([
            'schema' => 'WelcomeEmailOutcome/v1',
            'sagaId' => self::SAGA_ID,
            'subscriptionId' => 123,
            'outcome' => $outcome,
            'error' => null,
            'occurredAt' => '2026-06-20T12:00:03+00:00',
        ], JSON_THROW_ON_ERROR);
    }

    private function message(string $body): AMQPMessage
    {
        return new AMQPMessage($body);
    }

    private function consumer(): WelcomeEmailOutcomeConsumer
    {
        return new WelcomeEmailOutcomeConsumer(
            $this->messageConsumer,
            new WelcomeEmailOutcomeMessageMapper(),
            $this->commandBus,
            $this->metrics,
            new NullLogger(),
        );
    }
}
