<?php

declare(strict_types=1);

namespace Locio;

/**
 * One resolved address, from whichever register covers it.
 *
 * The G-NAF fields are Australian: another country's register publishes no
 * pid, mesh block or parcel, so they are null outside Australia and
 * $countryCode says which case you are in.
 *
 * Component and G-NAF values are carried as the register publishes them,
 * upper case included: codes to match on rather than prose to print.
 */
final class Address
{
    /**
     * @param array<string, string> $components the address split the way a form has boxes for it
     * @param array<string, string> $gnaf       the part of a G-NAF row that is not the address itself
     * @param array<string, mixed>  $raw        the record exactly as the API sent it
     */
    public function __construct(
        /**
         * The id to store against your own record, beside $countryCode. In
         * Australia, the G-NAF Address Detail PID: stable across releases for
         * an address that has not changed.
         */
        public readonly string $id,
        /** The address on one line, as an envelope would write it. */
        public readonly string $formatted,
        public readonly float $lat,
        public readonly float $lng,
        /** Which register this came from, as a two letter ISO 3166-1 code. Null means Australia. */
        public readonly ?string $countryCode = null,
        /** The G-NAF Address Detail PID, for an Australian address. */
        public readonly ?string $addressDetailPid = null,
        /** The ABS mesh block: the join key to census statistics. Australian addresses only. */
        public readonly ?string $meshBlock = null,
        public readonly array $components = [],
        public readonly array $gnaf = [],
        public readonly ?string $country = null,
        public readonly ?string $region = null,
        public readonly ?string $locality = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $string = static fn (string $key): ?string => isset($row[$key]) && is_scalar($row[$key]) && $row[$key] !== ''
            ? (string) $row[$key]
            : null;
        $strings = static function (string $key) use ($row): array {
            $out = [];
            foreach (is_array($row[$key] ?? null) ? $row[$key] : [] as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $out[$k] = (string) $v;
                }
            }

            return $out;
        };

        return new self(
            // Every address carries id; a service too old to send it carried
            // the Australian pid instead, under one name or the other.
            id: $string('id') ?? $string('address_detail_pid') ?? $string('gnaf_pid') ?? '',
            formatted: $string('formatted') ?? '',
            lat: is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : 0.0,
            lng: is_numeric($row['lng'] ?? null) ? (float) $row['lng'] : 0.0,
            countryCode: $string('country_code'),
            addressDetailPid: $string('address_detail_pid') ?? $string('gnaf_pid'),
            meshBlock: $string('mesh_block'),
            components: $strings('components'),
            gnaf: $strings('gnaf'),
            country: $string('country'),
            region: $string('region'),
            locality: $string('locality'),
            raw: $row,
        );
    }

    public function isAustralian(): bool
    {
        return $this->countryCode === null || $this->countryCode === 'AU';
    }

    /**
     * Whether this is a unit under a parcel, in which case gnaf['primary_pid']
     * names the parcel rather than this address. Storing that instead of $id
     * stores the building rather than the door.
     */
    public function isUnit(): bool
    {
        return strtoupper($this->gnaf['primary_secondary'] ?? '') === 'SECONDARY';
    }
}
