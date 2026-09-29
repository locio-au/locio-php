<?php

declare(strict_types=1);

namespace Locio\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Locio\Client;
use Locio\Exception\RateLimited;
use Locio\Http\Transport;
use Locio\Laravel\Locio;
use Locio\Laravel\LocioServiceProvider;
use Locio\Laravel\ResolvableAddress;
use PHPUnit\Framework\TestCase;

final class LaravelTest extends TestCase
{
    private Container $app;

    /** @param array<string, mixed> $locio */
    private function boot(array $locio, ?FakeTransport $transport = null): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['locio' => $locio]));
        if ($transport) {
            $this->app->instance(Transport::class, $transport);
        }
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();

        (new LocioServiceProvider($this->app))->register();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance(null);
    }

    public function testTheClientIsBuiltFromConfig(): void
    {
        $t = new FakeTransport();
        $this->boot(['key' => 'lc_live_abc', 'country' => 'au', 'base_url' => 'https://proxy.example.com'], $t);

        $this->app->make(Client::class)->search('1 george');
        self::assertSame('proxy.example.com', parse_url($t->url(), PHP_URL_HOST));
        self::assertSame('AU', $t->query()['country']);
        self::assertSame('Bearer lc_live_abc', $t->headers()['Authorization']);
    }

    public function testOneClientIsShared(): void
    {
        $this->boot(['key' => 'lc_live_abc'], new FakeTransport());
        self::assertSame($this->app->make(Client::class), $this->app->make(Client::class));
        self::assertSame($this->app->make(Client::class), $this->app->make('locio'));
    }

    public function testTheDefaultsApplyWhenConfigIsNotPublished(): void
    {
        $t = new FakeTransport();
        $this->boot(['key' => 'lc_live_abc'], $t);

        $this->app->make(Client::class)->search('1 george');
        self::assertSame('api.locio.com.au', parse_url($t->url(), PHP_URL_HOST));
        self::assertArrayNotHasKey('country', $t->query());
    }

    public function testAMissingKeySaysWhichVariableToSet(): void
    {
        $this->boot(['key' => null]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/LOCIO_KEY/');
        $this->app->make(Client::class);
    }

    public function testTheFacadeReachesTheClient(): void
    {
        $t = new FakeTransport(['data' => ['matched' => true, 'address' => ['id' => 'GANSW1', 'formatted' => 'x', 'lat' => 1, 'lng' => 2]]]);
        $this->boot(['key' => 'lc_live_abc'], $t);

        self::assertTrue(Locio::resolve('1 george st')->matched);
    }

    public function testTheRulePassesAnAddressThatResolves(): void
    {
        $t = new FakeTransport(['data' => ['matched' => true, 'address' => ['id' => 'GANSW1', 'formatted' => 'x', 'lat' => 1, 'lng' => 2]]]);
        $this->boot(['key' => 'lc_live_abc'], $t);

        self::assertSame([], $this->validate(new ResolvableAddress(), '1 george st sydney'));
        self::assertSame('1 george st sydney', $t->query()['q']);
    }

    public function testTheRuleFailsAnAddressThatDoesNot(): void
    {
        $this->boot(['key' => 'lc_live_abc'], new FakeTransport(['data' => ['matched' => false, 'address' => null]]));

        $failures = $this->validate(new ResolvableAddress(), '1 nowhere st');
        self::assertCount(1, $failures);
        self::assertStringContainsString(':attribute', $failures[0]);
    }

    public function testTheRuleDoesNotSpendAUnitOnNothing(): void
    {
        $t = new FakeTransport();
        $this->boot(['key' => 'lc_live_abc'], $t);

        self::assertCount(1, $this->validate(new ResolvableAddress(), '  '));
        self::assertCount(1, $this->validate(new ResolvableAddress(), ['array']));
        self::assertCount(0, $t->calls);
    }

    public function testTheRuleHandsBackWhatItResolved(): void
    {
        $t = new FakeTransport(['data' => ['matched' => true, 'address' => ['id' => 'GANSW1', 'formatted' => '1 GEORGE ST', 'lat' => 1, 'lng' => 2]]]);
        $this->boot(['key' => 'lc_live_abc'], $t);

        $rule = new ResolvableAddress();
        $this->validate($rule, '1 george st');
        self::assertSame('GANSW1', $rule->address?->id);
    }

    public function testTheRuleLetsAnOutagePassRatherThanBlockACheckout(): void
    {
        $this->boot(['key' => 'lc_live_abc'], new FakeTransport(['title' => 'too many requests'], 429));

        $rule = new ResolvableAddress();
        self::assertSame([], $this->validate($rule, '1 george st'));
        self::assertNull($rule->address);
        self::assertInstanceOf(RateLimited::class, $rule->error);
    }

    public function testTheRuleCanBeStrictAboutAnOutage(): void
    {
        $this->boot(['key' => 'lc_live_abc'], new FakeTransport(['title' => 'too many requests'], 429));

        self::assertCount(1, $this->validate(new ResolvableAddress(strict: true), '1 george st'));
    }

    /** @return list<string> */
    private function validate(ResolvableAddress $rule, mixed $value): array
    {
        $failures = [];
        $rule->validate('address', $value, static function (string $message) use (&$failures): object {
            $failures[] = $message;

            return new class () {
                public function translate(): void
                {
                }
            };
        });

        return $failures;
    }
}
