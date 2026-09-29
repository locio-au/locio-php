<?php

declare(strict_types=1);

namespace Locio;

/**
 * The answer to a validation call.
 *
 * $matched false is an answer, not an error: the address as written is not in
 * the register, which is exactly what a validation call is asking.
 */
final class Resolution
{
    public function __construct(
        public readonly bool $matched,
        public readonly ?Address $address,
    ) {
    }
}
