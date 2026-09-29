<?php

// Publish with: php artisan vendor:publish --tag=locio-config

return [
    // A secret key (lc_live_...) from https://locio.com.au/account/api.
    // Server side only: never render it into a page.
    'key' => env('LOCIO_KEY'),

    // Which country's addresses to search, as a two letter code. A request
    // from your server says nothing about where the address is. Null leaves
    // the service's default, which is AU.
    'country' => env('LOCIO_COUNTRY'),

    'base_url' => env('LOCIO_BASE_URL', 'https://api.locio.com.au'),
];
