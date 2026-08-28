<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StockShortageException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BranchSettings;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $r)
    {
        $filters = $r->validate([
            'status' => 'nullable|in:draft,pending_payment,confirmed,kitchen_pending,preparing,prepared,ready,on_way,delivered,cancelled',
            'date' => 'nullable|date',
            'scheduled' => 'nullable|boolean',
            'search' => 'nullable|string|max:100',
        ]);

        return Order::with(['customer', 'items.flavors.flavor', 'items.modifiers', 'items.components', 'payments', 'delivery'])
            ->where('branch_id', $r->user()->branch_id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('order_date', $date))
            ->when(array_key_exists('scheduled', $filters), fn ($query) => $filters['scheduled'] ? $query->whereNotNull('scheduled_at') : $query->whereNull('scheduled_at'))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('daily_number', $search)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->orderByDesc('order_date')
            ->orderByDesc('daily_number')
            ->paginate(30);
    }

    public function show(Request $r, Order $order)
    {
        $this->own($r, $order);

        return $order->load(['items.flavors', 'items.modifiers', 'items.components', 'items.ingredients.ingredient', 'payments', 'delivery', 'histories']);
    }

    public function store(Request $r, OrderService $service)
    {
        $d = $this->data($r);
        $idempotencyKey = $r->header('Idempotency-Key');
        if ($idempotencyKey !== null) {
            validator(['idempotency_key' => $idempotencyKey], [
                'idempotency_key' => 'required|string|min:8|max:100|regex:/^[A-Za-z0-9._:-]+$/',
            ])->validate();
            $d['idempotency_key'] = $idempotencyKey;
        }
        $d['customer_id'] = $r->validate([
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where('branch_id', $r->user()->branch_id),
            ],
        ])['customer_id'] ?? null;
        $d['status'] = $d['status'] ?? 'confirmed';
        abort_if(
            ((float) ($d['discount'] ?? 0) > 0 || (bool) ($d['courtesy'] ?? false))
            && $r->user()->role?->slug !== 'administrador',
            403,
            'Solo un administrador puede autorizar descuentos o cortesías.',
        );

        $existing = $idempotencyKey
            ? Order::where('branch_id', $r->user()->branch_id)->where('idempotency_key', $idempotencyKey)->first()
            : null;

        return response()->json($existing?->load([
            'customer',
            'items.flavors.flavor',
            'items.modifiers',
            'items.components',
            'items.ingredients.ingredient',
            'payments',
            'delivery',
            'histories',
        ]) ?? $service->create($d, $r->user()), $existing ? 200 : 201);
    }

    public function confirm(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);
        $p = $r->validate([
            'payments' => $order->courtesy
                ? 'present|array|max:0'
                : ($order->collect_on_delivery ? 'present|array' : 'required|array|min:1'),
            'payments.*.method' => 'required|in:cash,transfer,courtesy',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string',
        ]);

        return $service->confirm($order, $p['payments'], $r->user());
    }

    public function kitchen(Request $r, Order $order, OrderService $service)
    {
        $this->own($r, $order);
        $data = $r->validate(['allow_stock_shortage' => 'sometimes|boolean']);

        try {
            return $service->sendToKitchen(
                $order,
                $r->user(),
                (bool) ($data['allow_stock_shortage'] ?? false),
            );
        } catch (StockShortageException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'stock_shortage',
                'stock_warnings' => $exception->warnings,
                'errors' => ['stock_shortage' => [$exception->getMessage()]],
            ], 422);
        }
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
        $data = $r->validate(['comment' => 'nullable|string|max:500']);

        return $service->cancel($order, $r->user(), $data['comment'] ?? null);
    }

    public function kitchenOrders(Request $r, BranchSettings $settings)
    {
        $orders = Order::with(['items.flavors.flavor', 'items.modifiers', 'items.components', 'histories'])
            ->where('branch_id', $r->user()->branch_id)
            ->whereIn('status', ['kitchen_pending', 'preparing', 'prepared'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderBy('created_at')
            ->get();

        if (! $settings->get($r->user()->branch_id, 'show_kitchen_prices')) {
            $orders->each(function (Order $order): void {
                $order->makeHidden(['subtotal', 'discount', 'delivery_fee', 'total', 'courtesy']);
                $order->items->each(function ($item): void {
                    $item->makeHidden(['unit_price', 'total']);
                    $item->modifiers->each->makeHidden('price');
                    $item->components->each(function ($component): void {
                        $component->modifiers = collect($component->modifiers)
                            ->map(fn ($modifier) => collect($modifier)->except('price')->all())
                            ->all();
                    });
                });
            });
        }

        return $orders;
    }

    public function deliveryOrders(Request $r, BranchSettings $settings)
    {
        $filters = $r->validate([
            'view' => 'nullable|in:all,operational,scheduled',
        ]);
        $view = $filters['view'] ?? 'all';
        $leadMinutes = max(0, $settings->integer($r->user()->branch_id, 'delivery_lead_minutes'));
        $orders = Order::with(['items.flavors.flavor', 'items.modifiers', 'items.components', 'delivery', 'payments'])
            ->where('branch_id', $r->user()->branch_id)
            ->where('type', 'delivery')
            ->where(function ($query) use ($leadMinutes, $view): void {
                if ($view === 'scheduled') {
                    $query->whereNotNull('scheduled_at')
                        ->whereNotIn('status', ['draft', 'pending_payment', 'cancelled', 'delivered']);

                    return;
                }

                $query->whereIn('status', ['ready', 'on_way'])
                    ->orWhere(fn ($scheduled) => $scheduled
                        ->whereNotNull('scheduled_at')
                        ->when($view === 'operational', fn ($visible) => $visible->where('scheduled_at', '<=', now()->addMinutes($leadMinutes)))
                        ->whereNotIn('status', ['draft', 'pending_payment', 'cancelled', 'delivered']));
            })
            ->orderBy('scheduled_at')
            ->get();

        return $orders->each(function (Order $order): void {
            $paid = $order->courtesy ? (float) $order->total : (float) $order->payments->sum('amount');
            $due = $order->courtesy ? 0.0 : max(0, (float) $order->total - $paid);
            $order->setAttribute('amount_paid', $paid);
            $order->setAttribute('amount_due', $due);
            $order->setAttribute('payment_status', $order->courtesy ? 'courtesy' : ($due <= .009 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid')));
            $order->setAttribute('collection_required', $due > .009);
        });
    }

    public function paymentReceived(Request $r, Order $order, BranchSettings $settings)
    {
        $this->own($r, $order);
        abort_unless(
            $order->type === 'delivery' && $order->delivery && $order->collect_on_delivery,
            422,
            'El pedido no tiene cobro contra entrega.',
        );
        $data = $r->validate([
            'method' => 'required|in:cash,transfer',
            'reference' => 'nullable|required_if:method,transfer|string|max:150',
        ]);
        abort_unless(
            in_array($data['method'], $settings->activePaymentMethods($order->branch_id), true),
            422,
            'El método de pago está desactivado en los ajustes.',
        );
        DB::transaction(function () use ($r, $order, $data): void {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $order->status === 'on_way',
                422,
                'El cobro contra entrega solo puede registrarse durante el reparto.',
            );
            $paid = (float) $order->payments()->sum('amount');
            $due = max(0, (float) $order->total - $paid);
            abort_if($order->courtesy || $due <= .009, 422, 'El pedido no tiene saldo pendiente por cobrar.');
            $order->payments()->create([
                'method' => $data['method'],
                'amount' => $due,
                'reference' => $data['reference'] ?? null,
                'user_id' => $r->user()->id,
            ]);
            $order->delivery()->lockForUpdate()->firstOrFail()->update(['payment_received' => true]);
        });

        return $order->delivery->fresh();
    }

    private function data(Request $r): array
    {
        return $r->validate([
            'status' => 'sometimes|in:draft,pending_payment,confirmed', 'type' => 'required|in:pickup,whatsapp,delivery,dine_in', 'scheduled_at' => 'nullable|date|after:now',
            'discount' => 'sometimes|numeric|min:0', 'delivery_fee' => 'sometimes|numeric|min:0', 'courtesy' => 'sometimes|boolean', 'collect_on_delivery' => 'sometimes|boolean', 'notes' => 'nullable|string',
            'items' => 'required|array|min:1', 'items.*.product_variant_id' => 'required_without:items.*.combo_id|exists:product_variants,id',
            'items.*.combo_id' => 'required_without:items.*.product_variant_id|exists:combos,id', 'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.flavor_ids' => 'array', 'items.*.flavor_ids.*' => 'exists:product_flavors,id', 'items.*.modifier_ids' => 'array', 'items.*.modifier_ids.*' => 'exists:modifiers,id',
            'items.*.components' => 'array', 'items.*.components.*.combo_item_id' => 'required|exists:combo_items,id', 'items.*.components.*.flavor_ids' => 'array',
            'items.*.components.*.flavor_ids.*' => 'exists:product_flavors,id', 'items.*.components.*.modifier_ids' => 'array', 'items.*.components.*.modifier_ids.*' => 'exists:modifiers,id',
            'items.*.components.*.notes' => 'nullable|string',
            'items.*.notes' => 'nullable|string', 'payments' => 'array', 'payments.*.method' => 'required|in:cash,transfer,courtesy', 'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string', 'delivery' => 'required_if:type,delivery|array', 'delivery.recipient' => 'required_with:delivery|string',
            'delivery.phone' => 'required_with:delivery|string', 'delivery.address' => 'required_with:delivery|string', 'delivery.references' => 'nullable|string', 'delivery.map_url' => 'nullable|url', 'delivery.delivery_zone' => 'nullable|string|max:100',
        ]);
    }

    private function own(Request $r, Order $o): void
    {
        abort_unless($o->branch_id === $r->user()->branch_id, 404);
    }
}
