<?php

declare(strict_types=1);

namespace Locio\Exception;

/**
 * Too many requests a second. Slow down and try again: nothing is spent.
 *
 * A different problem from QuotaExhausted, with a different fix, which is why
 * the service answers 429 for one and 402 for the other.
 */
final class RateLimited extends LocioException
{
    public function __construct(
        int $status,
        string $title = '',
        string $detail = '',
        /** Seconds the service asked for, when it said. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($status, $title, $detail);
    }
}
