<?php

namespace App\Services;

use App\Models\Setting;

class BranchSettings
{
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
        $setting = Setting::where('branch_id', $branchId)->where('key', $key)->first();

        return $setting?->value ?? self::DEFAULTS[$key] ?? null;
    }

    public function integer(int $branchId, string $key): int
    {
        return (int) $this->get($branchId, $key);
    }

    public function defaults(): array
    {
        return self::DEFAULTS;
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
