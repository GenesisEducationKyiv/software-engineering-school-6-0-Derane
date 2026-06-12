<?php

declare(strict_types=1);

namespace Tests\Resilience;

use PHPUnit\Framework\TestCase;

final class ResilienceProofScriptTest extends TestCase
{
    public function testCiRunsScannerSmokeAndResilienceProof(): void
    {
        $makefile = (string) file_get_contents(__DIR__ . '/../../Makefile');
        $workflow = (string) file_get_contents(__DIR__ . '/../../.github/workflows/resilience-proof.yml');
        $ciTargetPattern = '/^ci:.*\n(?:\t.*\n)*\t\$\(MAKE\) scanner-smoke\n\t\$\(MAKE\) resilience-proof/m';

        self::assertMatchesRegularExpression($ciTargetPattern, $makefile);
        self::assertStringContainsString('pull_request:', $workflow);
        self::assertStringContainsString('RESILIENCE_RUN_ID: ci-${{ github.run_id }}-', $workflow);
        self::assertStringContainsString('make scanner-smoke', $workflow);
        self::assertStringContainsString('make resilience-proof', $workflow);
    }

    public function testNotificationImageUsesPurePhpAmqpClientOnly(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__ . '/../../apps/notification/Dockerfile');

        self::assertStringNotContainsString('rabbitmq-c-dev', $dockerfile);
        self::assertStringNotContainsString('pecl install amqp', $dockerfile);
        self::assertStringNotContainsString('docker-php-ext-enable amqp', $dockerfile);
        self::assertStringContainsString('docker-php-ext-install pdo_pgsql sockets pcntl', $dockerfile);
    }

    public function testOutageProofExercisesGrpcSubscriptionContract(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../bin/resilience-proof.sh');
        $seedScript = (string) file_get_contents(__DIR__ . '/../../bin/resilience-proof-seed.php');

        self::assertStringContainsString('GRPCURL_IMAGE', $script);
        self::assertStringNotContainsString('$RANDOM', $script);
        self::assertStringNotContainsString('date +%s', $script);
        self::assertStringNotContainsString('$$', $script);
        self::assertStringNotContainsString('random_bytes', $seedScript);
        self::assertStringContainsString('RESILIENCE_TOKEN is required', $seedScript);
        self::assertMatchesRegularExpression('/assert_grpc_subscription_alive\(\) \{/', $script);
        self::assertStringContainsString('release_notifier.v1.ReleaseNotifierService/CreateSubscription', $script);
        self::assertStringContainsString('deadline=$((SECONDS + 90))', $script);
        self::assertStringContainsString('max_attempts=15', $script);

        foreach (
            [
            'assert_grpc_subscription_alive "[rabbitmq DOWN]"',
            'assert_grpc_subscription_alive "[rabbitmq DOWN, post-scan]"',
            'assert_grpc_subscription_alive "[notification-svc DOWN]"',
            'assert_grpc_subscription_alive "[notification-svc DOWN, post-seed]"',
            ] as $callSite
        ) {
            self::assertStringContainsString($callSite, $script);
        }
    }
}
