<?php

namespace App\Observers;

use App\Services\CatalogCache;
use Illuminate\Database\Eloquent\Model;

class CatalogCacheObserver
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        app(CatalogCache::class)->invalidate();
        if ($model->getConnection()->transactionLevel() > 0) {
            // Also invalidate after commit: a concurrent reader may have seen
            // the old database state while the mutation was still in progress.
            $model->getConnection()->afterCommit(fn () => app(CatalogCache::class)->invalidate());
        }
    }
}
