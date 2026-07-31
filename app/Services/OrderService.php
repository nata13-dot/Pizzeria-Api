<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Combo;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(array $data, $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            $date = today()->toDateString();
            $number = (int) Order::where('branch_id', $user->branch_id)->whereDate('order_date', $date)->where('status', '!=', 'cancelled')->max('daily_number') + 1;
            $order = Order::create(['branch_id' => $user->branch_id, 'user_id' => $user->id, 'customer_id' => $data['customer_id'] ?? null, 'order_date' => $date, 'daily_number' => $number, 'status' => $data['status'], 'type' => $data['type'], 'scheduled_at' => $data['scheduled_at'] ?? null, 'pending_expires_at' => $data['status'] === 'pending_payment' ? now()->addMinutes(10) : null, 'discount' => $data['discount'] ?? 0, 'delivery_fee' => $data['delivery_fee'] ?? 0, 'courtesy' => $data['courtesy'] ?? false, 'notes' => $data['notes'] ?? null]);
            $subtotal = 0;
            foreach ($data['items'] as $row) {
                if (isset($row['combo_id'])) {
                    $subtotal += $this->addCombo($order, $row);
                    continue;
                }
                $variant = ProductVariant::with('product')->findOrFail($row['product_variant_id']);
                $modifierIds = $row['modifier_ids'] ?? [];
                $unit = (float) $variant->price;
                foreach ($variant->modifierRules()->with('modifier')->whereIn('modifier_id', $modifierIds)->get() as $rule) {
                    $unit += (float) ($rule->price_override ?? $rule->modifier->price);
                }$item = $order->items()->create(['product_variant_id' => $variant->id, 'name' => $variant->product->name.' '.$variant->name, 'quantity' => $row['quantity'], 'unit_price' => $unit, 'total' => $unit * $row['quantity'], 'notes' => $row['notes'] ?? null]);
                $ingredients = app(RecipeResolver::class)->resolve($variant, $row['flavor_ids'] ?? [], $modifierIds);
                foreach ($ingredients as $ing) {
                    $item->ingredients()->create(['ingredient_id' => $ing['ingredient_id'], 'quantity' => $ing['quantity'] * $row['quantity']]);
                }foreach ($row['flavor_ids'] ?? [] as $flavor) {
                    $item->flavors()->create(['product_flavor_id' => $flavor, 'ratio' => 1 / max(1, count($row['flavor_ids']))]);
                }foreach ($modifierIds as $id) {
                    $m = Modifier::findOrFail($id);
                    $item->modifiers()->create(['modifier_id' => $id, 'name' => $m->name, 'price' => $m->price]);
                }$subtotal += $item->total;
            }$total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + (float) ($data['delivery_fee'] ?? 0));
            $order->update(['subtotal' => $subtotal, 'total' => $total]);
            if ($data['status'] === 'pending_payment') {
                $requirements = $order->items()->with('ingredients')->get()->flatMap->ingredients->groupBy('ingredient_id');
                foreach ($requirements as $ingredientId => $rows) {
                    $order->reservations()->create(['ingredient_id' => $ingredientId, 'quantity' => $rows->sum('quantity'), 'expires_at' => $order->pending_expires_at]);
                }
            }
            if (($data['type'] === 'delivery') && isset($data['delivery'])) {
                $order->delivery()->create($data['delivery']);
            }foreach ($data['payments'] ?? [] as $payment) {
                $order->payments()->create($payment + ['user_id' => $user->id]);
            }if ($data['status'] === 'confirmed') {
                $this->validatePayment($order);
            }$order->histories()->create(['user_id' => $user->id, 'to_status' => $data['status']]);

            return $order->load($this->relations());
        });
    }

    public function confirm(Order $order, array $payments, $user): Order
    {
        return DB::transaction(function () use ($order, $payments, $user) {
            $this->expect($order, ['draft', 'pending_payment']);
            $order->payments()->delete();
            foreach ($payments as $p) {
                $order->payments()->create($p + ['user_id' => $user->id]);
            }$this->validatePayment($order);
            $this->transition($order, 'confirmed', $user);
            $order->reservations()->delete();

            return $order->load($this->relations());
        });
    }

    public function sendToKitchen(Order $order, $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $this->expect($order, ['confirmed']);
            $warnings = [];
            foreach ($order->items as $item) {
                foreach ($item->ingredients as $requirement) {
                    $result = app(InventoryService::class)->consumeFefo($requirement->ingredient, (float) $requirement->quantity, 'sale', $user, $order);
                    if ($result['shortage'] > 0) {
                        $warnings[] = ['ingredient_id' => $requirement->ingredient_id, 'name' => $requirement->ingredient->name, 'required' => (float) $requirement->quantity, 'available' => (float) $requirement->ingredient->current_stock, 'shortage' => $result['shortage']];
                    }
                }
            }$order->update(['inventory_deducted' => true, 'stock_warnings' => $warnings]);
            $this->transition($order, 'kitchen_pending', $user);

            return $order->load($this->relations());
        });
    }

    public function status(Order $order, string $status, $user): Order
    {
        $role = $user->role?->slug;
        $roleStates = ['administrador' => ['preparing', 'prepared', 'ready', 'on_way', 'delivered'], 'cocina' => ['preparing', 'prepared', 'ready'], 'repartidor' => ['on_way', 'delivered']];
        if (! in_array($status, $roleStates[$role] ?? [])) {
            throw ValidationException::withMessages(['status' => 'Tu rol no puede asignar este estado.']);
        }
        $allowed = ['kitchen_pending' => ['preparing'], 'preparing' => ['prepared'], 'prepared' => ['ready'], 'ready' => ['on_way', 'delivered'], 'on_way' => ['delivered']];
        if (! in_array($status, $allowed[$order->status] ?? [])) {
            throw ValidationException::withMessages(['status' => 'Cambio de estado no permitido.']);
        }if ($order->type !== 'delivery' && $status === 'on_way') {
            throw ValidationException::withMessages(['status' => 'Los pedidos para recoger no pasan a reparto.']);
        }$this->transition($order, $status, $user);
        if ($status === 'delivered') {
            app(LoyaltyService::class)->award($order->fresh());
        }

return $order->fresh()->load($this->relations());
    }

    public function cancel(Order $order, $user, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $user, $comment) {
            if (in_array($order->status, ['delivered', 'cancelled'])) {
                throw ValidationException::withMessages(['status' => 'El pedido ya no puede cancelarse desde operación.']);
            }if ($order->inventory_deducted) {
                $moves = InventoryMovement::where('reference_type', Order::class)->where('reference_id', $order->id)->where('type', 'sale')->where('quantity', '<', 0)->get();
                if (! in_array($order->status, ['preparing', 'prepared', 'ready', 'on_way'])) {
                    foreach ($moves as $move) {
                        app(InventoryService::class)->move($move->batch, abs((float) $move->quantity), 'return', $user, $order, 'cancelled', $comment);
                    }
                } else {
                    foreach ($moves as $move) {
                        InventoryAdjustment::create(['branch_id' => $order->branch_id, 'ingredient_id' => $move->ingredient_id, 'inventory_batch_id' => $move->inventory_batch_id, 'user_id' => $user->id, 'quantity' => abs((float) $move->quantity), 'reason' => 'preparation_error', 'comment' => 'Merma por cancelación: '.$comment]);
                    }
                }
            }$this->transition($order, 'cancelled', $user, $comment);
            $order->reservations()->delete();

            return $order->fresh()->load($this->relations());
        });
    }

    public function expirePending(): int
    {
        $orders = Order::where('status', 'pending_payment')->where('pending_expires_at', '<=', now())->get();
        foreach ($orders as $order) {
            $order->update(['status' => 'draft']);
            $order->histories()->create(['from_status' => 'pending_payment', 'to_status' => 'draft', 'comment' => 'Tiempo de pago vencido']);
            $order->reservations()->delete();
        }

return $orders->count();
    }

    private function validatePayment(Order $o): void
    {
        if ($o->courtesy) {
            return;
        }if (abs((float) $o->payments()->sum('amount') - (float) $o->total) > .009) {
            throw ValidationException::withMessages(['payments' => 'Los pagos deben cubrir exactamente el total.']);
        }
    }

    private function addCombo(Order $order, array $row): float
    {
        $combo = Combo::with(['items.variant.product', 'items.options'])->findOrFail($row['combo_id']);
        abort_unless($combo->branch_id === $order->branch_id && $combo->active, 422);
        $selections = collect($row['components'] ?? [])->keyBy('combo_item_id');
        $item = $order->items()->create(['combo_id' => $combo->id, 'name' => $combo->name, 'quantity' => $row['quantity'], 'unit_price' => $combo->price, 'total' => (float) $combo->price * $row['quantity'], 'notes' => $row['notes'] ?? null]);
        $totals = [];
        foreach ($combo->items as $component) {
            $selection = $selections->get($component->id, []);
            $flavors = $selection['flavor_ids'] ?? [];
            if ($component->flavor_required && empty($flavors)) throw ValidationException::withMessages(['items' => "Debes elegir sabor para {$component->variant->name}."]);
            $allowedFlavors = $component->options->pluck('product_flavor_id')->filter();
            if ($allowedFlavors->isNotEmpty() && collect($flavors)->diff($allowedFlavors)->isNotEmpty()) throw ValidationException::withMessages(['items' => 'El combo contiene un sabor no permitido.']);
            $resolved = app(RecipeResolver::class)->resolve($component->variant, $flavors, $selection['modifier_ids'] ?? []);
            foreach ($resolved as $ingredient) $totals[$ingredient['ingredient_id']] = ($totals[$ingredient['ingredient_id']] ?? 0) + $ingredient['quantity'] * $component->quantity * $row['quantity'];
        }
        foreach ($totals as $ingredientId => $quantity) $item->ingredients()->create(['ingredient_id' => $ingredientId, 'quantity' => $quantity]);
        return (float) $item->total;
    }

    private function transition(Order $o, string $to, $user, ?string $comment = null): void
    {
        $from = $o->status;
        $o->update(['status' => $to]);
        $o->histories()->create(['user_id' => $user?->id, 'from_status' => $from, 'to_status' => $to, 'comment' => $comment]);
        OrderStatusChanged::dispatch($o->fresh());
    }

    private function expect(Order $o, array $states): void
    {
        if (! in_array($o->status,$states)) {
            throw ValidationException::withMessages(['status' => 'El estado actual no permite esta acción.']);
        }
    }

    private function relations(): array
    {
        return ['items.flavors', 'items.modifiers', 'items.ingredients.ingredient', 'payments', 'delivery', 'histories'];
    }
}
