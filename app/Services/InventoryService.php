<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly BranchClock $clock) {}

    public function move(
        InventoryBatch $batch,
        float $quantity,
        string $type,
        ?User $user,
        mixed $reference = null,
        ?string $reason = null,
        ?string $comment = null,
    ): InventoryMovement {
        if (! is_finite($quantity) || abs($quantity) <= .000001) {
            throw ValidationException::withMessages([
                'quantity' => 'El movimiento debe tener una cantidad válida distinta de cero.',
            ]);
        }
        if ($user && $batch->branch_id !== $user->branch_id) {
            throw ValidationException::withMessages(['inventory' => 'El lote pertenece a otra sucursal.']);
        }

        return DB::transaction(function () use ($batch, $quantity, $type, $user, $reference, $reason, $comment): InventoryMovement {
            $batch = InventoryBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $before = (float) $batch->available_quantity;
            $after = $before + $quantity;
            if ($after < -.000001) {
                throw ValidationException::withMessages([
                    'quantity' => 'El lote no tiene existencia suficiente.',
                ]);
            }
            $after = max(0, $after);
            $batch->update(['available_quantity' => $after]);

            return InventoryMovement::create([
                'branch_id' => $batch->branch_id,
                'ingredient_id' => $batch->ingredient_id,
                'inventory_batch_id' => $batch->id,
                'user_id' => $user?->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->id,
                'reason' => $reason,
                'comment' => $comment,
            ]);
        });
    }

    /**
     * Deducts usable batches in FEFO order. Expired inventory is never consumed.
     * Partial consumption is reserved for an explicitly authorized sale shortage.
     */
    public function consumeFefo(
        Ingredient $ingredient,
        float $quantity,
        string $type,
        ?User $user,
        mixed $reference = null,
        bool $allowPartial = false,
    ): array {
        if (! is_finite($quantity) || $quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad a consumir debe ser mayor que cero.',
            ]);
        }

        return DB::transaction(function () use ($ingredient, $quantity, $type, $user, $reference, $allowPartial): array {
            $batches = $this->usableBatches($ingredient)
                ->orderByRaw('expires_at IS NULL')
                ->orderBy('expires_at')
                ->orderBy('received_at')
                ->lockForUpdate()
                ->get();
            $available = (float) $batches->sum('available_quantity');
            if ($available + .00001 < $quantity && ! $allowPartial) {
                return ['consumed' => 0, 'shortage' => $quantity - $available, 'movements' => []];
            }

            $remaining = min($quantity, $available);
            $movements = [];
            foreach ($batches as $batch) {
                if ($remaining <= .00001) {
                    break;
                }
                $take = min($remaining, (float) $batch->available_quantity);
                $movements[] = $this->move($batch, -$take, $type, $user, $reference);
                $remaining -= $take;
            }

            $consumed = min($quantity, $available);
            $this->refreshAlerts($ingredient);

            return [
                'consumed' => $consumed,
                'shortage' => max(0, $quantity - $consumed),
                'movements' => $movements,
            ];
        });
    }

    public function usableStock(Ingredient $ingredient, bool $lock = false): float
    {
        $query = $this->usableBatches($ingredient);
        if ($lock) {
            $query->lockForUpdate();
        }

        return (float) $query->sum('available_quantity');
    }

    public function availableToPromise(Ingredient $ingredient, bool $lock = false): float
    {
        $physical = $this->usableStock($ingredient, $lock);
        $reservations = StockReservation::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('expires_at', '>', now())
            ->whereHas('order', fn (Builder $query) => $query->where('status', 'pending_payment'));
        $reserved = $lock
            ? (float) $reservations->lockForUpdate()->get()->sum('quantity')
            : (float) $reservations->sum('quantity');

        return max(0, $physical - $reserved);
    }

    public function refreshAlerts(Ingredient $ingredient): void
    {
        $today = $this->clock->today($ingredient->branch_id);
        $stock = $this->usableStock($ingredient);
        $stockType = $stock <= (float) $ingredient->critical_stock
            ? 'critical_stock'
            : ($stock <= (float) $ingredient->minimum_stock ? 'low_stock' : null);

        Alert::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNull('inventory_batch_id')
            ->whereIn('type', ['low_stock', 'critical_stock'])
            ->whereNull('resolved_at')
            ->when($stockType, fn (Builder $query) => $query->where('type', '!=', $stockType))
            ->update(['resolved_at' => now()]);

        if ($stockType) {
            Alert::firstOrCreate([
                'ingredient_id' => $ingredient->id,
                'inventory_batch_id' => null,
                'type' => $stockType,
                'resolved_at' => null,
            ], [
                'branch_id' => $ingredient->branch_id,
                'severity' => $stockType === 'critical_stock' ? 'critical' : 'warning',
                'message' => "{$ingredient->name}: ".($stockType === 'critical_stock' ? 'stock crítico' : 'stock bajo')." ({$stock}).",
            ]);
        }

        foreach ($ingredient->batches()->get() as $batch) {
            $expiryType = null;
            if ((float) $batch->available_quantity > 0 && $batch->expires_at) {
                $expiryType = $batch->expires_at->startOfDay()->lt($today)
                    ? 'expired'
                    : ($batch->expires_at->startOfDay()->lte($today->addDays($ingredient->expiry_alert_days)) ? 'expiring' : null);
            }

            Alert::query()
                ->where('inventory_batch_id', $batch->id)
                ->whereIn('type', ['expired', 'expiring'])
                ->whereNull('resolved_at')
                ->when($expiryType, fn (Builder $query) => $query->where('type', '!=', $expiryType))
                ->update(['resolved_at' => now()]);

            if ($expiryType) {
                Alert::firstOrCreate([
                    'inventory_batch_id' => $batch->id,
                    'type' => $expiryType,
                    'resolved_at' => null,
                ], [
                    'branch_id' => $ingredient->branch_id,
                    'ingredient_id' => $ingredient->id,
                    'severity' => $expiryType === 'expired' ? 'critical' : 'warning',
                    'message' => "{$ingredient->name}, lote {$batch->lot_code}: "
                        .($expiryType === 'expired' ? 'caducado.' : 'próximo a caducar.'),
                ]);
            }
        }
    }

    private function usableBatches(Ingredient $ingredient)
    {
        $today = $this->clock->today($ingredient->branch_id)->toDateString();

        return $ingredient->batches()
            ->where('available_quantity', '>', 0)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
            });
    }
}
