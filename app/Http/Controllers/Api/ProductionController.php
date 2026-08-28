<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\ProductionBatch;
use App\Models\ProductionRecipe;
use App\Models\Unit;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionController extends Controller
{
    public function recipes(Request $request)
    {
        return ProductionRecipe::with([
            'items.ingredient.baseUnit',
            'outputIngredient.baseUnit',
            'yieldUnit',
        ])->where('branch_id', $request->user()->branch_id)->get();
    }

    public function storeRecipe(Request $request)
    {
        $data = $this->recipeData($request);

        $recipe = DB::transaction(function () use ($request, $data): ProductionRecipe {
            $this->validateRecipeReferences($data, $request->user()->branch_id);

            $recipe = ProductionRecipe::create([
                'branch_id' => $request->user()->branch_id,
                'output_ingredient_id' => $data['output_ingredient_id'],
                'name' => $data['name'],
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit_id' => $data['yield_unit_id'],
                'shelf_life_days' => $data['shelf_life_days'],
                'active' => $data['active'] ?? true,
            ]);
            $recipe->items()->createMany($data['items']);

            return $recipe;
        });

        return response()->json($this->loadRecipe($recipe), 201);
    }

    public function updateRecipe(Request $request, ProductionRecipe $recipe)
    {
        abort_unless($recipe->branch_id === $request->user()->branch_id, 404);
        $data = $this->recipeData($request);

        $recipe = DB::transaction(function () use ($request, $recipe, $data): ProductionRecipe {
            $recipe = ProductionRecipe::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            abort_unless($recipe->branch_id === $request->user()->branch_id, 404);
            $this->validateRecipeReferences($data, $request->user()->branch_id);

            $recipe->update(collect($data)->except('items')->all());
            $recipe->items()->delete();
            $recipe->items()->createMany($data['items']);

            return $recipe;
        });

        return $this->loadRecipe($recipe);
    }

    public function batches(Request $request)
    {
        return ProductionBatch::with(['recipe.outputIngredient', 'outputs.inventoryBatch'])
            ->where('branch_id', $request->user()->branch_id)
            ->latest('produced_at')
            ->paginate(25);
    }

    public function produce(Request $request, InventoryService $inventory)
    {
        $data = $request->validate([
            'production_recipe_id' => 'required|integer|exists:production_recipes,id',
            // Transitional compatibility: old clients may send it, but it can
            // no longer choose or override the recipe output.
            'output_ingredient_id' => 'sometimes|required|integer|exists:ingredients,id',
            'multiplier' => 'required|numeric|gt:0',
            'produced_at' => 'nullable|date|before_or_equal:now',
            'notes' => 'nullable|string',
            'outputs' => 'nullable|array|min:1',
            'outputs.*.portion_name' => 'required|string|max:100|distinct',
            'outputs.*.quantity' => 'required|integer|min:1',
            'outputs.*.grams_per_portion' => 'required|numeric|gt:0',
        ]);

        $batch = DB::transaction(function () use ($request, $data, $inventory): ProductionBatch {
            $recipe = ProductionRecipe::with(['items', 'yieldUnit'])
                ->whereKey($data['production_recipe_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($recipe->branch_id !== $request->user()->branch_id) {
                throw ValidationException::withMessages([
                    'production_recipe_id' => 'La receta no pertenece a la sucursal activa.',
                ]);
            }
            if (! $recipe->active) {
                throw ValidationException::withMessages([
                    'production_recipe_id' => 'La receta de producción está inactiva.',
                ]);
            }
            if (! $recipe->output_ingredient_id) {
                throw ValidationException::withMessages([
                    'production_recipe_id' => 'La receta debe configurarse con un insumo de salida antes de producir.',
                ]);
            }
            if (isset($data['output_ingredient_id']) && (int) $data['output_ingredient_id'] !== (int) $recipe->output_ingredient_id) {
                throw ValidationException::withMessages([
                    'output_ingredient_id' => 'La salida enviada no coincide con la salida configurada en la receta.',
                ]);
            }

            $referenceData = [
                'output_ingredient_id' => $recipe->output_ingredient_id,
                'yield_unit_id' => $recipe->yield_unit_id,
                'items' => $recipe->items->map->only(['ingredient_id', 'quantity'])->all(),
            ];
            $references = $this->validateRecipeReferences($referenceData, $recipe->branch_id);
            $output = $references['output'];

            $producedAt = isset($data['produced_at'])
                ? CarbonImmutable::parse($data['produced_at'])
                : CarbonImmutable::now();
            if ($producedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'produced_at' => 'La fecha de producción no puede estar en el futuro.',
                ]);
            }

            $multiplier = (float) $data['multiplier'];
            $quantity = (float) $recipe->yield_quantity
                * ((float) $recipe->yieldUnit->base_factor / (float) $output->baseUnit->base_factor)
                * $multiplier;
            if (! is_finite($quantity) || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'multiplier' => 'El rendimiento calculado debe ser mayor que cero.',
                ]);
            }
            $this->validatePortions($data['outputs'] ?? [], $recipe, $multiplier);

            $batch = ProductionBatch::create([
                'branch_id' => $recipe->branch_id,
                'production_recipe_id' => $recipe->id,
                'user_id' => $request->user()->id,
                'multiplier' => $multiplier,
                'produced_at' => $producedAt,
                'expires_at' => $producedAt->addDays($recipe->shelf_life_days)->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $inputCost = 0.0;
            foreach ($recipe->items->sortBy('ingredient_id') as $item) {
                /** @var Ingredient $ingredient */
                $ingredient = $references['ingredients']->get($item->ingredient_id);
                $needed = (float) $item->quantity * $multiplier;
                $result = $inventory->consumeFefo(
                    $ingredient,
                    $needed,
                    'production_input',
                    $request->user(),
                    $batch,
                );
                if ($result['shortage'] > 0) {
                    throw ValidationException::withMessages([
                        'stock' => "Faltan {$result['shortage']} {$ingredient->baseUnit->symbol} de {$ingredient->name}.",
                    ]);
                }
                $inputCost += collect($result['movements'])->sum(
                    fn ($movement) => abs((float) $movement->quantity) * (float) $movement->batch->unit_cost,
                );
            }

            $lot = InventoryBatch::create([
                'branch_id' => $batch->branch_id,
                'ingredient_id' => $output->id,
                'lot_code' => 'PROD-'.$batch->id,
                'received_at' => $producedAt->toDateString(),
                'expires_at' => $batch->expires_at,
                'initial_quantity' => $quantity,
                'available_quantity' => 0,
                'unit_cost' => $quantity > 0 ? $inputCost / $quantity : 0,
            ]);
            $inventory->move($lot, $quantity, 'production_output', $request->user(), $batch);
            $batch->outputs()->create([
                'ingredient_id' => $output->id,
                'inventory_batch_id' => $lot->id,
                'quantity' => $quantity,
            ]);
            foreach ($data['outputs'] ?? [] as $portion) {
                $batch->outputs()->create([
                    'ingredient_id' => $output->id,
                    'inventory_batch_id' => $lot->id,
                    'quantity' => $portion['quantity'],
                    'portion_name' => $portion['portion_name'],
                    'grams_per_portion' => $portion['grams_per_portion'],
                ]);
            }
            $inventory->refreshAlerts($output);

            return $batch;
        });

        return response()->json($batch->load(['recipe.outputIngredient', 'outputs.inventoryBatch']), 201);
    }

    private function recipeData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'output_ingredient_id' => 'required|integer|exists:ingredients,id',
            'yield_quantity' => 'required|numeric|gt:0',
            'yield_unit_id' => 'required|integer|exists:units,id',
            'shelf_life_days' => 'required|integer|min:0',
            'active' => 'sometimes|boolean',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|integer|distinct|exists:ingredients,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);
    }

    /**
     * @return array{output: Ingredient, ingredients: Collection<int, Ingredient>}
     */
    private function validateRecipeReferences(array $data, int $branchId): array
    {
        $yieldUnit = Unit::query()->whereKey($data['yield_unit_id'])->lockForUpdate()->firstOrFail();
        if (! $yieldUnit->active) {
            throw ValidationException::withMessages([
                'yield_unit_id' => 'La unidad de rendimiento está inactiva.',
            ]);
        }

        $ingredientIds = collect($data['items'])->pluck('ingredient_id')
            ->push($data['output_ingredient_id'])
            ->unique()
            ->sort()
            ->values();
        $ingredients = Ingredient::with('baseUnit')
            ->whereIn('id', $ingredientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Ingredient|null $output */
        $output = $ingredients->get($data['output_ingredient_id']);
        if (! $output || $output->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => 'El insumo de salida no pertenece a la sucursal activa.',
            ]);
        }
        if (! $output->active) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => 'El insumo de salida está inactivo.',
            ]);
        }
        if ($yieldUnit->dimension !== $output->baseUnit?->dimension) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => 'La unidad de rendimiento no es compatible con la unidad base del insumo producido.',
            ]);
        }

        foreach (collect($data['items'])->pluck('ingredient_id')->unique() as $ingredientId) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($ingredientId);
            if (! $ingredient || $ingredient->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'items' => 'Todos los insumos de la receta deben pertenecer a la sucursal activa.',
                ]);
            }
            if (! $ingredient->active) {
                throw ValidationException::withMessages([
                    'items' => "El insumo {$ingredient->name} está inactivo.",
                ]);
            }
        }

        return ['output' => $output, 'ingredients' => $ingredients];
    }

    private function validatePortions(array $portions, ProductionRecipe $recipe, float $multiplier): void
    {
        if (! $portions) {
            return;
        }
        if ($recipe->yieldUnit->dimension !== 'mass') {
            throw ValidationException::withMessages([
                'outputs' => 'Las porciones en gramos solo pueden usarse con recetas de rendimiento por masa.',
            ]);
        }

        $yieldInGrams = (float) $recipe->yield_quantity
            * (float) $recipe->yieldUnit->base_factor
            * $multiplier;
        $portionedGrams = collect($portions)->sum(
            fn (array $portion): float => (float) $portion['quantity'] * (float) $portion['grams_per_portion'],
        );
        if (! is_finite($portionedGrams) || $portionedGrams > $yieldInGrams + .0001) {
            throw ValidationException::withMessages([
                'outputs' => 'Las porciones declaradas exceden el rendimiento total de la producción.',
            ]);
        }
    }

    private function loadRecipe(ProductionRecipe $recipe): ProductionRecipe
    {
        return $recipe->load([
            'items.ingredient.baseUnit',
            'outputIngredient.baseUnit',
            'yieldUnit',
        ]);
    }
}
