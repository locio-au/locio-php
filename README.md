# locio/locio

**Australian address validation, autocomplete and geocoding for PHP and
Laravel**, from G-NAF, the national address register. Turn whatever somebody
typed into one address with a stable id, a coordinate, an ABS mesh block and
the components a form has boxes for.

- PHP 8.2 and up, no runtime dependencies
- Laravel: auto discovered, with a facade and a validation rule
- Any HTTP stack: the bundled cURL transport, or your own
- Tells a spent quota apart from a rate limit, so you know whether to wait or
  to upgrade

```sh
composer require locio/locio
```

Get a key at [locio.com.au](https://locio.com.au/account/api).

## Quick start

```php
$locio = new Locio\Client(getenv('LOCIO_KEY'), country: 'AU');

$res = $locio->resolve('1 george st sydney nsw 2000');
if ($res->matched) {
    echo $res->address->id, ' ', $res->address->formatted, ' ',
         $res->address->lat, ',', $res->address->lng, ' ', $res->address->meshBlock;
}
```

Write the address however you hold it. Abbreviations, missing commas and a
missing state are all fine.

## The calls

| Call | Answers | Units |
|---|---|---|
| `search($term, limit: 8)` | candidates for what somebody is typing: autocomplete | 1 |
| `resolve($address)` | one address, or `matched` false: validation and geocoding | 1 |
| `similar($address, limit: 3)` | near misses for an address that did not resolve | 3 |
| `get($id)` | the address you stored, read back; `null` once it has retired | 1 |

`searchScoped` and `similarScoped` return the same addresses with the country
the service searched and, when a country is not covered, a `note` saying so.

`matched` false is an answer, not an error: the address as written is not in
the register. Try `similar` once when a typo is likely.

## Laravel

The service provider is discovered on install. Add the key to `.env`:

```dotenv
LOCIO_KEY=lc_live_...
LOCIO_COUNTRY=AU
```

Then inject `Locio\Client`, or use the facade:

```php
use Locio\Laravel\Locio;

$candidates = Locio::search('1 george st', limit: 5);
```

### Validating an address field

```php
use Locio\Laravel\ResolvableAddress;

$request->validate([
    'address' => ['required', 'string', $rule = new ResolvableAddress()],
]);

$order->address_id = $rule->address?->id;   // store the id, not the text
```

One unit per value checked. When the service cannot answer, because of a
timeout, a rate limit or a spent quota, the address **passes** and
`$rule->error` says why: an outage of ours should not stop somebody checking
out. `new ResolvableAddress(strict: true)` fails instead.

Publish the config with `php artisan vendor:publish --tag=locio-config`.

## Which id to store

Store `$address->id` beside `$address->countryCode`. In Australia `id` is the
G-NAF Address Detail PID, stable across quarterly releases for an address that
has not changed. `isUnit()` says when `gnaf['primary_pid']` names the parcel a
unit sits on, which is the building rather than the door.

G-NAF retires ids between releases, so `get` answers `null` for one that has
gone rather than throwing.

## Quota and rate limits

```php
use Locio\Exception\QuotaExhausted;
use Locio\Exception\RateLimited;

try {
    $locio->search($term);
} catch (RateLimited $e) {
    // Too many a second. Wait $e->retryAfter seconds.
} catch (QuotaExhausted $e) {
    // Every unit in the period is spent until $e->reset.
}
```

`$locio->quota()` holds what the most recent answer said: `limit`, `remaining`
and `reset`. A queue worker can read it to slow down before the quota runs out.

Every exception is a `Locio\Exception\LocioException` carrying the service's
own `title` and `detail`. The others are `Unauthorized` (401, 403), `NotFound`,
`BadRequest` and `TransportException` (no answer arrived).

## Your own HTTP stack

Implement `Locio\Http\Transport`, one method, and pass it in:

```php
$locio = new Locio\Client($key, transport: new MyGuzzleTransport($guzzle));
```

A transport must not follow redirects, since a redirect would carry the
`Authorization` header to wherever it points. In Laravel, bind
`Locio\Http\Transport` in the container and the provider uses it.

## Keys

This package takes a **secret key** (`lc_live_...`), which belongs on a server.
Never render it into a page: anyone who can read the page can spend your quota.
For a browser autocomplete use a public key (`lc_pub_...`) with
[`@locio-au/react`](https://www.npmjs.com/package/@locio-au/react) or
[`@locio-au/vue`](https://www.npmjs.com/package/@locio-au/vue).

The client sends the key only over https (or plain http to localhost, for a test
server), and keeps it out of `var_dump`, `print_r`, stack traces and exception
messages.

## The other packages

| | |
|---|---|
| [`@locio-au/client`](https://www.npmjs.com/package/@locio-au/client) | JavaScript and TypeScript |
| [`@locio-au/react`](https://www.npmjs.com/package/@locio-au/react) | React address autocomplete |
| [`@locio-au/vue`](https://www.npmjs.com/package/@locio-au/vue) | Vue address autocomplete |
| [`locio`](https://pypi.org/project/locio/) | Python |
| [`locio-go`](https://github.com/locio-au/locio-go) | Go |

API reference: [locio.com.au/docs](https://locio.com.au/docs/).

## Licence

MIT. Address data is G-NAF, published by Geoscape Australia under CC BY 4.0;
attribution belongs wherever you show it. See
[locio.com.au/legal](https://locio.com.au/legal/).
