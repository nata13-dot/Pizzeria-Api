<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    /**
     * Pending work shown on the menu. Each count is only returned to users who
     * may open the matching module, and stays cheap enough to poll.
     */
    public function badges(Request $request, InventoryService $inventory)
    {
        $user = $request->user();
        $orders = fn () => Order::query()->where('branch_id', $user->branch_id);
        $badges = [];
        if ($user->hasPermission('kitchen.use') || $user->hasPermission('orders.view')) {
            $badges['kitchen'] = $orders()->where('status', 'kitchen_pending')->count();
        }
        if ($user->hasPermission('delivery.use')) {
            $badges['delivery'] = $orders()->where('status', 'ready')->where('type', 'delivery')->count();
        }
        if ($user->hasPermission('inventory.view')) {
            $badges['inventory'] = $inventory->stockQuery($user->branch_id)->where('ingredients.active', true)->get()
                ->whereIn('stock_status', ['critical', 'out'])->count();
        }

        return response()->json($badges)->header('Cache-Control', 'no-store');
    }
}
