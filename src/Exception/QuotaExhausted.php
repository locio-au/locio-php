<?php

declare(strict_types=1);

namespace Locio\Exception;

use DateTimeImmutable;
use Locio\Quota;

/**
 * Every unit in the period is spent. Retrying before $reset gets the same
 * answer; a larger plan at locio.com.au/account/ is the other way through.
 */
final class QuotaExhausted extends LocioException
{
    /** When the allowance starts again. */
    public readonly ?DateTimeImmutable $reset;

    public function __construct(
        int $status,
        string $title = '',
        string $detail = '',
        /** The allowance as the service reported it with the refusal. */
        public readonly ?Quota $quota = null,
    ) {
        parent::__construct($status, $title, $detail);
        $this->reset = $quota?->reset;
    }
}
