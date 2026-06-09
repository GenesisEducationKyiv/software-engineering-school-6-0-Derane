<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * The gRPC status-code table as a backed enum: case names are the canonical
 * wire names, values mirror {@see StatusCode} so Spiral stays the source of
 * truth for the ints.
 */
enum GrpcStatus: int
{
    case OK = StatusCode::OK;
    case CANCELLED = StatusCode::CANCELLED;
    case UNKNOWN = StatusCode::UNKNOWN;
    case INVALID_ARGUMENT = StatusCode::INVALID_ARGUMENT;
    case DEADLINE_EXCEEDED = StatusCode::DEADLINE_EXCEEDED;
    case NOT_FOUND = StatusCode::NOT_FOUND;
    case ALREADY_EXISTS = StatusCode::ALREADY_EXISTS;
    case PERMISSION_DENIED = StatusCode::PERMISSION_DENIED;
    case RESOURCE_EXHAUSTED = StatusCode::RESOURCE_EXHAUSTED;
    case FAILED_PRECONDITION = StatusCode::FAILED_PRECONDITION;
    case ABORTED = StatusCode::ABORTED;
    case OUT_OF_RANGE = StatusCode::OUT_OF_RANGE;
    case UNIMPLEMENTED = StatusCode::UNIMPLEMENTED;
    case INTERNAL = StatusCode::INTERNAL;
    case UNAVAILABLE = StatusCode::UNAVAILABLE;
    case DATA_LOSS = StatusCode::DATA_LOSS;
    case UNAUTHENTICATED = StatusCode::UNAUTHENTICATED;
}
