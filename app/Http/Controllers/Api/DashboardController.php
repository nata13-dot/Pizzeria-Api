<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\BranchClock;
use App\Services\CashReportService;
use App\Services\InventoryService;
use Illuminate\Http\Request;

/**
 * Today at a glance. Every section is returned only to users allowed to open
 * the module behind it, so one dashboard serves administrators and cashiers.
 */
class DashboardController extends Controller
{
    private const NOT_SOLD = ['draft', 'pending_payment', 'cancelled'];

    private const PIPELINE = ['pending_payment', 'kitchen_pending', 'preparing', 'ready', 'on_way'];

    public function show(Request $request, BranchClock $clock, CashReportService $cash, InventoryService $inventory)
    {
        $user = $request->user();
        $branch = $user->branch_id;
        $now = $clock->now($branch);
        $today = $clock->today($branch);
        $data = ['date' => $today->toDateString(), 'generated_at' => now()->toISOString()];

        if ($user->hasPermission('cash.manage')) {
            $data += $this->sales($branch, $now, $today, $cash);
        }
        if ($user->hasPermission('orders.view')) {
            $data['pipeline'] = collect(self::PIPELINE)->mapWithKeys(fn ($status) => [$status => 0])
                ->merge(Order::query()->where('branch_id', $branch)->whereIn('status', self::PIPELINE)
                    ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count) => (int) $count))
                ->all();
            $data['recent_orders'] = Order::query()->where('branch_id', $branch)->whereDate('order_date', $today->toDateString())
                ->latest('id')->limit(6)->get(['id', 'daily_number', 'status', 'type', 'total', 'contact_name', 'created_at'])
                ->map(fn (Order $order) => [...$order->only(['id', 'daily_number', 'status', 'type', 'contact_name']), 'total' => (float) $order->total, 'created_at' => $order->created_at?->toISOString()])
                ->all();
        }
        if ($user->hasPermission('inventory.view')) {
            $data['inventory'] = $this->inventory($branch, $today, $inventory);
        }

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function sales(int $branch, $now, $today, CashReportService $cash): array
    {
        $summary = $cash->summary($branch, $today->toDateString());
        $timezone = $now->getTimezone();
        $sold = fn (string $date) => Order::query()->where('branch_id', $branch)->whereDate('order_date', $date)
            ->whereNotIn('status', self::NOT_SOLD)->where('courtesy', false);
        // Compare against the same weekday last week up to this same time, not the whole day.
        $lastWeek = $sold($today->subWeek()->toDateString())->where('created_at', '<=', $now->subWeek()->utc())
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as sales')->first();
        $orders = $sold($today->toDateString())->get(['id', 'total', 'type', 'created_at']);
        $byHour = $orders->groupBy(fn (Order $order) => (int) $order->created_at->setTimezone($timezone)->format('G'))
            ->map(fn ($rows, $hour) => ['hour' => (int) $hour, 'orders' => $rows->count(), 'sales' => round((float) $rows->sum('total'), 2)])
            ->sortKeys()->values()->all();
        $top = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branch)->whereDate('orders.order_date', $today->toDateString())
            ->whereNotIn('orders.status', self::NOT_SOLD)
            ->selectRaw('order_items.name, SUM(order_items.quantity) as quantity, SUM(order_items.total) as sales')
            ->groupBy('order_items.name')->orderByDesc('quantity')->orderByDesc('sales')->limit(5)->get()
            ->map(fn ($row) => ['name' => $row->name, 'quantity' => (float) $row->quantity, 'sales' => round((float) $row->sales, 2)])->all();
        $salesTotal = (float) $summary['gross_sales'];
        $paidCount = $orders->count();

        return [
            'sales' => [
                'total' => $salesTotal,
                'orders' => $summary['orders'],
                'average_ticket' => $paidCount ? round($salesTotal / $paidCount, 2) : 0,
                'last_week_same_time' => ['sales' => round((float) ($lastWeek->sales ?? 0), 2), 'orders' => (int) ($lastWeek->orders ?? 0)],
                'by_hour' => $byHour,
                'cancelled' => $summary['cancelled'],
            ],
            'payments' => [
                'cash' => $summary['cash_only_sales'],
                'transfer' => $summary['transfer_only_sales'],
                'mixed' => $summary['mixed_sales'],
                'on_delivery' => $summary['uncollected_sales'],
                'courtesy' => $summary['courtesy'],
            ],
            'order_types' => [
                'pickup' => ['orders' => $orders->where('type', '!=', 'delivery')->count(), 'sales' => round((float) $orders->where('type', '!=', 'delivery')->sum('total'), 2)],
                'delivery' => ['orders' => $orders->where('type', 'delivery')->count(), 'sales' => round((float) $orders->where('type', 'delivery')->sum('total'), 2)],
            ],
            'top_products' => $top,
            'cash_day' => [
                'open' => $summary['cash_day_id'] !== null && $summary['closed_at'] === null,
                'closed_at' => $summary['closed_at'],
                'opening_amount' => $summary['opening_amount'],
                'expected_cash' => $summary['expected_cash'],
            ],
        ];
    }

    private function inventory(int $branch, $today, InventoryService $inventory): array
    {
        $rows = $inventory->stockQuery($branch)->where('ingredients.active', true)->get();
        $rank = ['out' => 0, 'critical' => 1, 'low' => 2];
        $expiring = InventoryBatch::query()->with('ingredient.baseUnit')
            ->join('ingredients', 'ingredients.id', '=', 'inventory_batches.ingredient_id')
            ->select('inventory_batches.*', 'ingredients.expiry_alert_days')
            ->where('inventory_batches.branch_id', $branch)->where('ingredients.active', true)
            ->where('inventory_batches.available_quantity', '>', 0)->whereNotNull('inventory_batches.expires_at')
            ->whereDate('inventory_batches.expires_at', '>=', $today->toDateString())
            ->orderBy('inventory_batches.expires_at')->limit(50)->get()
            ->filter(fn (InventoryBatch $batch) => $batch->expires_at->toDateString() <= $today->addDays((int) $batch->expiry_alert_days)->toDateString());

        return [
            'out' => $rows->where('stock_status', 'out')->count(),
            'critical' => $rows->where('stock_status', 'critical')->count(),
            'low' => $rows->where('stock_status', 'low')->count(),
            'shortages' => $rows->whereIn('stock_status', array_keys($rank))->sortBy(fn ($row) => [$rank[$row->stock_status], $row->name])->take(6)
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'status' => $row->stock_status, 'current_stock' => (float) $row->current_stock, 'minimum_stock' => (float) $row->minimum_stock, 'unit' => $row->baseUnit?->symbol])->values()->all(),
            'expiring' => $expiring->take(5)->map(fn (InventoryBatch $batch) => ['ingredient' => $batch->ingredient?->name, 'quantity' => (float) $batch->available_quantity, 'unit' => $batch->ingredient?->baseUnit?->symbol, 'expires_at' => $batch->expires_at->toDateString()])->values()->all(),
            'expiring_count' => $expiring->count(),
        ];
    }
}
