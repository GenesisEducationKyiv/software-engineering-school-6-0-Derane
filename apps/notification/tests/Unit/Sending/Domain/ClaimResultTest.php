<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Domain;

use App\Sending\Domain\ClaimOutcome;
use App\Sending\Domain\ClaimResult;
use PHPUnit\Framework\TestCase;

final class ClaimResultTest extends TestCase
{
    public function testClaimedCarriesTheFencingToken(): void
    {
        $result = ClaimResult::claimed('fence-token');

        self::assertSame(ClaimOutcome::Claimed, $result->outcome);
        self::assertSame('fence-token', $result->token());
    }

    public function testAlreadySentIsTokenLess(): void
    {
        $result = ClaimResult::alreadySent();

        self::assertSame(ClaimOutcome::AlreadySent, $result->outcome);
        $this->expectException(\LogicException::class);
        $result->token();
    }

    public function testInFlightIsTokenLess(): void
    {
        $result = ClaimResult::inFlight();

        self::assertSame(ClaimOutcome::InFlight, $result->outcome);
        $this->expectException(\LogicException::class);
        $result->token();
    }

    public function testAlreadyFailedIsTokenLess(): void
    {
        $result = ClaimResult::alreadyFailed();

        self::assertSame(ClaimOutcome::AlreadyFailed, $result->outcome);
        $this->expectException(\LogicException::class);
        $result->token();
    }
}
