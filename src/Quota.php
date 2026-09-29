<?php

declare(strict_types=1);

namespace Locio;

use DateTimeImmutable;

/** The allowance a key's account has left, as the service reported it. */
final class Quota
{
    public function __construct(
        /** Units the plan allows in the period. */
        public readonly int $limit,
        /** Units left in the period. */
        public readonly int $remaining,
        /** When the period ends and the count starts again. */
        public readonly DateTimeImmutable $reset,
    ) {
    }
}
