<?php

declare(strict_types=1);

namespace Locio;

/**
 * Addresses, and what the service said about the country it searched.
 *
 * A country the service holds no addresses for answers no results and a note
 * saying why, as an ordinary answer that costs nothing.
 */
final class ScopedAddresses
{
    /**
     * @param list<Address> $addresses
     */
    public function __construct(
        public readonly array $addresses,
        /** Which country was searched, as a two letter ISO 3166-1 code. */
        public readonly ?string $countryCode = null,
        /** Why the list is empty, when empty is the right answer. */
        public readonly ?string $note = null,
    ) {
    }
}
