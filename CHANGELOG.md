# Changelog

## 0.1.0

First release.

- `search`, `searchScoped`, `resolve`, `similar`, `similarScoped` and `get`,
  covering the Locio address API, with `Address` and `Resolution` as typed
  objects that keep the raw record.
- Exceptions that tell a spent quota (`QuotaExhausted`, 402, with `reset`) from
  a rate limit (`RateLimited`, 429, with `retryAfter`). `quota()` reports the
  allowance from the most recent answer.
- Laravel: an auto discovered service provider, a `Locio` facade and a
  `ResolvableAddress` validation rule.
- A `Transport` interface, so WordPress, Guzzle or any PSR-18 client can carry
  the requests. The bundled `CurlTransport` never follows redirects and stops
  reading at 8 MiB.
- The key never appears in a dump, a stack trace or an exception message.
- No runtime dependencies.
