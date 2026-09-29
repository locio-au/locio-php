<?php

declare(strict_types=1);

namespace Locio\Tests;

use InvalidArgumentException;
use Locio\Client;
use Locio\Exception\LocioException;
use Locio\Exception\TransportException;
use Locio\Http\CurlTransport;
use PHPUnit\Framework\TestCase;

/**
 * The controls that stop this client leaking the key it carries.
 *
 * A secret key is a bearer credential: whoever holds it spends its quota. So
 * the key travels only over https to the host the caller named, never in a
 * URL, never through a redirect, never into a log line or a stack trace, and
 * nothing a caller hands in can reach a header or walk out of its path.
 */
final class SecurityTest extends TestCase
{
    private const KEY = 'lc_live_0123456789abcdef';

    public function testAKeyIsRequired(): void
    {
        foreach (['', '   '] as $key) {
            try {
                new Client($key, transport: new FakeTransport());
                self::fail('accepted an empty key');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('no key', $e->getMessage());
            }
        }
    }

    public function testAKeyWithWhitespaceOrAControlCharacterIsRefused(): void
    {
        foreach (["lc_live_abc\r\nX-Evil: 1", "lc_live_abc\n", 'lc_live abc', "lc_live_abc\0"] as $key) {
            try {
                new Client($key, transport: new FakeTransport());
                self::fail('accepted ' . json_encode($key));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('key', $e->getMessage());
                self::assertStringNotContainsString('lc_live_abc', $e->getMessage());
            }
        }
    }

    public function testTheKeyDoesNotShowWhenTheClientIsDumped(): void
    {
        $client = new Client(self::KEY, transport: new FakeTransport());

        self::assertStringNotContainsString(self::KEY, print_r($client, true));
        ob_start();
        var_dump($client);
        self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());
        self::assertStringNotContainsString(self::KEY, (string) json_encode($client));
    }

    public function testTheKeyDoesNotShowInAStackTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new Client(self::KEY, baseUrl: 'http://api.locio.com.au');
            self::fail('accepted plaintext http');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::KEY, print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::KEY, (string) $e);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testTheDefaultIsHttps(): void
    {
        self::assertSame('https://api.locio.com.au', Client::DEFAULT_BASE_URL);
    }

    public function testPlaintextHttpToAPublicHostIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/clear/');
        new Client(self::KEY, baseUrl: 'http://api.locio.com.au');
    }

    public function testLoopbackOverHttpIsAllowed(): void
    {
        foreach (['http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080'] as $baseUrl) {
            new Client(self::KEY, baseUrl: $baseUrl, transport: new FakeTransport());
        }
        self::addToAssertionCount(3);
    }

    public function testAHostThatOnlyStartsLikeLoopbackIsRefused(): void
    {
        foreach (['http://localhost.evil.example', 'http://127.0.0.1.evil.example'] as $baseUrl) {
            try {
                new Client(self::KEY, baseUrl: $baseUrl);
                self::fail("accepted $baseUrl");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBadBaseUrlsAreRefused(): void
    {
        $bad = [
            'ftp://api.locio.com.au',
            'file:///etc/passwd',
            'javascript:alert(1)',
            '',
            'api.locio.com.au',
            '/v1',
            'https://api.locio.com.au@evil.example',
            'https://user:pass@api.locio.com.au',
            'https://api.locio.com.au?x=1',
            'https://api.locio.com.au#x',
        ];
        foreach ($bad as $baseUrl) {
            try {
                new Client(self::KEY, baseUrl: $baseUrl);
                self::fail("accepted $baseUrl");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryRequestGoesToTheHostItWasGiven(): void
    {
        $t = new FakeTransport(['data' => null]);
        $locio = new Client(self::KEY, baseUrl: 'https://proxy.example.com/locio/', transport: $t);
        $locio->search('1 george');
        $locio->get('GANSW705023327');
        $locio->resolve('1 george st');
        $locio->similar('1 gorge st');

        foreach ($t->calls as $call) {
            self::assertSame('proxy.example.com', parse_url($call['url'], PHP_URL_HOST));
            self::assertStringStartsWith('/locio/v1/addresses', (string) parse_url($call['url'], PHP_URL_PATH));
        }
    }

    public function testTheKeyTravelsAsABearerCredentialAndNowhereElse(): void
    {
        $t = new FakeTransport();
        (new Client(self::KEY, transport: $t))->search('1 george');

        self::assertSame('Bearer ' . self::KEY, $t->headers()['Authorization']);
        self::assertStringNotContainsString(self::KEY, $t->url());
        self::assertArrayNotHasKey('Cookie', $t->headers());
    }

    public function testAnIdCannotWalkOutOfItsPath(): void
    {
        $t = new FakeTransport(['data' => null]);
        $locio = new Client(self::KEY, transport: $t);
        foreach (['../keys', '..%2Fkeys', 'a/b', 'x?limit=1000', 'x#frag'] as $id) {
            $locio->get($id);
            $path = (string) parse_url($t->url(), PHP_URL_PATH);
            $segments = explode('/', $path);
            self::assertSame(['', 'v1', 'addresses'], array_slice($segments, 0, 3));
            self::assertCount(4, $segments, $path);
            self::assertNull(parse_url($t->url(), PHP_URL_QUERY));
            self::assertNull(parse_url($t->url(), PHP_URL_FRAGMENT));
        }
    }

    public function testAnIdNamingTheCollectionOrItsParentIsRefused(): void
    {
        $locio = new Client(self::KEY, transport: new FakeTransport());
        foreach (['', '  ', '.', '..'] as $id) {
            try {
                $locio->get($id);
                self::fail('accepted ' . json_encode($id));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('id', $e->getMessage());
            }
        }
    }

    public function testATermCannotInjectAnotherParameter(): void
    {
        $t = new FakeTransport();
        (new Client(self::KEY, transport: $t))->search('1 george&country=NZ&limit=1000');

        self::assertSame('1 george&country=NZ&limit=1000', $t->query()['q']);
        self::assertArrayNotHasKey('country', $t->query());
        self::assertArrayNotHasKey('limit', $t->query());
    }

    public function testANegativeLimitIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/limit/');
        (new Client(self::KEY, transport: new FakeTransport()))->search('1 george', limit: -1);
    }

    public function testABodyThatIsNotAnObjectSurvives(): void
    {
        foreach (['null', '42', '"text"', '[1,2]', ''] as $body) {
            $found = (new Client(self::KEY, transport: new FakeTransport($body)))->search('1 george');
            self::assertSame([], $found);
        }
    }

    public function testAProblemDocumentDoesNotDecideWhetherItFailed(): void
    {
        $t = new FakeTransport(['data' => [], 'title' => 'error', 'status' => 500], 200);
        self::assertSame([], (new Client(self::KEY, transport: $t))->search('1 george'));
    }

    public function testAnErrorDoesNotRepeatTheKey(): void
    {
        $t = new FakeTransport(['title' => 'bad key'], 401);
        try {
            (new Client(self::KEY, transport: $t))->search('1 george');
            self::fail('no exception');
        } catch (LocioException $e) {
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    // The transport, against a real socket.

    /** @var resource|null */
    private static $server = null;
    private static string $origin = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            return;
        }
        $port = random_int(20000, 40000);
        self::$origin = "http://127.0.0.1:$port";
        $router = __DIR__ . '/fixtures/router.php';
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        ) ?: null;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function transport(): CurlTransport
    {
        if (!self::$server) {
            self::markTestSkipped('needs ext-curl');
        }

        return new CurlTransport(timeout: 5.0);
    }

    public function testTheTransportDoesNotFollowARedirect(): void
    {
        $locio = new Client(self::KEY, baseUrl: self::$origin . '/redirect', transport: $this->transport());
        try {
            $locio->search('1 george');
            self::fail('followed the redirect');
        } catch (LocioException $e) {
            self::assertSame(302, $e->status);
        }
        $seen = (new CurlTransport())->get(self::$origin . '/seen', []);
        self::assertSame('0', $seen->body, 'the redirect target was reached');
    }

    public function testTheTransportCapsTheBody(): void
    {
        $locio = new Client(self::KEY, baseUrl: self::$origin . '/huge', transport: $this->transport());
        $this->expectException(TransportException::class);
        $locio->search('1 george');
    }

    public function testTheTransportSpeaksOnlyHttp(): void
    {
        $this->expectException(TransportException::class);
        $this->transport()->get('file:///etc/passwd', []);
    }

    public function testTheTransportSendsTheHeadersItWasGiven(): void
    {
        $locio = new Client(self::KEY, baseUrl: self::$origin . '/echo', transport: $this->transport());
        $found = $locio->searchScoped('1 george');
        self::assertSame('Bearer ' . self::KEY, $found->note);
    }
}
