<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Services\CatalogWrite;
use App\Services\RecipeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1']);
        $search = trim($data['search'] ?? '');

        return Recipe::query()->whereHas('variant.product', fn ($q) => $q->where('branch_id', $request->user()->branch_id))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('variant', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('variant.product', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('flavor', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            }))->with(['variant:id,product_id,name,active', 'variant.product:id,name,type,branch_id,active', 'flavor:id,product_id,name,active'])
            ->withCount('items')->orderBy('name')->orderBy('id')->paginate(25);
    }

    public function destinations(Request $request)
    {
        $data = $request->validate(['search' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1', 'product_id' => 'sometimes|integer']);
        $search = trim($data['search'] ?? '');

        return ProductVariant::query()->select(['id', 'product_id', 'name', 'active'])
            ->whereHas('product', fn ($q) => $q->where('branch_id', $request->user()->branch_id)->where('active', true))
            ->where('active', true)->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            }))->with($this->destinationRelations())->orderBy('name')->orderBy('id')->paginate(25);
    }

    public function destination(Request $request, ProductVariant $variant)
    {
        $this->ownVariant($request, $variant);

        return response()->json($variant->load($this->destinationRelations()))->header('Cache-Control', 'no-store');
    }

    private function destinationRelations(): array
    {
        return ['product:id,branch_id,name,type,active', 'product.flavors:id,product_id,name,active', 'recipes:id,product_variant_id,product_flavor_id,applies_to_flavors,base_only,name,active'];
    }

    public function show(Request $request, Recipe $recipe)
    {
        $this->ownRecipe($request, $recipe);

        return $recipe->load(['variant.product', 'flavor', 'items.ingredient.baseUnit']);
    }

    public function store(Request $request)
    {
        return CatalogWrite::run($request->user()->branch_id, [], function () use ($request) {
            $data = $request->validate($this->rules());
            $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);
            $this->ownVariant($request, $variant);
            $recipe = $this->persist($request, $variant, null, $data);
            $this->assertBaseOnlyRecipesHaveBase([$variant->id]);

            return response()->json($recipe->load(['flavor', 'items.ingredient.baseUnit']), 201);
        });
    }

    public function update(Request $request, Recipe $recipe)
    {
        return CatalogWrite::run($request->user()->branch_id, [$recipe], function () use ($request, $recipe) {
            $this->ownRecipe($request, $recipe);
            $data = $request->validate($this->rules(true));
            $recipe->loadMissing(['variant.product', 'items']);
            $flavorId = array_key_exists('product_flavor_id', $data)
                ? $data['product_flavor_id']
                : $recipe->product_flavor_id;
            $items = $data['items'] ?? $recipe->items->map->only(['ingredient_id', 'quantity', 'component'])->all();
            $active = (bool) ($data['active'] ?? $recipe->active);
            $this->assertBaseFlags($recipe->variant, $flavorId, (bool) ($data['applies_to_flavors'] ?? $recipe->applies_to_flavors), (bool) ($data['base_only'] ?? $recipe->base_only), $items);

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
                    $recipe->touch();
                }
            });
            $this->assertBaseOnlyRecipesHaveBase([$recipe->product_variant_id]);

            return $recipe->fresh()->load(['variant.product', 'flavor', 'items.ingredient.baseUnit']);
        });
    }

    public function destroy(Request $request, Recipe $recipe)
    {
        return CatalogWrite::run($request->user()->branch_id, [$recipe], function () use ($request, $recipe) {
            $this->ownRecipe($request, $recipe);
            if (! $recipe->active) {
                return response()->noContent();
            }
            $recipe->update(['active' => false]);
            $this->assertBaseOnlyRecipesHaveBase([$recipe->product_variant_id]);

            return response()->noContent();
        });
    }

    public function cloneRecipe(Request $request, Recipe $recipe)
    {
        return CatalogWrite::run($request->user()->branch_id, [$recipe], function () use ($request, $recipe) {
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
            if (! $items) {
                throw ValidationException::withMessages(['items' => 'Una receta sin ingredientes propios no se puede duplicar.']);
            }
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

            return response()->json($clone->load(['flavor', 'items.ingredient.baseUnit']), 201);
        });
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

    /** Creates or updates several sizes of one recipe at once; nothing is saved if any entry fails. */
    public function batch(Request $request)
    {
        return CatalogWrite::run($request->user()->branch_id, [], function () use ($request) {
            $data = $request->validate([
                'recipes' => ['required', 'array', 'min:1', 'max:20'],
                'recipes.*.id' => ['sometimes', 'nullable', 'integer'],
                'recipes.*.product_variant_id' => ['required', 'integer'],
                'recipes.*.product_flavor_id' => ['present', 'nullable', 'integer'],
                'recipes.*.name' => ['required', 'string', 'max:150'],
                'recipes.*.active' => ['sometimes', 'boolean'],
                'recipes.*.applies_to_flavors' => ['sometimes', 'boolean'],
                'recipes.*.base_only' => ['sometimes', 'boolean'],
                'recipes.*.items' => ['present', 'array'],
                'recipes.*.items.*.ingredient_id' => ['required', 'integer'],
                'recipes.*.items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999'],
                'recipes.*.items.*.component' => ['required', Rule::in(['base', 'topping', 'sauce', 'packaging', 'other'])],
            ]);

            $slots = [];
            $saved = [];
            foreach ($data['recipes'] as $index => $entry) {
                $slot = $entry['product_variant_id'].':'.($entry['product_flavor_id'] ?? 'none');
                if (isset($slots[$slot])) {
                    throw ValidationException::withMessages(["recipes.{$index}.product_variant_id" => 'Hay dos recetas para el mismo tamaño y sabor.']);
                }
                $slots[$slot] = true;
                $saved[] = $this->prefixErrors("recipes.{$index}.", function () use ($request, $entry) {
                    $variant = ProductVariant::with('product')->findOrFail($entry['product_variant_id']);
                    $this->ownVariant($request, $variant);
                    $recipe = null;
                    if (! empty($entry['id'])) {
                        $recipe = Recipe::query()->lockForUpdate()->findOrFail($entry['id']);
                        $this->ownRecipe($request, $recipe);
                        if ($recipe->product_variant_id !== $variant->id || $recipe->product_flavor_id !== ($entry['product_flavor_id'] ?? null)) {
                            throw ValidationException::withMessages(['product_variant_id' => 'La receta existente pertenece a otro tamaño o sabor.']);
                        }
                    }

                    return $this->persist($request, $variant, $recipe, $entry);
                });
            }
            $this->assertBaseOnlyRecipesHaveBase(array_column($data['recipes'], 'product_variant_id'));

            return ['data' => collect($saved)->map(fn (Recipe $recipe) => $recipe->fresh()->load(['flavor', 'items.ingredient.baseUnit']))->all()];
        });
    }

    /**
     * Proposes (or applies) moving the ingredients every flavor shares into one
     * base per size. Single-flavor consumption is unchanged: base + remainder
     * equals the original quantity.
     */
    public function baseSplit(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'apply' => ['sometimes', 'boolean'],
            'revision' => ['required_if:apply,true', 'nullable', 'string'],
        ]);

        return CatalogWrite::run($request->user()->branch_id, [], function () use ($request, $data) {
            $product = Product::query()->whereKey($data['product_id'])->where('branch_id', $request->user()->branch_id)->firstOrFail();
            $variants = ProductVariant::query()->where('product_id', $product->id)->where('active', true)->orderBy('id')
                ->with(['recipes' => fn ($q) => $q->orderBy('id'), 'recipes.items' => fn ($q) => $q->orderBy('id'), 'recipes.flavor:id,name', 'recipes.items.ingredient.baseUnit'])->get();
            $revision = sha1(json_encode($variants->map(fn ($variant) => $variant->recipes->map(fn ($recipe) => [$recipe->id, (string) $recipe->updated_at, $recipe->active, $recipe->items->map->only(['ingredient_id', 'quantity', 'component'])]))));
            $plan = $variants->map(fn (ProductVariant $variant) => $this->splitPlan($product, $variant))->values();

            if (! ($data['apply'] ?? false)) {
                return ['revision' => $revision, 'variants' => $plan->all()];
            }
            if (! hash_equals($revision, (string) $data['revision'])) {
                return response()->json(['code' => 'configuration_conflict', 'message' => 'Las recetas cambiaron. Revisa la propuesta otra vez.'], 409);
            }

            foreach ($plan->where('applicable', true) as $variantPlan) {
                $base = Recipe::create([
                    'product_variant_id' => $variantPlan['variant_id'], 'product_flavor_id' => null, 'applies_to_flavors' => true,
                    'base_only' => false, 'name' => $variantPlan['base_name'], 'active' => true,
                ]);
                $base->items()->createMany($variantPlan['base_items']);
                foreach ($variantPlan['flavors'] as $flavor) {
                    $recipe = Recipe::query()->lockForUpdate()->findOrFail($flavor['recipe_id']);
                    $recipe->items()->delete();
                    $recipe->items()->createMany($flavor['items']);
                    $recipe->update(['base_only' => $flavor['items'] === []]);
                    $recipe->touch();
                }
            }
            $this->assertBaseOnlyRecipesHaveBase($plan->pluck('variant_id')->all());

            return ['applied' => $plan->where('applicable', true)->count(), 'variants' => $plan->all()];
        });
    }

    private function splitPlan(Product $product, ProductVariant $variant): array
    {
        $summary = ['variant_id' => $variant->id, 'variant_name' => $variant->name, 'applicable' => false, 'reason' => null, 'base_name' => "{$product->name} {$variant->name} · Base", 'base_items' => [], 'flavors' => []];
        if ($variant->recipes->contains(fn ($recipe) => $recipe->product_flavor_id === null)) {
            return [...$summary, 'reason' => 'Ya tiene una receta sin sabor. Revísala a mano antes de separar la base.'];
        }
        $flavors = $variant->recipes->filter(fn ($recipe) => $recipe->active && $recipe->product_flavor_id !== null && ! $recipe->base_only)->values();
        if ($flavors->count() < 2) {
            return [...$summary, 'reason' => 'Necesita al menos dos sabores con receta.'];
        }
        $keyed = $flavors->map(fn ($recipe) => $recipe->items->keyBy(fn ($item) => $item->ingredient_id.':'.$item->component));
        $common = collect($keyed->first()->keys())->filter(fn ($key) => $keyed->every(fn ($items) => $items->has($key)))->values();
        if ($common->isEmpty()) {
            return [...$summary, 'reason' => 'Ningún ingrediente se repite en todos los sabores.'];
        }
        $baseItems = $common->map(function ($key) use ($keyed) {
            $sample = $keyed->first()[$key];

            return ['ingredient_id' => $sample->ingredient_id, 'component' => $sample->component, 'quantity' => round($keyed->min(fn ($items) => (float) $items[$key]->quantity), 4), 'name' => $sample->ingredient?->name, 'unit' => $sample->ingredient?->baseUnit?->symbol];
        });
        $minimum = $baseItems->keyBy(fn ($item) => $item['ingredient_id'].':'.$item['component']);

        return [...$summary, 'applicable' => true,
            'base_items' => $baseItems->map(fn ($item) => collect($item)->only(['ingredient_id', 'component', 'quantity'])->all())->values()->all(),
            'base_preview' => $baseItems->values()->all(),
            'flavors' => $flavors->map(fn ($recipe) => [
                'recipe_id' => $recipe->id,
                'flavor_name' => $recipe->flavor?->name,
                'items' => $remaining = $recipe->items->map(function ($item) use ($minimum) {
                    $key = $item->ingredient_id.':'.$item->component;
                    $quantity = round((float) $item->quantity - (float) ($minimum[$key]['quantity'] ?? 0), 4);

                    return $quantity > .00001 ? ['ingredient_id' => $item->ingredient_id, 'component' => $item->component, 'quantity' => $quantity] : null;
                })->filter()->values()->all(),
                'preview' => collect($remaining)->map(fn ($item) => [...$item, 'name' => $recipe->items->firstWhere('ingredient_id', $item['ingredient_id'])?->ingredient?->name, 'unit' => $recipe->items->firstWhere('ingredient_id', $item['ingredient_id'])?->ingredient?->baseUnit?->symbol])->all(),
            ])->all(),
        ];
    }

    private function persist(Request $request, ProductVariant $variant, ?Recipe $recipe, array $entry): Recipe
    {
        $flavorId = $entry['product_flavor_id'] ?? null;
        $active = (bool) ($entry['active'] ?? true);
        $applies = (bool) ($entry['applies_to_flavors'] ?? false);
        $baseOnly = (bool) ($entry['base_only'] ?? false);
        $items = $entry['items'];
        $this->assertBaseFlags($variant, $flavorId, $applies, $baseOnly, $items);
        $this->assertRecipeReferences($request, $variant, $flavorId, $items, $active);
        $this->assertRecipeSlotIsAvailable($variant, $flavorId, $recipe);

        $attributes = ['product_variant_id' => $variant->id, 'product_flavor_id' => $flavorId, 'applies_to_flavors' => $applies, 'base_only' => $baseOnly, 'name' => $entry['name'], 'active' => $active];
        if ($recipe) {
            $recipe->update($attributes);
            $recipe->items()->delete();
        } else {
            $recipe = Recipe::create($attributes);
        }
        $recipe->items()->createMany(collect($items)->map(fn ($item) => collect($item)->only(['ingredient_id', 'quantity', 'component'])->all())->all());
        $recipe->touch();

        return $recipe;
    }

    private function assertBaseFlags(ProductVariant $variant, ?int $flavorId, bool $applies, bool $baseOnly, array $items): void
    {
        if ($applies && ($flavorId !== null || ! $variant->product->flavors()->exists())) {
            throw ValidationException::withMessages(['applies_to_flavors' => 'Solo la receta sin sabor de un producto con sabores puede ser la base de todos.']);
        }
        if ($baseOnly && $flavorId === null) {
            throw ValidationException::withMessages(['base_only' => 'Solo un sabor puede usar únicamente la base.']);
        }
        if ($baseOnly && $items !== []) {
            throw ValidationException::withMessages(['items' => 'Un sabor que usa solo la base no lleva ingredientes propios.']);
        }
        if (! $baseOnly && $items === []) {
            throw ValidationException::withMessages(['items' => 'Agrega al menos un ingrediente.']);
        }
    }

    /** A flavor marked "solo base" would fail at the register without an active base. */
    private function assertBaseOnlyRecipesHaveBase(array $variantIds): void
    {
        $orphan = Recipe::query()->whereIn('product_variant_id', array_unique($variantIds))->where('active', true)->where('base_only', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('recipes as bases')->whereColumn('bases.product_variant_id', 'recipes.product_variant_id')
                ->whereNull('bases.product_flavor_id')->where('bases.applies_to_flavors', true)->where('bases.active', true))
            ->with('flavor:id,name')->first();
        if ($orphan) {
            throw ValidationException::withMessages(['base_only' => "El sabor {$orphan->flavor?->name} usa solo la base; este tamaño necesita una base activa."]);
        }
    }

    private function prefixErrors(string $prefix, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [$prefix.$key => $messages])->all());
        }
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'product_variant_id' => [$partial ? 'prohibited' : 'required', 'integer'],
            'product_flavor_id' => ['sometimes', 'nullable', 'integer'],
            'name' => [$required, 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
            'applies_to_flavors' => ['sometimes', 'boolean'],
            'base_only' => ['sometimes', 'boolean'],
            'items' => [$partial ? 'sometimes' : 'present', 'array'],
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
