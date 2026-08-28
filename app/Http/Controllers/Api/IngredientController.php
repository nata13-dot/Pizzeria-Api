<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\Unit;
use App\Services\BranchClock;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function index(Request $r)
    {
        return Ingredient::with(['baseUnit', 'type', 'presentations.equivalentUnit'])->where('branch_id', $r->user()->branch_id)->orderBy('name')->paginate($r->integer('per_page', 25));
    }

    public function store(Request $r, InventoryService $inventory, BranchClock $clock)
    {
        $data = $this->data($r);
        $data['branch_id'] = $r->user()->branch_id;
        $ingredient = DB::transaction(function () use ($r, $data, $inventory, $clock) {
            $ingredient = Ingredient::create(collect($data)->except(['initial_stock', 'initial_lot_code', 'initial_expires_at', 'initial_unit_cost'])->all());
            $quantity = (float) ($data['initial_stock'] ?? 0);
            if ($quantity > 0) {
                $batch = InventoryBatch::create(['branch_id' => $ingredient->branch_id, 'ingredient_id' => $ingredient->id, 'lot_code' => $data['initial_lot_code'] ?? 'INICIAL-'.$ingredient->id, 'received_at' => $clock->today($ingredient->branch_id), 'expires_at' => $data['initial_expires_at'] ?? null, 'initial_quantity' => $quantity, 'available_quantity' => 0, 'unit_cost' => $data['initial_unit_cost'] ?? 0]);
                $adjustment = InventoryAdjustment::create(['branch_id' => $ingredient->branch_id, 'ingredient_id' => $ingredient->id, 'inventory_batch_id' => $batch->id, 'user_id' => $r->user()->id, 'quantity' => $quantity, 'reason' => 'initial', 'comment' => 'Existencia inicial al registrar el insumo.']);
                $inventory->move($batch, $quantity, 'adjustment', $r->user(), $adjustment, 'initial', 'Existencia inicial al registrar el insumo.');
                $inventory->refreshAlerts($ingredient);
            }

            return $ingredient;
        });

        return response()->json($ingredient->load(['baseUnit', 'type', 'batches']), 201);
    }

    public function show(Request $r, Ingredient $ingredient)
    {
        $this->own($r, $ingredient);

        return $ingredient->load(['baseUnit', 'type', 'presentations.equivalentUnit', 'batches']);
    }

    public function update(Request $r, Ingredient $ingredient)
    {
        $this->own($r, $ingredient);
        $data = $this->data($r, true);

        $ingredient = DB::transaction(function () use ($ingredient, $data) {
            /** @var Ingredient $lockedIngredient */
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            if (isset($data['base_unit_id'])
                && (int) $data['base_unit_id'] !== (int) $lockedIngredient->base_unit_id
                && $this->hasUnitDependentRecords($lockedIngredient->id)) {
                throw ValidationException::withMessages([
                    'base_unit_id' => 'No se puede cambiar la unidad base después de registrar existencias, movimientos, presentaciones, recetas o producción.',
                ]);
            }

            $lockedIngredient->update($data);

            return $lockedIngredient;
        });

        return $ingredient->load(['baseUnit', 'type']);
    }

    public function destroy(Request $r, Ingredient $ingredient)
    {
        $this->own($r, $ingredient);
        $ingredient->update(['active' => false]);

        return response()->noContent();
    }

    public function presentation(Request $r, Ingredient $ingredient)
    {
        $this->own($r, $ingredient);
        $data = $this->presentationData($r, $ingredient);
        $presentation = DB::transaction(function () use ($ingredient, $data) {
            /** @var Ingredient $lockedIngredient */
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            if (! $lockedIngredient->active) {
                throw ValidationException::withMessages(['ingredient_id' => 'El ingrediente está inactivo.']);
            }

            $presentationData = $data;
            $presentationData['base_quantity'] = $this->baseQuantity(
                $lockedIngredient,
                (int) $data['equivalent_unit_id'],
                (float) $data['quantity'],
            );

            return $lockedIngredient->presentations()->create($presentationData);
        });

        return response()->json($presentation->load('equivalentUnit'), 201);
    }

    public function updatePresentation(Request $r, Ingredient $ingredient, IngredientPresentation $presentation)
    {
        $this->ownPresentation($r, $ingredient, $presentation);
        $data = $this->presentationData($r, $ingredient, true, $presentation);

        $presentation = DB::transaction(function () use ($ingredient, $presentation, $data): IngredientPresentation {
            /** @var Ingredient $lockedIngredient */
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            /** @var IngredientPresentation $lockedPresentation */
            $lockedPresentation = IngredientPresentation::query()->lockForUpdate()->findOrFail($presentation->id);
            abort_unless($lockedPresentation->ingredient_id === $lockedIngredient->id, 404);

            $equivalentUnitId = (int) ($data['equivalent_unit_id'] ?? $lockedPresentation->equivalent_unit_id);
            $quantity = (float) ($data['quantity'] ?? $lockedPresentation->quantity);
            if (array_key_exists('equivalent_unit_id', $data) || array_key_exists('quantity', $data)) {
                $data['base_quantity'] = $this->baseQuantity($lockedIngredient, $equivalentUnitId, $quantity);
            }
            $lockedPresentation->update($data);

            return $lockedPresentation;
        });

        return $presentation->load('equivalentUnit');
    }

    public function destroyPresentation(Request $r, Ingredient $ingredient, IngredientPresentation $presentation)
    {
        $this->ownPresentation($r, $ingredient, $presentation);
        $presentation->update(['active' => false]);

        return response()->noContent();
    }

    private function data(Request $r, bool $partial = false)
    {
        $s = $partial ? 'sometimes|' : '';

        return $r->validate([
            'ingredient_type_id' => 'nullable|exists:ingredient_types,id',
            'base_unit_id' => [
                $partial ? 'sometimes' : 'required',
                'integer',
                Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'name' => $s.'required|string|max:150',
            'sku' => 'nullable|string|max:100',
            'minimum_stock' => 'sometimes|numeric|min:0',
            'critical_stock' => 'sometimes|numeric|min:0',
            'shelf_life_days' => 'nullable|integer|min:0',
            'expiry_alert_days' => 'sometimes|integer|min:0',
            'active' => 'sometimes|boolean',
            'initial_stock' => ($partial ? 'prohibited' : 'sometimes').'|numeric|min:0',
            'initial_lot_code' => 'nullable|string|max:100',
            'initial_expires_at' => 'nullable|date',
            'initial_unit_cost' => 'sometimes|numeric|min:0',
        ]);
    }

    private function hasUnitDependentRecords(int $ingredientId): bool
    {
        $references = [
            ['ingredient_presentations', 'ingredient_id'],
            ['inventory_batches', 'ingredient_id'],
            ['inventory_movements', 'ingredient_id'],
            ['inventory_adjustments', 'ingredient_id'],
            ['purchase_items', 'ingredient_id'],
            ['recipe_items', 'ingredient_id'],
            ['modifier_recipe_items', 'ingredient_id'],
            ['production_recipe_items', 'ingredient_id'],
            ['production_batch_outputs', 'ingredient_id'],
            ['production_recipes', 'output_ingredient_id'],
            ['order_item_ingredients', 'ingredient_id'],
            ['stock_reservations', 'ingredient_id'],
        ];

        foreach ($references as [$table, $column]) {
            if (DB::table($table)->where($column, $ingredientId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function presentationData(
        Request $request,
        Ingredient $ingredient,
        bool $partial = false,
        ?IngredientPresentation $presentation = null,
    ): array {
        $sometimes = $partial ? 'sometimes|' : '';

        return $request->validate([
            'name' => [
                $partial ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('ingredient_presentations', 'name')
                    ->where('ingredient_id', $ingredient->id)
                    ->ignore($presentation?->id),
            ],
            'quantity' => $sometimes.'required|numeric|gt:0',
            'equivalent_unit_id' => [
                $partial ? 'sometimes' : 'required',
                'integer',
                Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'supplier_sku' => 'nullable|string|max:100',
            'active' => 'sometimes|boolean',
        ]);
    }

    private function baseQuantity(Ingredient $ingredient, int $equivalentUnitId, float $quantity): float
    {
        $units = Unit::query()
            ->whereIn('id', [$ingredient->base_unit_id, $equivalentUnitId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        /** @var Unit|null $equivalent */
        $equivalent = $units->get($equivalentUnitId);
        /** @var Unit|null $base */
        $base = $units->get($ingredient->base_unit_id);
        if (! $equivalent?->active) {
            throw ValidationException::withMessages(['equivalent_unit_id' => 'La unidad equivalente está inactiva.']);
        }
        if (! $base?->active) {
            throw ValidationException::withMessages(['base_unit_id' => 'La unidad base del ingrediente está inactiva.']);
        }
        if ($equivalent->dimension !== $base->dimension
            || (float) $equivalent->base_factor <= 0
            || (float) $base->base_factor <= 0) {
            throw ValidationException::withMessages(['equivalent_unit_id' => 'La unidad no es compatible con la unidad base.']);
        }

        return $quantity * ((float) $equivalent->base_factor / (float) $base->base_factor);
    }

    private function own(Request $r, Ingredient $i): void
    {
        abort_unless($i->branch_id === $r->user()->branch_id, 404);
    }

    private function ownPresentation(Request $request, Ingredient $ingredient, IngredientPresentation $presentation): void
    {
        $this->own($request, $ingredient);
        abort_unless($presentation->ingredient_id === $ingredient->id, 404);
    }
}
