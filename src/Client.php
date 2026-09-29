<?php

declare(strict_types=1);

namespace Locio;

use DateTimeImmutable;
use InvalidArgumentException;
use Locio\Exception\BadRequest;
use Locio\Exception\LocioException;
use Locio\Exception\NotFound;
use Locio\Exception\QuotaExhausted;
use Locio\Exception\RateLimited;
use Locio\Exception\Unauthorized;
use Locio\Http\CurlTransport;
use Locio\Http\Response;
use Locio\Http\Transport;

/**
 * A client for the Locio address API: G-NAF, as autocomplete, validation and
 * geocoding.
 *
 *     $locio = new Locio\Client(getenv('LOCIO_KEY'), country: 'AU');
 *     $res = $locio->resolve('1 george st sydney nsw 2000');
 *
 * Takes a secret key (lc_live_...), which belongs on a server. Make one client
 * and keep it.
 *
 * Addresses come from G-NAF, © Geoscape Australia, under CC BY 4.0. Publishing
 * what you get back means carrying that credit: locio.com.au/legal/.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.locio.com.au';

    private readonly string $key;
    private readonly string $baseUrl;
    private readonly ?string $country;
    private readonly Transport $transport;
    private ?Quota $quota = null;

    /**
     * @param string      $key       a secret key (lc_live_...) from locio.com.au/account/api
     * @param string|null $country   which country's addresses to search, as a two letter ISO
     *                               3166-1 code. A request from your server says nothing about
     *                               where the address is, so say. Null sends nothing, and the
     *                               service's default applies, which is AU.
     * @param string      $baseUrl   a proxy of your own, or a test server. https, or http to loopback.
     * @param Transport|null $transport your own HTTP stack. Defaults to CurlTransport.
     */
    public function __construct(
        #[\SensitiveParameter] string $key,
        ?string $country = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?Transport $transport = null,
    ) {
        $this->key = self::checkKey($key);
        $this->baseUrl = self::checkBaseUrl($baseUrl);

        if ($country !== null) {
            $code = strtoupper(trim($country));
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                throw new InvalidArgumentException("locio: country $country is not a two letter ISO 3166-1 code");
            }
            $country = $code;
        }
        $this->country = $country;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * The allowance as the most recent answer reported it: units in the
     * period, units left, and when it resets. Null until a metered call answers.
     */
    public function quota(): ?Quota
    {
        return $this->quota;
    }

    /**
     * Candidate addresses for what somebody has typed: address autocomplete.
     *
     * One unit a call. An empty term answers an empty list without a request.
     * A limit of 0 takes the service's default.
     *
     * @return list<Address>
     */
    public function search(string $term, int $limit = 0): array
    {
        return $this->searchScoped($term, $limit)->addresses;
    }

    /** The same call, with the country it searched and any note about it. */
    public function searchScoped(string $term, int $limit = 0): ScopedAddresses
    {
        return $this->list('/v1/addresses', $term, $limit);
    }

    /**
     * One address by its id: the $id of a record you stored, which in
     * Australia is the G-NAF Address Detail PID.
     *
     * Null when the id is not in the current release. G-NAF retires ids
     * between quarterly releases, so code walking stored ids will meet this
     * and should tell it apart from an outage.
     */
    public function get(string $id): ?Address
    {
        $text = trim($id);
        // rawurlencode leaves dots alone, and "." or ".." as a path segment is
        // the collection or its parent, not an address.
        if ($text === '' || $text === '.' || $text === '..') {
            throw new InvalidArgumentException('locio: ' . json_encode($id) . ' is not an address id');
        }

        try {
            $body = $this->call('/v1/addresses/' . rawurlencode($text), []);
        } catch (NotFound) {
            return null;
        }

        return is_array($body['data'] ?? null) ? Address::fromArray($body['data']) : null;
    }

    /**
     * A whole address string to one record: address validation, geocoding and
     * parsing in the same answer. Write the address however you hold it.
     */
    public function resolve(string $address): Resolution
    {
        $body = $this->call('/v1/addresses/resolve', ['q' => $address]);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return new Resolution(
            ($data['matched'] ?? false) === true,
            is_array($data['address'] ?? null) ? Address::fromArray($data['address']) : null,
        );
    }

    /**
     * Near misses for an address that did not resolve: "1 gorge rd sydenhum"
     * is one address, and this says which. Three units, so call it once on a
     * failed resolve, never on a keystroke.
     *
     * @return list<Address>
     */
    public function similar(string $address, int $limit = 0): array
    {
        return $this->similarScoped($address, $limit)->addresses;
    }

    /** The same call, with the country it searched and any note about it. */
    public function similarScoped(string $address, int $limit = 0): ScopedAddresses
    {
        return $this->list('/v1/addresses/similar', $address, $limit);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        // Never the key: a dumped client ends up in logs and error trackers.
        return ['baseUrl' => $this->baseUrl, 'country' => $this->country, 'quota' => $this->quota];
    }

    private function list(string $path, string $term, int $limit): ScopedAddresses
    {
        $q = trim($term);
        if ($q === '') {
            return new ScopedAddresses([]);
        }
        if ($limit < 0) {
            throw new InvalidArgumentException("locio: limit must be a whole number, not $limit");
        }

        $body = $this->call($path, ['q' => $q, 'limit' => $limit > 0 ? (string) $limit : null]);
        $addresses = [];
        foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $row) {
            if (is_array($row)) {
                $addresses[] = Address::fromArray($row);
            }
        }

        return new ScopedAddresses(
            $addresses,
            is_string($body['country_code'] ?? null) ? $body['country_code'] : null,
            is_string($body['note'] ?? null) ? $body['note'] : null,
        );
    }

    /**
     * @param array<string, string|null> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $path, array $params): array
    {
        $params = array_filter($params, static fn (?string $v): bool => $v !== null && $v !== '');
        // Every address call is scoped to one country.
        if ($this->country !== null) {
            $params['country'] = $this->country;
        }
        $url = $this->baseUrl . $path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');

        $response = $this->transport->get($url, [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'locio-php/' . self::VERSION,
        ]);

        $quota = self::readQuota($response);
        if ($quota !== null) {
            $this->quota = $quota;
        }

        // A problem document is JSON; a proxy having a bad day is not. Either
        // way the status is the part a caller can act on, so parsing never
        // decides whether an error is raised.
        $decoded = json_decode($response->body, true);
        $body = is_array($decoded) && !array_is_list($decoded) ? $decoded : [];

        if ($response->status < 200 || $response->status >= 300) {
            throw self::errorFor($response, $body, $quota);
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function errorFor(Response $response, array $body, ?Quota $quota): LocioException
    {
        $status = $response->status;
        $title = is_string($body['title'] ?? null) ? $body['title'] : '';
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : '';

        return match (true) {
            $status === 401, $status === 403 => new Unauthorized($status, $title, $detail),
            $status === 402 => new QuotaExhausted($status, $title, $detail, $quota),
            $status === 404 => new NotFound($status, $title, $detail),
            $status === 429 => new RateLimited($status, $title, $detail, self::retryAfter($response)),
            $status >= 400 && $status < 500 => new BadRequest($status, $title, $detail),
            default => new LocioException($status, $title, $detail),
        };
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** The allowance from the X-Quota-* headers, or null when any is missing or malformed. */
    private static function readQuota(Response $response): ?Quota
    {
        $limit = $response->header('X-Quota-Limit');
        $remaining = $response->header('X-Quota-Remaining');
        $reset = $response->header('X-Quota-Reset');
        if ($limit === null || $remaining === null || $reset === null || !ctype_digit($limit) || !ctype_digit($remaining)) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat(DATE_RFC3339, $reset)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $reset, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }

        return new Quota((int) $limit, (int) $remaining, $at);
    }

    private static function checkKey(#[\SensitiveParameter] string $key): string
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException(
                'locio: no key. Create a secret key (lc_live_...) at https://locio.com.au/account/api',
            );
        }
        // A header value, so a newline in it would be a header the caller did
        // not write. Keys never contain whitespace, so any is a paste gone wrong.
        // The message never repeats the key: it ends up in logs.
        if (preg_match('/[\s\x00-\x1f\x7f]/', $key) === 1) {
            throw new InvalidArgumentException(
                'locio: the key contains whitespace or a control character; check how it was pasted',
            );
        }

        return $key;
    }

    /**
     * Refuse a base URL that would send the key somewhere it should not go:
     * https always, and plaintext http only to loopback.
     */
    private static function checkBaseUrl(string $raw): string
    {
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("locio: base URL $raw is not absolute");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                "locio: base URL $raw carries credentials, and the host it would reach is not the one it reads as",
            );
        }
        if (strpbrk($raw, '?#') !== false) {
            throw new InvalidArgumentException("locio: base URL $raw has a query or a fragment, which would swallow the path");
        }

        $scheme = strtolower($parts['scheme']);
        $loopback = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme === 'https' || ($scheme === 'http' && $loopback)) {
            return rtrim($raw, '/');
        }
        if ($scheme === 'http') {
            throw new InvalidArgumentException(
                "locio: base URL $raw is plaintext http to a public host, which would send the key in the clear",
            );
        }

        throw new InvalidArgumentException("locio: base URL $raw has scheme $scheme, want https");
    }
}
