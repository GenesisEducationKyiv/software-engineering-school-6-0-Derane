<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Grpc;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeAlreadyFailedException;
use App\Sending\Infrastructure\Error\ExceptionStatusMap;
use App\Sending\Infrastructure\Http\WelcomeEmailFactory;
use Notification\Welcome\V1\Outcome;
use Notification\Welcome\V1\SendWelcomeEmailRequest;
use Notification\Welcome\V1\SendWelcomeEmailResponse;
use Notification\Welcome\V1\WelcomeEmailServiceInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\ServiceException;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * gRPC unary server for the welcome-email send (FR4), a net-new transport adapter
 * over the UNCHANGED {@see SendWelcomeEmailHandler} (FR5/N3). Modeled on the
 * monolith's {@see \App\Grpc\ReleaseNotifierService}.
 *
 * Outcome derivation lives in the adapter, not the handler, with this EXACT catch
 * order (load-bearing, RD6 — {@see WelcomeAlreadyFailedException} and
 * {@see \App\Sending\Application\WelcomeInFlightException} both extend \RuntimeException):
 *  1. handler returns normally (sent / dedup AlreadySent / fenced-superseded)
 *       → OUTCOME_SENT.
 *  2. WelcomeAlreadyFailedException → OUTCOME_FAILED as a NORMAL OK response (drives
 *       the saga compensate) — NOT an exception.
 *  3. anything else → {@see mapException()} via {@see ExceptionStatusMap::toGrpcStatus()}:
 *       WelcomeInFlightException → ABORTED (benign contention), validation →
 *       INVALID_ARGUMENT, transient (RuntimeException/PDOException) → UNAVAILABLE,
 *       otherwise → INTERNAL.
 */
final readonly class WelcomeEmailGrpcService implements WelcomeEmailServiceInterface
{
    // gRPC method names are generated from the proto contract and must keep exact casing.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function __construct(
        private SendWelcomeEmailHandler $handler,
        private WelcomeEmailFactory $factory,
        private ExceptionStatusMap $statusMap,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function SendWelcomeEmail(ContextInterface $ctx, SendWelcomeEmailRequest $in): SendWelcomeEmailResponse
    {
        try {
            $email = $this->factory->fromGrpc($in);
            $this->handler->handle($email);

            return new SendWelcomeEmailResponse(['outcome' => Outcome::OUTCOME_SENT]);
        } catch (WelcomeAlreadyFailedException $e) {
            return new SendWelcomeEmailResponse([
                'outcome' => Outcome::OUTCOME_FAILED,
                'error_detail' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }
    }

    private function mapException(\Throwable $e): GRPCException
    {
        /** @var 0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16 $code */
        $code = $this->statusMap->toGrpcStatus($e);
        $message = $this->statusMap->toClientMessage($e);

        if ($code === StatusCode::INTERNAL) {
            $this->logger->error('Welcome gRPC send failed', ['error' => $e->getMessage(), 'exception' => $e::class]);

            return ServiceException::create($message, $code, $e);
        }

        return GRPCException::create($message, $code, $e);
    }
    // phpcs:enable
}
