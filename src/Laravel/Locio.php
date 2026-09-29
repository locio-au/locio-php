<?php

declare(strict_types=1);

namespace Locio\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * @method static list<\Locio\Address> search(string $term, int $limit = 0)
 * @method static \Locio\ScopedAddresses searchScoped(string $term, int $limit = 0)
 * @method static \Locio\Address|null get(string $id)
 * @method static \Locio\Resolution resolve(string $address)
 * @method static list<\Locio\Address> similar(string $address, int $limit = 0)
 * @method static \Locio\ScopedAddresses similarScoped(string $address, int $limit = 0)
 * @method static \Locio\Quota|null quota()
 *
 * @see \Locio\Client
 */
final class Locio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'locio';
    }
}
