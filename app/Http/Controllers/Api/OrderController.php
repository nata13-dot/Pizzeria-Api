<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $r)
    {
        return Order::with(['items.flavors', 'payments', 'delivery'])->where('branch_id', $r->user()->branch_id)->when($r->status, fn ($q, $s) => $q->where('status', $s))->when($r->boolean('scheduled'), fn ($q) => $q->whereNotNull('scheduled_at'))->latest()->paginate(30);
    }

    public function show(Request $r, Order $order)
    {
        $this->own($r, $order);

        return $order->load(['items.flavors', 'items.modifiers', 'items.ingredients.ingredient', 'payments', 'delivery', 'histories']);
    }

    public function store(Request $r, OrderService $service)
    {
        $d = $this->data($r);
        $d['customer_id'] = $r->validate(['customer_id' => 'nullable|exists:customers,id'])['customer_id'] ?? null;
        $d['status'] = $d['status'] ?? 'confirmed';

        return response()->json($service->create($d, $r->user()), 201);
    }

    public function confirm(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);
        $p = $r->validate(['payments' => 'required|array|min:1', 'payments.*.method' => 'required|in:cash,transfer,courtesy', 'payments.*.amount' => 'required|numeric|min:0', 'payments.*.reference' => 'nullable|string']);

        return $service->confirm($order, $p['payments'], $r->user());
    }

    public function kitchen(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);

        return $service->sendToKitchen($order, $r->user());
    }

    public function status(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);
        $d = $r->validate(['status' => 'required|in:preparing,prepared,ready,on_way,delivered']);

        return $service->status($order, $d['status'], $r->user());
    }

    public function cancel(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);

        return $service->cancel($order, $r->user(), $r->input('comment'));
    }

    public function kitchenOrders(Request $r)
    {
        return Order::with(['items.flavors', 'items.modifiers'])->where('branch_id', $r->user()->branch_id)->whereIn('status', ['kitchen_pending', 'preparing', 'prepared'])->orderByRaw('scheduled_at IS NOT NULL')->orderBy('created_at')->get();
    }

    public function deliveryOrders(Request $r)
    {
        return Order::with(['items', 'delivery'])->where('branch_id', $r->user()->branch_id)->where('type', 'delivery')->whereIn('status', ['ready', 'on_way'])->orWhere(fn ($q) => $q->where('branch_id', $r->user()->branch_id)->where('type', 'delivery')->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now()))->orderBy('scheduled_at')->get();
    }

    public function paymentReceived(Request $r, Order $order)
    {
        $this->own($r, $order);
        abort_unless($order->type === 'delivery' && $order->delivery, 422);
        $order->delivery->update(['payment_received' => true]);

        return $order->delivery->fresh();
    }

    private function data(Request $r): array
    {
        return $r->validate([
            'status' => 'sometimes|in:draft,pending_payment,confirmed', 'type' => 'required|in:pickup,whatsapp,delivery,dine_in', 'scheduled_at' => 'nullable|date',
            'discount' => 'sometimes|numeric|min:0', 'delivery_fee' => 'sometimes|numeric|min:0', 'courtesy' => 'sometimes|boolean', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.product_variant_id' => 'required_without:items.*.combo_id|exists:product_variants,id',
            'items.*.combo_id' => 'required_without:items.*.product_variant_id|exists:combos,id', 'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.flavor_ids' => 'array', 'items.*.flavor_ids.*' => 'exists:product_flavors,id', 'items.*.modifier_ids' => 'array', 'items.*.modifier_ids.*' => 'exists:modifiers,id',
            'items.*.components' => 'array', 'items.*.components.*.combo_item_id' => 'required|exists:combo_items,id', 'items.*.components.*.flavor_ids' => 'array',
            'items.*.components.*.flavor_ids.*' => 'exists:product_flavors,id', 'items.*.components.*.modifier_ids' => 'array', 'items.*.components.*.modifier_ids.*' => 'exists:modifiers,id',
            'items.*.notes' => 'nullable|string', 'payments' => 'array', 'payments.*.method' => 'required|in:cash,transfer,courtesy', 'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string', 'delivery' => 'required_if:type,delivery|array', 'delivery.recipient' => 'required_with:delivery|string',
            'delivery.phone' => 'required_with:delivery|string', 'delivery.address' => 'required_with:delivery|string', 'delivery.references' => 'nullable|string', 'delivery.map_url' => 'nullable|url',
        ]);
    }

    private function own(Request $r,Order $o): void
    {
        abort_unless($o->branch_id === $r->user()->branch_id,404);
    }
}
