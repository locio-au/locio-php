<?php

declare(strict_types=1);

namespace Locio\Laravel;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Locio\Client;
use Locio\Http\Transport;

/**
 * Registers one shared Client, built from config/locio.php. Discovered
 * automatically; nothing to add to config/app.php.
 */
final class LocioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/locio.php', 'locio');

        $this->app->singleton(Client::class, static function ($app): Client {
            /** @var array{key?: ?string, country?: ?string, base_url?: ?string} $config */
            $config = $app->make('config')->get('locio', []);
            $key = (string) ($config['key'] ?? '');
            if (trim($key) === '') {
                throw new InvalidArgumentException(
                    'locio: no key. Set LOCIO_KEY in .env to a secret key (lc_live_...) from https://locio.com.au/account/api',
                );
            }

            return new Client(
                $key,
                country: ($config['country'] ?? null) ?: null,
                baseUrl: ($config['base_url'] ?? null) ?: Client::DEFAULT_BASE_URL,
                transport: $app->bound(Transport::class) ? $app->make(Transport::class) : null,
            );
        });
        $this->app->alias(Client::class, 'locio');
    }

    public function boot(): void
    {
        if (method_exists($this->app, 'runningInConsole') && $this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__, 2) . '/config/locio.php' => $this->app->configPath('locio.php'),
            ], 'locio-config');
        }
    }
}
