<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogCache
{
    public function remember(int $branchId, string $resource, Closure $load): array
    {
        // Never publish uncommitted data to other requests.
        if (! config('performance.catalog_cache_enabled') || DB::transactionLevel() > 0) {
            return $load();
        }

        $store = Cache::store(config('performance.catalog_cache_store'));
        $version = $store->get('catalog:version', 'initial');
        // Fixed keys keep file-cache usage bounded across repeated edits.
        $key = "catalog:v1:{$branchId}:{$resource}";
        $entry = $store->get($key);
        if (is_array($entry) && ($entry['version'] ?? null) === $version) {
            return $entry['data'];
        }

        $data = $load();
        if ($store->get('catalog:version', 'initial') === $version) {
            $store->put($key, ['version' => $version, 'data' => $data],
                max(1, (int) config('performance.catalog_cache_ttl', 300)));
        }

        return $data;
    }

    public function invalidate(): void
    {
        if (! config('performance.catalog_cache_enabled')) {
            return;
        }

        Cache::store(config('performance.catalog_cache_store'))
            ->forever('catalog:version', (string) Str::uuid());
    }
}
