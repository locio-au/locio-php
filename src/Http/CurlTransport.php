<?php

declare(strict_types=1);

namespace Locio\Http;

use Locio\Exception\TransportException;

/**
 * The default transport, on ext-curl.
 *
 * Never follows a redirect, speaks only http and https, verifies TLS, and
 * stops reading at 8 MiB: a client should not be talked into reading an
 * unbounded body by whatever is on the other end of the socket.
 */
final class CurlTransport implements Transport
{
    private const MAX_BODY = 8 * 1024 * 1024;

    public function __construct(
        private readonly float $timeout = 15.0,
        private readonly float $connectTimeout = 5.0,
    ) {
    }

    public function get(string $url, array $headers): Response
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new TransportException("locio: refusing to fetch a $scheme URL");
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        $body = '';
        $received = [];
        $tooBig = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                    $tooBig = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if ($tooBig) {
            throw new TransportException('locio: the response was larger than 8 MiB, and was not read');
        }
        if ($ok === false) {
            throw new TransportException("locio: request failed: $error");
        }

        return new Response($status, $received, $body);
    }
}
