<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function range(Request $r, $q, $field = 'created_at')
    {
        return $q->when($r->from, fn ($x, $d) => $x->whereDate($field, '>=', $d))->when($r->to, fn ($x, $d) => $x->whereDate($field, '<=', $d));
    }

    public function sales(Request $r)
    {
        $q = $this->range($r, Order::where('branch_id', $r->user()->branch_id)->whereNotIn('status', ['draft', 'pending_payment']), 'order_date');

        return ['summary' => (clone $q)->selectRaw('COUNT(*) orders, SUM(CASE WHEN courtesy = 0 AND status != "cancelled" THEN total ELSE 0 END) sales, SUM(discount) discounts')->first(), 'by_day' => $q->selectRaw('order_date, COUNT(*) orders, SUM(total) total')->groupBy('order_date')->get()];
    }

    public function products(Request $r)
    {
        return OrderItem::whereHas('order', fn ($q) => $q->where('branch_id', $r->user()->branch_id)->whereNotIn('status', ['draft', 'pending_payment', 'cancelled']))->selectRaw('name, SUM(quantity) quantity, SUM(total) sales')->groupBy('name')->orderByDesc('quantity')->get();
    }

    public function inventory(Request $r)
    {
        return ['ingredients' => Ingredient::with('baseUnit')->where('branch_id', $r->user()->branch_id)->get(), 'waste' => InventoryAdjustment::where('branch_id', $r->user()->branch_id)->whereIn('reason', ['waste', 'expiry', 'preparation_error', 'loss'])->sum('quantity')];
    }

    public function purchases(Request $r)
    {
        return $this->range($r, Purchase::with('supplier')->where('branch_id', $r->user()->branch_id), 'purchased_at')->get();
    }

    public function customers(Request $r)
    {
        return Customer::withCount('orders')->withSum('orders', 'total')->where('branch_id', $r->user()->branch_id)->orderByDesc('orders_count')->get();
    }

    public function profit(Request $r)
    {
        $items = OrderItem::with(['ingredients.ingredient.batches'])->whereHas('order', fn ($q) => $q->where('branch_id', $r->user()->branch_id)->whereNotIn('status', ['draft', 'pending_payment', 'cancelled']))->get();
        return $items->groupBy('name')->map(function ($rows, $name) {
            $sales = (float) $rows->sum('total');
            $cost = $rows->sum(function ($row) {
                return $row->ingredients->sum(function ($requirement) {
                    $batches = $requirement->ingredient->batches;
                    $units = (float) $batches->sum('initial_quantity');
                    $average = $units > 0 ? $batches->sum(fn ($b) => (float) $b->initial_quantity * (float) $b->unit_cost) / $units : 0;
                    return (float) $requirement->quantity * $average;
                });
            });
            return ['product' => $name, 'sales' => round($sales, 2), 'estimated_cost' => round($cost, 2), 'estimated_profit' => round($sales - $cost, 2)];
        })->values();
    }

    public function times(Request $r)
    {
        $orders = Order::with('histories')->where('branch_id', $r->user()->branch_id)->whereHas('histories', fn ($q) => $q->where('to_status', 'delivered'))->get();
        $values = $orders->map(function ($order) {
            $at = fn ($status) => $order->histories->firstWhere('to_status', $status)?->created_at;
            return ['order_id' => $order->id, 'kitchen_minutes' => $at('prepared') && $at('kitchen_pending') ? $at('kitchen_pending')->diffInMinutes($at('prepared')) : null, 'delivery_minutes' => $at('delivered') && $at('on_way') ? $at('on_way')->diffInMinutes($at('delivered')) : null];
        });
        return ['orders' => $values, 'average_kitchen_minutes' => round((float) $values->whereNotNull('kitchen_minutes')->avg('kitchen_minutes'), 2), 'average_delivery_minutes' => round((float) $values->whereNotNull('delivery_minutes')->avg('delivery_minutes'), 2)];
    }
}
