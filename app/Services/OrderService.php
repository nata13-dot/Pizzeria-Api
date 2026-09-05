<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Exceptions\StockShortageException;
use App\Models\Branch;
use App\Models\Combo;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly BranchSettings $settings,
        private readonly InventoryService $inventory,
        private readonly RecipeResolver $recipes,
        private readonly PushService $push,
    ) {}

    public function create(array $data, $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            // This row is the sequence lock for daily order numbers in the branch.
            $branch = Branch::query()->whereKey($user->branch_id)->lockForUpdate()->firstOrFail();
            if (! empty($data['idempotency_key'])) {
                $existing = Order::query()
                    ->where('branch_id', $branch->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    return $existing->load($this->relations());
                }
            }
            $date = CarbonImmutable::now($branch->timezone ?: config('app.timezone'))->toDateString();
            $number = (int) Order::query()
                ->where('branch_id', $branch->id)
                ->whereDate('order_date', $date)
                ->where('status', '!=', 'cancelled')
                ->max('daily_number') + 1;
            $deliveryFee = $this->deliveryFee($branch->id, $data);

            $order = Order::create([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'order_date' => $date,
                'daily_number' => $number,
                'status' => $data['status'],
                'type' => $data['type'],
                'sales_channel' => $data['sales_channel'] ?? 'local',
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'pending_expires_at' => $data['status'] === 'pending_payment'
                    ? now()->addMinutes(max(1, $this->settings->integer($branch->id, 'pending_payment_minutes')))
                    : null,
                'discount' => $data['discount'] ?? 0,
                'delivery_fee' => $deliveryFee,
                'courtesy' => $data['courtesy'] ?? false,
                'collect_on_delivery' => $data['collect_on_delivery'] ?? false,
                'notes' => $data['notes'] ?? null,
            ]);

            $subtotal = 0.0;
            foreach ($data['items'] as $row) {
                if (isset($row['combo_id'])) {
                    $subtotal += $this->addCombo($order, $row);

                    continue;
                }

                $variant = ProductVariant::with('product')->findOrFail($row['product_variant_id']);
                abort_unless($variant->product->branch_id === $branch->id, 404);

                $modifierIds = array_values(array_unique($row['modifier_ids'] ?? []));
                $modifierRules = $variant->modifierRules()
                    ->with('modifier')
                    ->whereIn('modifier_id', $modifierIds)
                    ->get();
                if ($modifierRules->count() !== count($modifierIds)) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno de los modificadores no está permitido para el producto.',
                    ]);
                }

                $flavorIds = array_values(array_unique($row['flavor_ids'] ?? []));
                $unitPrice = (float) $variant->price + $this->selectionExtra($variant, $flavorIds);
                foreach ($modifierRules as $rule) {
                    $unitPrice += (float) ($rule->price_override ?? $rule->modifier->price);
                }
                $item = $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'name' => trim($variant->product->name.' '.$variant->name),
                    'quantity' => $row['quantity'],
                    'unit_price' => $unitPrice,
                    'total' => $unitPrice * $row['quantity'],
                    'notes' => $row['notes'] ?? null,
                ]);

                $ingredients = $this->recipes->resolve($variant, $flavorIds, $modifierIds);
                foreach ($ingredients as $ingredient) {
                    $item->ingredients()->create([
                        'ingredient_id' => $ingredient['ingredient_id'],
                        'quantity' => $ingredient['quantity'] * $row['quantity'],
                    ]);
                }
                foreach ($flavorIds as $flavorId) {
                    $item->flavors()->create([
                        'product_flavor_id' => $flavorId,
                        'ratio' => 1 / max(1, count($flavorIds)),
                    ]);
                }
                foreach ($modifierRules as $rule) {
                    $item->modifiers()->create([
                        'modifier_id' => $rule->modifier_id,
                        'name' => $rule->modifier->name,
                        'price' => $rule->price_override ?? $rule->modifier->price,
                    ]);
                }
                $subtotal += (float) $item->total;
            }

            $total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + $deliveryFee);
            $order->update(['subtotal' => $subtotal, 'total' => $total]);

            if ($data['status'] === 'pending_payment') {
                $requirements = $this->requirements($order);
                $order->update(['stock_warnings' => $this->warnings($requirements, true)]);
                foreach ($requirements as $requirement) {
                    $order->reservations()->create([
                        'ingredient_id' => $requirement['ingredient']->id,
                        'quantity' => $requirement['quantity'],
                        'expires_at' => $order->pending_expires_at,
                    ]);
                }
            }
            if ($data['type'] === 'delivery' && isset($data['delivery'])) {
                $order->delivery()->create($data['delivery']);
            }
            foreach ($data['payments'] ?? [] as $payment) {
                $order->payments()->create($payment + ['user_id' => $user->id]);
            }
            if ($data['status'] === 'confirmed') {
                $this->validatePayment($order);
            }
            $order->histories()->create(['user_id' => $user->id, 'to_status' => $data['status']]);
            $this->broadcastAfterCommit($order, $user?->id);

            return $order->load($this->relations());
        });
    }

    public function confirm(Order $order, array $payments, $user): Order
    {
        return DB::transaction(function () use ($order, $payments, $user) {
            $order = $this->locked($order);
            if ($order->status === 'confirmed') {
                return $order->load($this->relations());
            }
            $this->expect($order, ['draft', 'pending_payment']);
            $order->payments()->delete();
            foreach ($payments as $payment) {
                $order->payments()->create($payment + ['user_id' => $user->id]);
            }
            $this->validatePayment($order);
            $this->transition($order, 'confirmed', $user);
            $order->reservations()->delete();

            return $order->fresh()->load($this->relations());
        });
    }

    public function sendToKitchen(Order $order, $user, bool $allowShortage = false, bool $ignoreSchedule = false): Order
    {
        abort_if(
            $allowShortage && ! $user?->hasPermission('stock.override'),
            403,
            'Se requiere el permiso para autorizar faltantes de inventario.',
        );

        $blockedWarnings = [];
        $result = DB::transaction(function () use ($order, $user, $allowShortage, $ignoreSchedule, &$blockedWarnings) {
            $order = $this->locked($order);
            if ($order->inventory_deducted && in_array($order->status, ['kitchen_pending', 'preparing', 'prepared', 'ready', 'on_way', 'delivered'], true)) {
                return $order->load($this->relations());
            }
            $this->expect($order, ['confirmed']);

            if ($order->scheduled_at && ! $ignoreSchedule) {
                $leadMinutes = max(0, $this->settings->integer($order->branch_id, 'kitchen_lead_minutes'));
                if (now()->lt($order->scheduled_at->copy()->subMinutes($leadMinutes))) {
                    throw ValidationException::withMessages([
                        'scheduled_at' => 'El pedido aún no entra en su ventana de preparación.',
                    ]);
                }
            }

            $requirements = $this->requirements($order);
            $warnings = $this->warnings($requirements, true);
            if ($warnings && ! $allowShortage) {
                $order->update(['stock_warnings' => $warnings]);
                $blockedWarnings = $warnings;

                return null;
            }

            foreach ($requirements as $requirement) {
                $this->inventory->consumeFefo(
                    $requirement['ingredient'],
                    $requirement['quantity'],
                    'sale',
                    $user,
                    $order,
                    $allowShortage,
                );
            }

            $order->update([
                'inventory_deducted' => true,
                'stock_warnings' => $warnings,
                'stock_shortage_authorized_by' => $warnings ? $user?->id : null,
                'stock_shortage_authorized_at' => $warnings ? now() : null,
            ]);
            $this->transition($order, 'kitchen_pending', $user);

            return $order->fresh()->load($this->relations());
        });

        if ($blockedWarnings) {
            throw new StockShortageException($blockedWarnings);
        }

        return $result;
    }

    public function dispatchScheduled(): int
    {
        $sent = 0;
        Branch::query()->where('active', true)->each(function (Branch $branch) use (&$sent): void {
            $leadMinutes = max(0, $this->settings->integer($branch->id, 'kitchen_lead_minutes'));
            Order::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'confirmed')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now()->addMinutes($leadMinutes))
                ->orderBy('scheduled_at')
                ->each(function (Order $order) use (&$sent): void {
                    try {
                        $this->sendToKitchen($order, $order->user, false, true);
                        $sent++;
                    } catch (StockShortageException) {
                        // A cashier must explicitly authorize production with shortage.
                    } catch (ValidationException) {
                        // Another worker may already have transitioned this order.
                    }
                });
        });

        return $sent;
    }

    public function status(Order $order, string $status, $user): Order
    {
        return DB::transaction(function () use ($order, $status, $user) {
            $order = $this->locked($order);
            if ($order->status === $status) {
                return $order->load($this->relations());
            }
            $requiredPermission = match ($status) {
                'preparing', 'prepared', 'ready' => 'kitchen.use',
                'on_way' => 'delivery.use',
                'delivered' => $order->type === 'delivery' ? 'delivery.use' : 'pos.use',
            };
            if (! $user->hasPermission($requiredPermission)) {
                throw ValidationException::withMessages(['status' => 'Tu rol no puede asignar este estado.']);
            }

            $allowed = [
                'kitchen_pending' => ['preparing'],
                'preparing' => ['prepared'],
                'prepared' => ['ready'],
                'ready' => $order->type === 'delivery' ? ['on_way'] : ['delivered'],
                'on_way' => ['delivered'],
            ];
            if (! in_array($status, $allowed[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Cambio de estado no permitido.']);
            }
            if ($order->type !== 'delivery' && $status === 'on_way') {
                throw ValidationException::withMessages(['status' => 'Los pedidos para recoger no pasan a reparto.']);
            }
            if ($status === 'delivered' && ! $order->courtesy
                && (float) $order->payments()->sum('amount') + .009 < (float) $order->total) {
                throw ValidationException::withMessages([
                    'payments' => 'Debes registrar el pago completo antes de marcar el pedido como entregado.',
                ]);
            }

            $this->transition($order, $status, $user);
            if ($status === 'delivered') {
                app(LoyaltyService::class)->award($order->fresh());
            }

            return $order->fresh()->load($this->relations());
        });
    }

    public function cancel(Order $order, $user, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $user, $comment) {
            $order = $this->locked($order);
            if ($order->status === 'cancelled') {
                return $order->load($this->relations());
            }
            $advanced = in_array($order->status, ['preparing', 'prepared', 'ready', 'on_way', 'delivered'], true);
            abort_if(
                $advanced && ! $user->hasPermission('orders.cancel_advanced'),
                403,
                'Se requiere autorización administrativa para cancelar un pedido en preparación, reparto o ya entregado.',
            );
            if ($advanced && ! trim((string) $comment)) {
                throw ValidationException::withMessages([
                    'comment' => 'Debes indicar el motivo de la cancelación avanzada.',
                ]);
            }

            if ($order->inventory_deducted) {
                $movements = InventoryMovement::query()
                    ->where('reference_type', Order::class)
                    ->where('reference_id', $order->id)
                    ->where('type', 'sale')
                    ->where('quantity', '<', 0)
                    ->get();
                if (! in_array($order->status, ['preparing', 'prepared', 'ready', 'on_way', 'delivered'], true)) {
                    foreach ($movements as $movement) {
                        $this->inventory->move(
                            $movement->batch,
                            abs((float) $movement->quantity),
                            'return',
                            $user,
                            $order,
                            'cancelled',
                            $comment,
                        );
                    }
                } else {
                    foreach ($movements as $movement) {
                        InventoryAdjustment::create([
                            'branch_id' => $order->branch_id,
                            'ingredient_id' => $movement->ingredient_id,
                            'inventory_batch_id' => $movement->inventory_batch_id,
                            'user_id' => $user->id,
                            'quantity' => -abs((float) $movement->quantity),
                            'reason' => 'preparation_error',
                            'comment' => 'Merma por cancelación: '.($comment ?: 'sin comentario'),
                        ]);
                    }
                }
            }

            app(LoyaltyService::class)->reverseForCancellation($order, $user, trim((string) $comment) ?: 'Cancelación operativa');

            $this->transition($order, 'cancelled', $user, $comment);
            $order->reservations()->delete();

            return $order->fresh()->load($this->relations());
        });
    }

    public function expirePending(): int
    {
        $expired = 0;
        Order::query()
            ->where('status', 'pending_payment')
            ->where('pending_expires_at', '<=', now())
            ->each(function (Order $order) use (&$expired): void {
                DB::transaction(function () use ($order, &$expired): void {
                    $order = $this->locked($order);
                    if ($order->status !== 'pending_payment' || $order->pending_expires_at?->isFuture()) {
                        return;
                    }
                    $this->transition($order, 'draft', null, 'Tiempo de pago vencido');
                    $order->reservations()->delete();
                    $expired++;
                });
            });

        return $expired;
    }

    private function validatePayment(Order $order): void
    {
        if ($order->courtesy) {
            if ($order->collect_on_delivery || $order->payments()->exists()) {
                throw ValidationException::withMessages([
                    'payments' => 'Una cortesía no debe registrar pagos ni cobro contra entrega.',
                ]);
            }

            return;
        }
        if ($order->payments()->where('method', 'courtesy')->exists()) {
            throw ValidationException::withMessages([
                'payments' => 'El método cortesía requiere marcar toda la orden como cortesía.',
            ]);
        }
        $inactiveMethods = $order->payments()
            ->whereNotIn('method', $this->settings->activePaymentMethods($order->branch_id))
            ->pluck('method')
            ->unique();
        if ($inactiveMethods->isNotEmpty()) {
            throw ValidationException::withMessages([
                'payments' => 'Uno de los métodos de pago está desactivado en los ajustes.',
            ]);
        }
        $paid = (float) $order->payments()->sum('amount');
        if ($order->collect_on_delivery) {
            if ($order->type !== 'delivery' || $paid > (float) $order->total + .009) {
                throw ValidationException::withMessages([
                    'payments' => 'El cobro contra entrega solo aplica a domicilio y no puede exceder el total.',
                ]);
            }

            return;
        }
        if (abs($paid - (float) $order->total) > .009) {
            throw ValidationException::withMessages([
                'payments' => 'Los pagos deben cubrir exactamente el total.',
            ]);
        }
    }

    private function addCombo(Order $order, array $row): float
    {
        $combo = Combo::with(['items.variant.product', 'items.options'])->findOrFail($row['combo_id']);
        abort_unless($combo->branch_id === $order->branch_id && $combo->active, 422);
        $selectionRows = collect($row['components'] ?? []);
        if ($selectionRows->pluck('combo_item_id')->unique()->count() !== $selectionRows->count()
            || $selectionRows->pluck('combo_item_id')->diff($combo->items->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'El combo contiene un componente inválido o repetido.']);
        }
        $selections = $selectionRows->keyBy('combo_item_id');

        $totals = [];
        $components = [];
        $unitExtras = 0.0;
        foreach ($combo->items as $component) {
            abort_unless($component->variant?->product?->branch_id === $order->branch_id, 422);
            $selection = $selections->get($component->id, []);
            $flavors = array_values(array_unique($selection['flavor_ids'] ?? []));
            $modifierIds = array_values(array_unique($selection['modifier_ids'] ?? []));
            if ($component->flavor_required && empty($flavors)) {
                throw ValidationException::withMessages([
                    'items' => "Debes elegir sabor para {$component->variant->name}.",
                ]);
            }
            $allowedFlavors = $component->options->pluck('product_flavor_id')->filter();
            if ($allowedFlavors->isNotEmpty() && collect($flavors)->diff($allowedFlavors)->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'El combo contiene un sabor no permitido.']);
            }
            $allowedModifiers = $component->options->pluck('modifier_id')->filter();
            if ($allowedModifiers->isNotEmpty() && collect($modifierIds)->diff($allowedModifiers)->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'El combo contiene un modificador no permitido.']);
            }

            $resolved = $this->recipes->resolve(
                $component->variant,
                $flavors,
                $modifierIds,
            );
            foreach ($resolved as $ingredient) {
                $totals[$ingredient['ingredient_id']] = ($totals[$ingredient['ingredient_id']] ?? 0)
                    + $ingredient['quantity'] * $component->quantity * $row['quantity'];
            }

            $modifierRules = $component->variant->modifierRules()
                ->with('modifier')
                ->whereIn('modifier_id', $modifierIds)
                ->get();
            $modifierExtra = $modifierRules->sum(
                fn ($rule) => (float) ($rule->price_override ?? $rule->modifier->price),
            );
            $unitExtras += ($this->selectionExtra($component->variant, $flavors) + $modifierExtra)
                * (float) $component->quantity;
            $components[] = [
                'combo_item_id' => $component->id,
                'product_variant_id' => $component->variant->id,
                'name' => trim($component->variant->product->name.' '.$component->variant->name),
                'quantity' => (float) $component->quantity * (float) $row['quantity'],
                'flavors' => ProductFlavor::query()->whereIn('id', $flavors)->pluck('name')->all(),
                'modifiers' => $modifierRules->map(fn ($rule) => [
                    'id' => $rule->modifier_id,
                    'name' => $rule->modifier->name,
                    'price' => (float) ($rule->price_override ?? $rule->modifier->price),
                ])->values()->all(),
                'notes' => $selection['notes'] ?? null,
            ];
        }

        $unitPrice = (float) $combo->price + $unitExtras;
        $item = $order->items()->create([
            'combo_id' => $combo->id,
            'name' => $combo->name,
            'quantity' => $row['quantity'],
            'unit_price' => $unitPrice,
            'total' => $unitPrice * $row['quantity'],
            'notes' => $row['notes'] ?? null,
        ]);
        foreach ($totals as $ingredientId => $quantity) {
            $item->ingredients()->create(['ingredient_id' => $ingredientId, 'quantity' => $quantity]);
        }
        $item->components()->createMany($components);

        return (float) $item->total;
    }

    /** @return array<int, array{ingredient: Ingredient, quantity: float}> */
    private function requirements(Order $order): array
    {
        $order->loadMissing('items.ingredients.ingredient');
        $requirements = [];
        foreach ($order->items as $item) {
            foreach ($item->ingredients as $row) {
                if (! isset($requirements[$row->ingredient_id])) {
                    $requirements[$row->ingredient_id] = [
                        'ingredient' => $row->ingredient,
                        'quantity' => 0.0,
                    ];
                }
                $requirements[$row->ingredient_id]['quantity'] += (float) $row->quantity;
            }
        }

        return array_values($requirements);
    }

    private function warnings(array $requirements, bool $lock = false): array
    {
        $warnings = [];
        foreach ($requirements as $requirement) {
            $available = $this->inventory->availableToPromise($requirement['ingredient'], $lock);
            if ($available + .00001 < $requirement['quantity']) {
                $warnings[] = [
                    'ingredient_id' => $requirement['ingredient']->id,
                    'name' => $requirement['ingredient']->name,
                    'required' => $requirement['quantity'],
                    'available' => $available,
                    'shortage' => $requirement['quantity'] - $available,
                ];
            }
        }

        return $warnings;
    }

    private function selectionExtra(ProductVariant $variant, array $flavorIds): float
    {
        $count = count(array_unique($flavorIds));
        if ($count < 2) {
            return 0.0;
        }

        return match ($variant->product->type) {
            'pizza' => (float) $this->settings->get($variant->product->branch_id, 'half_and_half_extra'),
            'wings' => (float) $this->settings->get($variant->product->branch_id, 'additional_wing_flavor_extra') * ($count - 1),
            default => 0.0,
        };
    }

    private function deliveryFee(int $branchId, array $data): float
    {
        if (($data['type'] ?? null) !== 'delivery') {
            return 0.0;
        }

        $configuredZones = collect($this->settings->get($branchId, 'delivery_zones'))
            ->filter(fn ($zone) => is_array($zone));
        if ($configuredZones->isEmpty()) {
            return (float) ($data['delivery_fee'] ?? 0);
        }
        $zones = $configuredZones
            ->filter(fn ($zone) => is_array($zone) && ($zone['active'] ?? true));
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages([
                'delivery.delivery_zone' => 'No hay zonas de entrega activas.',
            ]);
        }

        $selected = trim((string) ($data['delivery']['delivery_zone'] ?? ''));
        $zone = $zones->first(fn ($candidate) => mb_strtolower(trim((string) ($candidate['name'] ?? ''))) === mb_strtolower($selected));
        if (! $zone) {
            throw ValidationException::withMessages([
                'delivery.delivery_zone' => 'Selecciona una zona de entrega activa.',
            ]);
        }

        return round(max(0, (float) ($zone['fee'] ?? 0)), 2);
    }

    private function transition(Order $order, string $to, $user, ?string $comment = null): void
    {
        $from = $order->status;
        $order->update(['status' => $to]);
        $order->histories()->create([
            'user_id' => $user?->id,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
        ]);
        $this->broadcastAfterCommit($order, $user?->id);
    }

    private function broadcastAfterCommit(Order $order, ?int $actorId = null): void
    {
        $orderId = $order->id;
        DB::afterCommit(function () use ($orderId, $actorId): void {
            $fresh = Order::find($orderId);
            if ($fresh) {
                try {
                    OrderStatusChanged::dispatch($fresh);
                } catch (\Throwable $exception) {
                    Log::warning('No se pudo publicar el cambio de pedido en tiempo real.', [
                        'order_id' => $fresh->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
                $this->notifyStatusChange($fresh, $actorId);
            }
        });
    }

    private function notifyStatusChange(Order $order, ?int $actorId): void
    {
        $notification = match ($order->status) {
            'kitchen_pending' => [
                'permission' => 'kitchen.use',
                'title' => 'Nuevo pedido para cocina',
                'body' => "Pedido #{$order->daily_number} listo para preparar.",
                'screen' => 'kitchen',
            ],
            'ready' => [
                'permission' => $order->type === 'delivery' ? 'delivery.use' : 'pos.use',
                'title' => $order->type === 'delivery' ? 'Pedido listo para repartir' : 'Pedido listo para entregar',
                'body' => "Pedido #{$order->daily_number} está listo.",
                'screen' => $order->type === 'delivery' ? 'delivery' : 'orders',
            ],
            'on_way' => [
                'permission' => 'pos.use',
                'title' => 'Pedido en reparto',
                'body' => "Pedido #{$order->daily_number} salió a reparto.",
                'screen' => 'orders',
            ],
            default => null,
        };

        if (! $notification) {
            return;
        }

        $recipients = User::query()
            ->with('role.permissions')
            ->where('branch_id', $order->branch_id)
            ->where('active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->role?->slug === 'administrador'
                || $user->role?->permissions->contains('slug', $notification['permission']));

        foreach ($recipients as $recipient) {
            $payload = [
                'order_id' => $order->id,
                'daily_number' => $order->daily_number,
                'status' => $order->status,
                'screen' => $notification['screen'],
            ];
            $recipient->notify(new SystemAlertNotification($notification['title'], $notification['body'], $payload));
            $this->push->send($recipient, $notification['title'], $notification['body'], $payload);
        }
    }

    private function locked(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function expect(Order $order, array $states): void
    {
        if (! in_array($order->status, $states, true)) {
            throw ValidationException::withMessages(['status' => 'El estado actual no permite esta acción.']);
        }
    }

    private function relations(): array
    {
        return [
            'items.flavors',
            'items.modifiers',
            'items.ingredients.ingredient',
            'items.components',
            'payments',
            'delivery',
            'histories',
        ];
    }
}
