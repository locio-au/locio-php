<?php

declare(strict_types=1);

namespace Locio\Http;

/**
 * How a request reaches the network.
 *
 * One method, so any HTTP stack fits behind it: the bundled CurlTransport,
 * a PSR-18 client, or WordPress's own wp_remote_get. An implementation must
 * not follow redirects, since a redirect would carry the Authorization header
 * to wherever it points.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \Locio\Exception\TransportException when no answer arrived
     */
    public function get(string $url, array $headers): Response;
}
