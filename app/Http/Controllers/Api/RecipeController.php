<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Services\RecipeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function show(Request $request, Recipe $recipe)
    {
        $this->ownRecipe($request, $recipe);

        return $recipe->load(['variant.product', 'flavor', 'items.ingredient']);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);
        $this->ownVariant($request, $variant);
        $this->assertRecipeReferences($request, $variant, $data['product_flavor_id'] ?? null, $data['items'], true);
        $this->assertRecipeSlotIsAvailable($variant, $data['product_flavor_id'] ?? null);

        $recipe = DB::transaction(function () use ($data) {
            $recipe = Recipe::create([
                'product_variant_id' => $data['product_variant_id'],
                'product_flavor_id' => $data['product_flavor_id'] ?? null,
                'name' => $data['name'],
                'active' => $data['active'] ?? true,
            ]);
            $recipe->items()->createMany($data['items']);

            return $recipe;
        });

        return response()->json($recipe->load(['flavor', 'items.ingredient']), 201);
    }

    public function update(Request $request, Recipe $recipe)
    {
        $this->ownRecipe($request, $recipe);
        $data = $request->validate($this->rules(true));
        $recipe->loadMissing(['variant.product', 'items']);
        $flavorId = array_key_exists('product_flavor_id', $data)
            ? $data['product_flavor_id']
            : $recipe->product_flavor_id;
        $items = $data['items'] ?? $recipe->items->map->only(['ingredient_id', 'quantity', 'component'])->all();
        $active = (bool) ($data['active'] ?? $recipe->active);

        $this->assertRecipeReferences($request, $recipe->variant, $flavorId, $items, $active);
        $this->assertRecipeSlotIsAvailable($recipe->variant, $flavorId, $recipe);

        DB::transaction(function () use ($recipe, $data, $flavorId): void {
            $recipe->update([
                ...collect($data)->except('items', 'product_variant_id')->all(),
                'product_flavor_id' => $flavorId,
            ]);

            if (array_key_exists('items', $data)) {
                $recipe->items()->delete();
                $recipe->items()->createMany($data['items']);
            }
        });

        return $recipe->fresh()->load(['variant.product', 'flavor', 'items.ingredient']);
    }

    public function destroy(Request $request, Recipe $recipe)
    {
        $this->ownRecipe($request, $recipe);
        if (! $recipe->active) {
            return response()->noContent();
        }
        $recipe->update(['active' => false]);

        return response()->noContent();
    }

    public function cloneRecipe(Request $request, Recipe $recipe)
    {
        $this->ownRecipe($request, $recipe);
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer'],
            'product_flavor_id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $target = ProductVariant::with('product')->findOrFail($data['product_variant_id']);
        $this->ownVariant($request, $target);
        $items = $recipe->items()->get()->map->only(['ingredient_id', 'quantity', 'component'])->all();
        $active = (bool) ($data['active'] ?? true);
        $this->assertRecipeReferences($request, $target, $data['product_flavor_id'] ?? null, $items, $active);
        $this->assertRecipeSlotIsAvailable($target, $data['product_flavor_id'] ?? null);

        $clone = DB::transaction(function () use ($data, $items) {
            $clone = Recipe::create([
                'product_variant_id' => $data['product_variant_id'],
                'product_flavor_id' => $data['product_flavor_id'] ?? null,
                'name' => $data['name'],
                'active' => $data['active'] ?? true,
            ]);
            $clone->items()->createMany($items);

            return $clone;
        });

        return response()->json($clone->load(['flavor', 'items.ingredient']), 201);
    }

    public function resolve(Request $request, ProductVariant $variant, RecipeResolver $resolver)
    {
        $this->ownVariant($request, $variant);
        $data = $request->validate([
            'flavor_ids' => ['sometimes', 'array'],
            'flavor_ids.*' => ['integer', 'distinct'],
            'modifier_ids' => ['sometimes', 'array'],
            'modifier_ids.*' => ['integer', 'distinct'],
        ]);

        return ['ingredients' => $resolver->resolve($variant, $data['flavor_ids'] ?? [], $data['modifier_ids'] ?? [])];
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'product_variant_id' => [$partial ? 'prohibited' : 'required', 'integer'],
            'product_flavor_id' => ['sometimes', 'nullable', 'integer'],
            'name' => [$required, 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
            'items' => [$required, 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999'],
            'items.*.component' => ['required', Rule::in(['base', 'topping', 'sauce', 'packaging', 'other'])],
        ];
    }

    private function assertRecipeReferences(
        Request $request,
        ProductVariant $variant,
        ?int $flavorId,
        array $items,
        bool $mustBeUsable,
    ): void {
        if ($mustBeUsable && (! $variant->active || ! $variant->product->active)) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'La variante o su producto están inactivos.',
            ]);
        }

        if ($flavorId !== null) {
            $flavor = ProductFlavor::query()
                ->whereKey($flavorId)
                ->where('product_id', $variant->product_id)
                ->first();
            if (! $flavor || ($mustBeUsable && ! $flavor->active)) {
                throw ValidationException::withMessages([
                    'product_flavor_id' => 'El sabor no pertenece al producto o está inactivo.',
                ]);
            }
        }

        $pairs = [];
        foreach ($items as $index => $item) {
            $pair = ((int) $item['ingredient_id']).':'.$item['component'];
            if (isset($pairs[$pair])) {
                throw ValidationException::withMessages([
                    "items.{$index}.ingredient_id" => 'No repitas el mismo insumo y componente en una receta.',
                ]);
            }
            $pairs[$pair] = true;

            $ingredient = Ingredient::query()
                ->whereKey($item['ingredient_id'])
                ->where('branch_id', $request->user()->branch_id)
                ->first();
            if (! $ingredient || ($mustBeUsable && ! $ingredient->active)) {
                throw ValidationException::withMessages([
                    "items.{$index}.ingredient_id" => 'El insumo no pertenece a la sucursal o está inactivo.',
                ]);
            }
        }
    }

    private function assertRecipeSlotIsAvailable(ProductVariant $variant, ?int $flavorId, ?Recipe $except = null): void
    {
        $query = Recipe::query()
            ->where('product_variant_id', $variant->id)
            ->when(
                $flavorId === null,
                fn ($builder) => $builder->whereNull('product_flavor_id'),
                fn ($builder) => $builder->where('product_flavor_id', $flavorId),
            )
            ->when($except, fn ($builder) => $builder->whereKeyNot($except->id));

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'product_flavor_id' => 'Ya existe una receta para esa variante y sabor; edita o reactiva la existente.',
            ]);
        }
    }

    private function ownRecipe(Request $request, Recipe $recipe): void
    {
        $recipe->loadMissing('variant.product');
        abort_unless($recipe->variant?->product?->branch_id === $request->user()->branch_id, 404);
    }

    private function ownVariant(Request $request, ProductVariant $variant): void
    {
        $variant->loadMissing('product');
        abort_unless($variant->product?->branch_id === $request->user()->branch_id, 404);
    }
}
