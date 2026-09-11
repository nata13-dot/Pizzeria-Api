<?php

namespace App\Services;

use App\Models\Setting;

class BranchSettings
{
    /** @var array<int, array<string, mixed>> */
    private array $settingsByBranch = [];

    private const DEFAULTS = [
        'pending_payment_minutes' => 10,
        'kitchen_lead_minutes' => 30,
        'delivery_lead_minutes' => 20,
        'half_and_half_extra' => 0,
        'additional_wing_flavor_extra' => 0,
        'max_wing_flavors' => 2,
        'delivery_zones' => [],
        'payment_methods' => [
            ['key' => 'cash', 'label' => 'Efectivo', 'active' => true],
            ['key' => 'transfer', 'label' => 'Transferencia', 'active' => true],
        ],
        'show_kitchen_prices' => false,
        'loyalty_enabled' => true,
        'loyalty_point_value' => 1,
    ];

    public function get(int $branchId, string $key): mixed
    {
        if (! array_key_exists($branchId, $this->settingsByBranch)) {
            $this->settingsByBranch[$branchId] = Setting::query()
                ->where('branch_id', $branchId)
                ->pluck('value', 'key')
                ->all();
        }

        return $this->settingsByBranch[$branchId][$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function integer(int $branchId, string $key): int
    {
        return (int) $this->get($branchId, $key);
    }

    public function defaults(): array
    {
        return self::DEFAULTS;
    }

    public function forget(int $branchId): void
    {
        unset($this->settingsByBranch[$branchId]);
    }

    public function flush(): void
    {
        $this->settingsByBranch = [];
    }

    public function activePaymentMethods(int $branchId): array
    {
        return collect($this->get($branchId, 'payment_methods'))
            ->filter(fn ($method) => is_array($method) && ($method['active'] ?? true))
            ->pluck('key')
            ->filter(fn ($key) => in_array($key, ['cash', 'transfer'], true))
            ->unique()
            ->values()
            ->all();
    }
}
