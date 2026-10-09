<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

final class CatalogWrite
{
    /** Serialize catalog changes with order creation; audit observers share the transaction. */
    public static function run(int $branchId, array $models, callable $write): mixed
    {
        return DB::transaction(function () use ($branchId, $models, $write) {
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            // Route binding happened before acquiring the lock. Do not validate stale models.
            foreach ($models as $model) {
                $model->refresh();
            }

            return $write();
        });
    }
}
