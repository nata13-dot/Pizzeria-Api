<?php

namespace App\Services;

use App\Models\Branch;
use Carbon\CarbonImmutable;

class BranchClock
{
    private array $timezones = [];

    public function now(int $branchId): CarbonImmutable
    {
        $timezone = $this->timezones[$branchId] ??= Branch::whereKey($branchId)->value('timezone') ?: config('app.timezone');

        return CarbonImmutable::now($timezone);
    }

    public function today(int $branchId): CarbonImmutable
    {
        return $this->now($branchId)->startOfDay();
    }
}
