<?php

declare(strict_types=1);

namespace Locio\Tests;

use Locio\Http\Response;
use Locio\Http\Transport;

/** Answers every request with one canned response, and remembers what was asked. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $calls = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private mixed $body = ['data' => []],
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public function get(string $url, array $headers): Response
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        $body = is_string($this->body) ? $this->body : json_encode($this->body, JSON_THROW_ON_ERROR);

        return new Response($this->status, array_change_key_case($this->headers, CASE_LOWER), $body);
    }

    /** @param array<string, string> $headers */
    public function answer(mixed $body, int $status = 200, array $headers = []): void
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public function url(): string
    {
        return $this->calls[array_key_last($this->calls)]['url'];
    }

    public function path(): string
    {
        return (string) parse_url($this->url(), PHP_URL_PATH);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        parse_str((string) parse_url($this->url(), PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->calls[array_key_last($this->calls)]['headers'];
    }
}
