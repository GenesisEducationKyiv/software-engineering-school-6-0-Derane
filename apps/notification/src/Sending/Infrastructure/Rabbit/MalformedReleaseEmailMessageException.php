<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * The dedicated, narrowly-typed "poison message" signal `SendReleaseEmailMessageMapper`
 * throws when a `SendReleaseEmail/v1` message body cannot be turned into a
 * `ReleaseEmail` — undecodable JSON, or valid JSON missing/wrong-typing one of
 * the seven required fields (Technical Decisions §2/§6).
 *
 * `SendReleaseEmailConsumer`'s first `catch` block matches this type
 * *specifically* — never a bare `\JsonException`/`\TypeError`/`\InvalidArgumentException`,
 * which could also leak out of an unrelated mapper defect and get
 * misclassified as "poison" when it is actually a bug. Catching this exact
 * type is what lets the consumer route straight to the DLQ (no
 * `shouldRouteToDlq` bound-check, ever) without risking false positives.
 *
 * `extends \RuntimeException`: the condition is a runtime data-quality problem
 * (untrusted external input), not a programming error (`\LogicException`) —
 * mirrors `RabbitPublishFailedException`'s choice of base for the same reason.
 */
final class MalformedReleaseEmailMessageException extends \RuntimeException
{
}
