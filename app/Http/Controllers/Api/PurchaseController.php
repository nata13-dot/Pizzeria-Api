<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashDay;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\InventoryBatch;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\BranchClock;
use App\Services\InventoryService;
use App\Services\Money;
use App\Services\PurchaseCosts;
use App\Services\PurchasePresentationRevision;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $r)
    {
        return Purchase::with(['supplier', 'items.ingredient.baseUnit', 'items.presentation.equivalentUnit'])->where('branch_id', $r->user()->branch_id)->latest('purchased_at')->paginate(25);
    }

    public function show(Request $r, Purchase $purchase)
    {
        abort_unless($purchase->branch_id === $r->user()->branch_id, 404);

        return $purchase->load(['supplier', 'items.ingredient.baseUnit', 'items.presentation.equivalentUnit']);
    }

    public function store(Request $r, InventoryService $inventory, BranchClock $clock, PurchasePresentationRevision $revisions)
    {
        $key = $r->header('Idempotency-Key');
        if ($key !== null) {
            validator(['idempotency_key' => $key], ['idempotency_key' => 'required|string|min:8|max:100|regex:/^[A-Za-z0-9._:-]+$/'])->validate();
            if ($replay = $this->replay($r, $key)) {
                return $replay;
            }
        }
        $data = $r->validate([
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')
                    ->where('branch_id', $r->user()->branch_id)
                    ->where('active', true),
            ],
            'purchased_at' => 'required|date_format:Y-m-d',
            'payment_source' => 'required|in:cash,owner,bank,credit,other',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.ingredient_presentation_id' => [
                'required',
                'integer',
                Rule::exists('ingredient_presentations', 'id')->where('active', true),
            ],
            'items.*.expected_presentation_revision' => 'nullable|string|size:64',
            'items.*.presentations_quantity' => 'required|numeric|gt:0',
            'items.*.total_cost' => 'required|numeric|min:0',
            'items.*.expires_at' => 'nullable|date|after_or_equal:purchased_at',
            'items.*.lot_code' => 'nullable|string|max:100',
        ]);
        if ($data['purchased_at'] > $clock->today($r->user()->branch_id)->toDateString()) {
            throw ValidationException::withMessages([
                'purchased_at' => 'La fecha de compra no puede ser futura en la zona horaria de la sucursal.',
            ]);
        }
        try {
            $purchase = $this->createPurchase($r, $data, $inventory, $revisions, $key);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent retry with the same key won the race; answer with its purchase.
            if ($key !== null && ($replay = $this->replay($r, $key))) {
                return $replay;
            }
            throw $exception;
        }

        return response()->json($purchase->load(['supplier', 'items.ingredient.baseUnit', 'items.presentation.equivalentUnit']), 201);
    }

    private function replay(Request $r, string $key)
    {
        $existing = Purchase::query()->where('branch_id', $r->user()->branch_id)->where('idempotency_key', $key)->first();

        return $existing ? response()->json($existing->load(['supplier', 'items.ingredient.baseUnit', 'items.presentation.equivalentUnit']))->header('Idempotent-Replay', 'true') : null;
    }

    private function createPurchase(Request $r, array $data, InventoryService $inventory, PurchasePresentationRevision $revisions, ?string $key): Purchase
    {
        return DB::transaction(function () use ($r, $data, $inventory, $revisions, $key) {
            if ($data['payment_source'] === 'cash') {
                $cashDay = CashDay::query()
                    ->where('branch_id', $r->user()->branch_id)
                    ->whereDate('date', $data['purchased_at'])
                    ->lockForUpdate()
                    ->first();
                if (! $cashDay) {
                    throw ValidationException::withMessages([
                        'payment_source' => 'Debes abrir la caja de esa fecha antes de registrar una compra en efectivo.',
                    ]);
                }
                if ($cashDay->closed_at) {
                    throw ValidationException::withMessages([
                        'payment_source' => 'No puedes registrar una compra en efectivo después de cerrar la caja de esa fecha.',
                    ]);
                }
            }

            if (isset($data['supplier_id'])) {
                $supplier = Supplier::query()
                    ->whereKey($data['supplier_id'])
                    ->where('branch_id', $r->user()->branch_id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $supplier) {
                    throw ValidationException::withMessages(['supplier_id' => 'El proveedor debe estar activo y pertenecer a la sucursal.']);
                }
            }

            $presentationIds = collect($data['items'])->pluck('ingredient_presentation_id')->unique()->sort()->values();
            $presentations = IngredientPresentation::query()
                ->whereIn('id', $presentationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $ingredientIds = $presentations->pluck('ingredient_id')->unique()->sort()->values();
            $ingredients = Ingredient::query()
                ->whereIn('id', $ingredientIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $unitIds = $presentations->pluck('equivalent_unit_id')
                ->merge($ingredients->pluck('base_unit_id'))
                ->unique()
                ->sort()
                ->values();
            $units = Unit::query()->whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($data['items'] as $index => $row) {
                /** @var IngredientPresentation|null $presentation */
                $presentation = $presentations->get($row['ingredient_presentation_id']);
                /** @var Ingredient|null $ingredient */
                $ingredient = $presentation ? $ingredients->get($presentation->ingredient_id) : null;
                if (! $presentation?->active || ! $ingredient?->active || $ingredient->branch_id !== $r->user()->branch_id) {
                    throw ValidationException::withMessages([
                        "items.{$index}.ingredient_presentation_id" => 'La presentación y el ingrediente deben estar activos y pertenecer a la sucursal.',
                    ]);
                }

                /** @var Unit|null $equivalentUnit */
                $equivalentUnit = $units->get($presentation->equivalent_unit_id);
                /** @var Unit|null $baseUnit */
                $baseUnit = $units->get($ingredient->base_unit_id);
                $unitsAreCompatible = $equivalentUnit?->active
                    && $baseUnit?->active
                    && $equivalentUnit->dimension === $baseUnit->dimension
                    && (float) $equivalentUnit->base_factor > 0
                    && (float) $baseUnit->base_factor > 0;
                $expectedBaseQuantity = $unitsAreCompatible
                    ? (float) $presentation->quantity * ((float) $equivalentUnit->base_factor / (float) $baseUnit->base_factor)
                    : 0;
                if ($presentation && $ingredient && $equivalentUnit && $baseUnit
                    && isset($row['expected_presentation_revision'])
                    && ! hash_equals(
                        $row['expected_presentation_revision'],
                        $revisions->make($presentation, $ingredient, $equivalentUnit, $baseUnit),
                    )) {
                    throw new HttpResponseException(response()->json([
                        'code' => 'presentation_changed',
                        'message' => 'Esta presentación cambió. Revísala antes de registrar la compra.',
                        'errors' => ["items.{$index}.ingredient_presentation_id" => ['Esta presentación cambió. Revísala antes de registrar la compra.']],
                    ], 409));
                }
                if (! $unitsAreCompatible
                    || (float) $presentation->quantity <= 0
                    || (float) $presentation->base_quantity <= 0
                    || abs((float) $presentation->base_quantity - $expectedBaseQuantity) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$index}.ingredient_presentation_id" => 'La presentación tiene una conversión de unidad inválida o inactiva.',
                    ]);
                }
            }

            $purchase = Purchase::create(['branch_id' => $r->user()->branch_id, 'supplier_id' => $data['supplier_id'] ?? null, 'user_id' => $r->user()->id, 'idempotency_key' => $key, 'purchased_at' => $data['purchased_at'], 'payment_source' => $data['payment_source'], 'notes' => $data['notes'] ?? null, 'total' => Money::sum(collect($data['items'])->pluck('total_cost'))]);
            foreach ($data['items'] as $row) {
                $row['total_cost'] = Money::normalize($row['total_cost']);
                /** @var IngredientPresentation $presentation */
                $presentation = $presentations->get($row['ingredient_presentation_id']);
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients->get($presentation->ingredient_id);
                $baseQty = (float) $presentation->base_quantity * (float) $row['presentations_quantity'];
                $item = $purchase->items()->create(['ingredient_id' => $presentation->ingredient_id, 'ingredient_presentation_id' => $presentation->id, 'presentations_quantity' => $row['presentations_quantity'], 'base_quantity' => $baseQty, 'total_cost' => $row['total_cost'], 'base_unit_cost' => $baseQty ? ((float) $row['total_cost'] / $baseQty) : 0, 'expires_at' => $row['expires_at'] ?? null, 'lot_code' => $row['lot_code'] ?? null]);
                $batch = InventoryBatch::create(['branch_id' => $purchase->branch_id, 'ingredient_id' => $presentation->ingredient_id, 'purchase_item_id' => $item->id, 'lot_code' => $row['lot_code'] ?? null, 'received_at' => $data['purchased_at'], 'expires_at' => $row['expires_at'] ?? null, 'initial_quantity' => $baseQty, 'available_quantity' => 0, 'unit_cost' => $item->base_unit_cost]);
                $inventory->move($batch, $baseQty, 'purchase', $r->user(), $purchase);
                $inventory->refreshAlerts($ingredient);
            }

            return $purchase;
        });
    }

    /** Ingredients below their minimum with a suggested presentation and amount to reach it. */
    public function suggestions(Request $r, InventoryService $inventory, PurchasePresentationRevision $revisions, PurchaseCosts $costs)
    {
        $branchId = $r->user()->branch_id;
        $rows = $inventory->stockQuery($branchId)->where('ingredients.active', true)->where('ingredients.minimum_stock', '>', 0)
            ->with(['presentations' => fn ($q) => $q->where('active', true)->orderBy('base_quantity'), 'presentations.equivalentUnit'])
            ->orderBy('ingredients.name')->get()
            ->filter(fn ($row) => (float) $row->missing_to_minimum > 0.00001)
            ->sortBy(fn ($row) => ['out' => 0, 'critical' => 1, 'low' => 2][$row->stock_status] ?? 3)
            ->take(60)->values();
        $last = $costs->lastUnitCosts($branchId, $rows->flatMap(fn ($row) => $row->presentations->pluck('id'))->all());
        $lastUsed = \App\Models\PurchaseItem::query()->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.branch_id', $branchId)->whereIn('purchase_items.ingredient_id', $rows->pluck('id'))
            ->orderByDesc('purchases.purchased_at')->orderByDesc('purchase_items.id')
            ->get(['purchase_items.ingredient_id', 'purchase_items.ingredient_presentation_id'])->unique('ingredient_id')
            ->pluck('ingredient_presentation_id', 'ingredient_id');

        return ['data' => $rows->map(function (Ingredient $row) use ($revisions, $last, $lastUsed) {
            $presentations = $row->presentations->filter(fn ($p) => $p->equivalentUnit && (float) $p->base_quantity > 0)->values();
            $presentations->each(function (IngredientPresentation $p) use ($row, $revisions, $last): void {
                if ($row->baseUnit) {
                    $p->setAttribute('purchase_revision', $revisions->make($p, $row, $p->equivalentUnit, $row->baseUnit));
                }
                $p->setAttribute('last_unit_cost', $last[$p->id] ?? null);
            });
            $chosen = $presentations->firstWhere('id', $lastUsed[$row->id] ?? null) ?? $presentations->first();

            return [
                'ingredient' => $row->only(['id', 'name', 'sku', 'base_unit_id', 'current_stock', 'minimum_stock', 'missing_to_minimum', 'stock_status']) + ['base_unit' => $row->baseUnit],
                'presentations' => $presentations->all(),
                'suggested_presentation_id' => $chosen?->id,
                'suggested_quantity' => $chosen ? (int) ceil(((float) $row->missing_to_minimum - 0.00001) / (float) $chosen->base_quantity) : null,
            ];
        })->all()];
    }
}
