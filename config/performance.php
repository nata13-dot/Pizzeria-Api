<?php

return [
    'catalog_cache_enabled' => (bool) env('CATALOG_CACHE_ENABLED', true),
    'catalog_cache_store' => env('CATALOG_CACHE_STORE', env('CACHE_STORE', 'file')),
    'catalog_cache_ttl' => (int) env('CATALOG_CACHE_TTL', 300),
    'enabled' => (bool) env('PERFORMANCE_METRICS_ENABLED', false),
    'log' => (bool) env('PERFORMANCE_METRICS_LOG', true),
];
