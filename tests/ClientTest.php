<?php

declare(strict_types=1);

namespace Locio\Tests;

use DateTimeImmutable;
use Locio\Address;
use Locio\Client;
use Locio\Exception\BadRequest;
use Locio\Exception\LocioException;
use Locio\Exception\NotFound;
use Locio\Exception\QuotaExhausted;
use Locio\Exception\RateLimited;
use Locio\Exception\Unauthorized;
use Locio\Quota;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'lc_live_0123456789abcdef';

    private const ADDRESS = [
        'id' => 'GANSW705023327',
        'country_code' => 'AU',
        'address_detail_pid' => 'GANSW705023327',
        'formatted' => '1 GEORGE STREET, SYDNEY NSW 2000',
        'lat' => -33.8612,
        'lng' => 151.2104,
        'mesh_block' => '11205258900',
        'components' => ['number_first' => '1', 'street_name' => 'GEORGE', 'street_type' => 'STREET', 'postcode' => '2000'],
        'gnaf' => ['primary_secondary' => 'PRINCIPAL', 'geocode_type' => 'PROPERTY CENTROID'],
        'region' => 'New South Wales',
        'locality' => 'Sydney',
    ];

    private function client(FakeTransport $t, ?string $country = null): Client
    {
        return new Client(self::KEY, country: $country, transport: $t);
    }

    public function testSearchAsksForTheTermAndReturnsAddresses(): void
    {
        $t = new FakeTransport(['data' => [self::ADDRESS]]);
        $found = $this->client($t)->search('1 george st sydney');

        self::assertSame('/v1/addresses', $t->path());
        self::assertSame('1 george st sydney', $t->query()['q']);
        self::assertCount(1, $found);
        self::assertInstanceOf(Address::class, $found[0]);
        self::assertSame('GANSW705023327', $found[0]->id);
    }

    public function testTheLimitIsSentAndAZeroIsLeftOff(): void
    {
        $t = new FakeTransport();
        $this->client($t)->search('1 george', limit: 5);
        self::assertSame('5', $t->query()['limit']);
        $this->client($t)->search('1 george', limit: 0);
        self::assertArrayNotHasKey('limit', $t->query());
    }

    public function testAnEmptyTermSpendsNothing(): void
    {
        $t = new FakeTransport();
        self::assertSame([], $this->client($t)->search('   '));
        self::assertSame([], $this->client($t)->similar(''));
        self::assertCount(0, $t->calls);
    }

    public function testScopedSearchCarriesTheCountryAndTheNote(): void
    {
        $t = new FakeTransport(['data' => [], 'country_code' => 'NZ', 'note' => 'We hold no addresses for New Zealand yet.']);
        $scoped = $this->client($t)->searchScoped('1 queen st auckland');

        self::assertSame([], $scoped->addresses);
        self::assertSame('NZ', $scoped->countryCode);
        self::assertSame('We hold no addresses for New Zealand yet.', $scoped->note);
    }

    public function testTheChosenCountryIsSentOnEveryCall(): void
    {
        $t = new FakeTransport(['data' => []]);
        $locio = $this->client($t, 'au');
        $locio->search('1 george');
        self::assertSame('AU', $t->query()['country']);
        $locio->resolve('1 george st sydney');
        self::assertSame('AU', $t->query()['country']);
    }

    public function testNoCountryIsSentWhenNoneWasChosen(): void
    {
        $t = new FakeTransport();
        $this->client($t)->search('1 george');
        self::assertArrayNotHasKey('country', $t->query());
    }

    public function testACountryMustBeTwoLetters(): void
    {
        foreach (['AUS', 'Australia', 'a', 'A1'] as $country) {
            try {
                $this->client(new FakeTransport(), $country);
                self::fail("accepted $country");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('two letter', $e->getMessage());
            }
        }
    }

    public function testGetReadsOneAddress(): void
    {
        $t = new FakeTransport(['data' => self::ADDRESS]);
        $address = $this->client($t)->get('GANSW705023327');

        self::assertSame('/v1/addresses/GANSW705023327', $t->path());
        self::assertNotNull($address);
        self::assertSame('1 GEORGE STREET, SYDNEY NSW 2000', $address->formatted);
    }

    public function testARetiredIdIsNullNotAnException(): void
    {
        $t = new FakeTransport(['title' => 'not found'], 404);
        self::assertNull($this->client($t)->get('GANSW700000000'));
    }

    public function testGetStillThrowsForAnyOtherFailure(): void
    {
        $t = new FakeTransport(['title' => 'server error'], 500);
        $this->expectException(LocioException::class);
        $this->client($t)->get('GANSW705023327');
    }

    public function testResolveSaysWhetherItMatched(): void
    {
        $t = new FakeTransport(['data' => ['matched' => true, 'address' => self::ADDRESS]]);
        $res = $this->client($t)->resolve('1 george st sydney nsw 2000');

        self::assertSame('/v1/addresses/resolve', $t->path());
        self::assertTrue($res->matched);
        self::assertSame('GANSW705023327', $res->address?->id);
    }

    public function testResolveWithoutDataIsNotMatched(): void
    {
        $res = $this->client(new FakeTransport([]))->resolve('nowhere');
        self::assertFalse($res->matched);
        self::assertNull($res->address);
    }

    public function testSimilarSendsTheLimit(): void
    {
        $t = new FakeTransport(['data' => [self::ADDRESS]]);
        $found = $this->client($t)->similar('1 gorge st sydnee', limit: 3);
        self::assertSame('/v1/addresses/similar', $t->path());
        self::assertSame('3', $t->query()['limit']);
        self::assertCount(1, $found);
    }

    public function testTheAddressCarriesEveryField(): void
    {
        $a = Address::fromArray(self::ADDRESS);

        self::assertSame('GANSW705023327', $a->id);
        self::assertSame('AU', $a->countryCode);
        self::assertSame('GANSW705023327', $a->addressDetailPid);
        self::assertSame(-33.8612, $a->lat);
        self::assertSame(151.2104, $a->lng);
        self::assertSame('11205258900', $a->meshBlock);
        self::assertSame('2000', $a->components['postcode']);
        self::assertSame('PROPERTY CENTROID', $a->gnaf['geocode_type']);
        self::assertSame('Sydney', $a->locality);
        self::assertSame(self::ADDRESS, $a->raw);
        self::assertFalse($a->isUnit());
        self::assertTrue($a->isAustralian());
    }

    public function testTheIdFallsBackToThePidForAnOlderService(): void
    {
        $a = Address::fromArray(['formatted' => 'x', 'lat' => 0, 'lng' => 0, 'gnaf_pid' => 'GAVIC1']);
        self::assertSame('GAVIC1', $a->id);
    }

    public function testAUnitIsSaidToBeOne(): void
    {
        $a = Address::fromArray(['gnaf' => ['primary_secondary' => 'SECONDARY', 'primary_pid' => 'GAVIC9']] + self::ADDRESS);
        self::assertTrue($a->isUnit());
    }

    public function testAnAddressOutsideAustraliaHasNoGnafFields(): void
    {
        $a = Address::fromArray(['id' => 'us-123', 'country_code' => 'US', 'formatted' => '1 Main St', 'lat' => 40.0, 'lng' => -74.0]);
        self::assertFalse($a->isAustralian());
        self::assertNull($a->addressDetailPid);
        self::assertNull($a->meshBlock);
    }

    public function testErrorsKeepTheServicesOwnWords(): void
    {
        $t = new FakeTransport(['title' => 'bad key', 'detail' => 'That key was revoked on 3 September.'], 401);
        try {
            $this->client($t)->search('1 george');
            self::fail('no exception');
        } catch (Unauthorized $e) {
            self::assertSame(401, $e->status);
            self::assertSame('bad key', $e->title);
            self::assertSame('That key was revoked on 3 September.', $e->detail);
            self::assertSame('bad key: That key was revoked on 3 September.', $e->getMessage());
        }
    }

    public function testForbiddenIsAKeyProblemToo(): void
    {
        $this->expectException(Unauthorized::class);
        $this->client(new FakeTransport(['title' => 'origin not allowed'], 403))->search('1 george');
    }

    public function testASpentQuotaSaysWhenItResets(): void
    {
        $t = new FakeTransport(['title' => 'quota exhausted'], 402, [
            'X-Quota-Limit' => '10000',
            'X-Quota-Remaining' => '0',
            'X-Quota-Reset' => '2026-10-01T00:00:00Z',
        ]);
        try {
            $this->client($t)->search('1 george');
            self::fail('no exception');
        } catch (QuotaExhausted $e) {
            self::assertEquals(new DateTimeImmutable('2026-10-01T00:00:00Z'), $e->reset);
            self::assertSame(10000, $e->quota?->limit);
            self::assertSame(0, $e->quota?->remaining);
        }
    }

    public function testARateLimitSaysHowLongToWait(): void
    {
        $t = new FakeTransport(['title' => 'too many requests'], 429, ['Retry-After' => '1']);
        try {
            $this->client($t)->search('1 george');
            self::fail('no exception');
        } catch (RateLimited $e) {
            self::assertSame(1, $e->retryAfter);
            self::assertNotInstanceOf(QuotaExhausted::class, $e);
        }
    }

    public function testRetryAfterIsNullWhenNotSaid(): void
    {
        try {
            $this->client(new FakeTransport([], 429))->search('1 george');
            self::fail('no exception');
        } catch (RateLimited $e) {
            self::assertNull($e->retryAfter);
        }
    }

    public function testOtherStatusesAreNamed(): void
    {
        try {
            $this->client(new FakeTransport([], 404))->search('1 george');
            self::fail('no exception');
        } catch (NotFound) {
            self::addToAssertionCount(1);
        }
        $this->expectException(BadRequest::class);
        $this->client(new FakeTransport([], 400))->search('1 george');
    }

    public function testTheStatusStandsWhenTheBodyIsNotAProblemDocument(): void
    {
        try {
            $this->client(new FakeTransport('<html>bad gateway</html>', 502))->search('1 george');
            self::fail('no exception');
        } catch (LocioException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('HTTP 502', $e->getMessage());
        }
    }

    public function testQuotaIsUnknownUntilACallAnswers(): void
    {
        self::assertNull($this->client(new FakeTransport())->quota());
    }

    public function testQuotaHoldsTheLatestAnswer(): void
    {
        $t = new FakeTransport(['data' => []], 200, [
            'X-Quota-Limit' => '10000',
            'X-Quota-Remaining' => '9876',
            'X-Quota-Reset' => '2026-10-01T00:00:00Z',
        ]);
        $locio = $this->client($t);
        $locio->search('1 george');

        self::assertEquals(new Quota(10000, 9876, new DateTimeImmutable('2026-10-01T00:00:00Z')), $locio->quota());
    }

    public function testQuotaIsKeptWhenAnAnswerCarriesNone(): void
    {
        $t = new FakeTransport(['data' => []], 200, [
            'X-Quota-Limit' => '100',
            'X-Quota-Remaining' => '40',
            'X-Quota-Reset' => '2026-10-01T00:00:00Z',
        ]);
        $locio = $this->client($t);
        $locio->search('1 george');
        $t->answer(['data' => []]);
        $locio->search('1 george');
        self::assertSame(40, $locio->quota()?->remaining);
    }

    public function testQuotaHeadersThatDoNotParseAreIgnored(): void
    {
        $t = new FakeTransport(['data' => []], 200, ['X-Quota-Limit' => 'lots', 'X-Quota-Remaining' => '1', 'X-Quota-Reset' => 'soon']);
        $locio = $this->client($t);
        $locio->search('1 george');
        self::assertNull($locio->quota());
    }

    public function testRequestsIdentifyTheLibrary(): void
    {
        $t = new FakeTransport();
        $this->client($t)->search('1 george');
        self::assertSame('locio-php/' . Client::VERSION, $t->headers()['User-Agent']);
        self::assertSame('application/json', $t->headers()['Accept']);
    }
}
