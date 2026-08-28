<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BranchSettings;
use Illuminate\Http\Request;

class OperationalSettingsController extends Controller
{
    public function __invoke(Request $request, BranchSettings $settings): array
    {
        $branchId = $request->user()->branch_id;

        return collect([
            'pending_payment_minutes',
            'half_and_half_extra',
            'additional_wing_flavor_extra',
            'max_wing_flavors',
            'delivery_zones',
            'payment_methods',
            'loyalty_enabled',
            'loyalty_point_value',
        ])->mapWithKeys(fn (string $key) => [$key => $settings->get($branchId, $key)])->all();
    }
}
