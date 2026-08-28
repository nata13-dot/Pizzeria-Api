<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $includeInactive = $this->includeInactive($request);

        return Product::query()
            ->where('branch_id', $request->user()->branch_id)
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->with($this->relations($includeInactive))
            ->orderBy('name')
            ->get();
    }

    public function show(Request $request, Product $product)
    {
        $this->ownProduct($request, $product);
        $includeInactive = $this->includeInactive($request);
        abort_if(! $includeInactive && ! $product->active, 404);

        return $product->load($this->relations($includeInactive));
    }

    public function categories(Request $request)
    {
        $includeInactive = $this->includeInactive($request);

        return ProductCategory::query()
            ->where('branch_id', $request->user()->branch_id)
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->withCount(['products' => fn ($query) => $query->where('active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function showCategory(Request $request, ProductCategory $category)
    {
        $this->ownCategory($request, $category);
        $includeInactive = $this->includeInactive($request);
        abort_if(! $includeInactive && ! $category->active, 404);

        return $category->loadCount(['products' => fn ($query) => $query->where('active', true)]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate($this->categoryRules($request));
        $data['branch_id'] = $request->user()->branch_id;

        return response()->json(ProductCategory::create($data), 201);
    }

    public function updateCategory(Request $request, ProductCategory $category)
    {
        $this->ownCategory($request, $category);
        $data = $request->validate($this->categoryRules($request, $category, true));

        if ($category->active && array_key_exists('active', $data) && ! $data['active']) {
            $this->assertCategoryCanBeDeactivated($category);
        }

        $category->update($data);

        return $category->fresh()->loadCount(['products' => fn ($query) => $query->where('active', true)]);
    }

    public function destroyCategory(Request $request, ProductCategory $category)
    {
        $this->ownCategory($request, $category);
        if (! $category->active) {
            return response()->noContent();
        }
        $this->assertCategoryCanBeDeactivated($category);
        $category->update(['active' => false]);

        return response()->noContent();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            ...$this->productRules($request, false),
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'variants.*.max_flavors' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'variants.*.allows_half_and_half' => ['sometimes', 'boolean'],
            'variants.*.allows_stuffed_crust' => ['sometimes', 'boolean'],
            'variants.*.active' => ['sometimes', 'boolean'],
            'flavors' => ['sometimes', 'array'],
            'flavors.*.name' => ['required', 'string', 'max:100'],
            'flavors.*.ingredient_id' => ['nullable', 'integer'],
            'flavors.*.active' => ['sometimes', 'boolean'],
        ]);

        $data['variants'] = collect($data['variants'])->map(function (array $variant) {
            if (array_key_exists('sku', $variant)) {
                $variant['sku'] = trim((string) $variant['sku']) ?: null;
            }

            return $variant;
        })->all();
        $this->assertCategoryIsUsable($request, $data['product_category_id'] ?? null);
        $this->assertUniquePayloadValues($data['variants'], 'name', 'variants');
        $this->assertUniquePayloadValues($data['variants'], 'sku', 'variants', false);
        $skus = collect($data['variants'])->pluck('sku')->filter()->values();
        if ($skus->isNotEmpty() && ProductVariant::query()->whereIn('sku', $skus)->exists()) {
            throw ValidationException::withMessages(['variants' => 'Uno de los SKU ya está registrado.']);
        }
        $this->assertUniquePayloadValues($data['flavors'] ?? [], 'name', 'flavors');
        $this->assertIngredientsAreUsable($request, collect($data['flavors'] ?? [])->pluck('ingredient_id')->filter()->all(), 'flavors');

        $prospective = new Product([
            'type' => $data['type'],
            'active' => $data['active'] ?? true,
        ]);
        foreach ($data['variants'] as $index => $variant) {
            $this->assertVariantSemantics($prospective, $variant, "variants.{$index}");
        }
        if (($data['active'] ?? true) && collect($data['variants'])->every(fn ($variant) => ($variant['active'] ?? true) === false)) {
            throw ValidationException::withMessages(['variants' => 'Un producto activo necesita al menos una variante activa.']);
        }

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::create([
                'branch_id' => $request->user()->branch_id,
                'product_category_id' => $data['product_category_id'] ?? null,
                'name' => $data['name'],
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'] ?? true,
            ]);
            $product->variants()->createMany($data['variants']);
            $product->flavors()->createMany($data['flavors'] ?? []);

            return $product;
        });

        return response()->json($product->load($this->relations(true)), 201);
    }

    public function update(Request $request, Product $product)
    {
        $this->ownProduct($request, $product);
        $data = $request->validate($this->productRules($request, true, $product));
        $categoryId = array_key_exists('product_category_id', $data)
            ? $data['product_category_id']
            : $product->product_category_id;
        $this->assertCategoryIsUsable($request, $categoryId);

        $effectiveType = $data['type'] ?? $product->type;
        if ($effectiveType !== 'pizza' && $product->variants()
            ->where('active', true)
            ->where(fn ($query) => $query->where('allows_half_and_half', true)->orWhere('allows_stuffed_crust', true))
            ->exists()) {
            throw ValidationException::withMessages([
                'type' => 'Desactiva mitad y mitad y orilla rellena en las variantes antes de cambiar el tipo de producto.',
            ]);
        }

        if (array_key_exists('active', $data) && $data['active']) {
            if (! $product->variants()->where('active', true)->exists()) {
                throw ValidationException::withMessages(['active' => 'Agrega o reactiva una variante antes de activar el producto.']);
            }
        } elseif ($product->active && array_key_exists('active', $data) && ! $data['active']) {
            $this->assertProductIsNotInActiveCombo($product);
        }

        $product->update($data);

        return $product->fresh()->load($this->relations(true));
    }

    public function destroy(Request $request, Product $product)
    {
        $this->ownProduct($request, $product);
        if (! $product->active) {
            return response()->noContent();
        }
        $this->assertProductIsNotInActiveCombo($product);
        $product->update(['active' => false]);

        return response()->noContent();
    }

    public function storeVariant(Request $request, Product $product)
    {
        $this->ownProduct($request, $product);
        $data = $request->validate($this->variantRules($product));
        $this->normalizeSku($data);
        $this->assertVariantSemantics($product, $data, 'variant');
        $variant = $product->variants()->create($data);

        return response()->json($variant, 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        $this->ownVariant($request, $variant);
        $data = $request->validate($this->variantRules($variant->product, true, $variant));
        $this->normalizeSku($data);
        $this->assertVariantSemantics($variant->product, $data, 'variant', $variant);

        if ($variant->active && array_key_exists('active', $data) && ! $data['active']) {
            $this->assertVariantCanBeDeactivated($variant);
        }

        $variant->update($data);

        return $variant->fresh();
    }

    public function destroyVariant(Request $request, ProductVariant $variant)
    {
        $this->ownVariant($request, $variant);
        if (! $variant->active) {
            return response()->noContent();
        }
        $this->assertVariantCanBeDeactivated($variant);
        $variant->update(['active' => false]);

        return response()->noContent();
    }

    public function storeFlavor(Request $request, Product $product)
    {
        $this->ownProduct($request, $product);
        $data = $request->validate($this->flavorRules($product));
        $this->assertIngredientsAreUsable($request, array_filter([$data['ingredient_id'] ?? null]), 'ingredient_id');
        $flavor = $product->flavors()->create($data);

        return response()->json($flavor->load('ingredient'), 201);
    }

    public function updateFlavor(Request $request, ProductFlavor $flavor)
    {
        $this->ownFlavor($request, $flavor);
        $data = $request->validate($this->flavorRules($flavor->product, true, $flavor));
        $ingredientId = array_key_exists('ingredient_id', $data) ? $data['ingredient_id'] : $flavor->ingredient_id;
        $this->assertIngredientsAreUsable($request, array_filter([$ingredientId]), 'ingredient_id');

        if ($flavor->active && array_key_exists('active', $data) && ! $data['active']) {
            $this->assertFlavorCanBeDeactivated($flavor);
        }

        $flavor->update($data);

        return $flavor->fresh()->load('ingredient');
    }

    public function destroyFlavor(Request $request, ProductFlavor $flavor)
    {
        $this->ownFlavor($request, $flavor);
        if (! $flavor->active) {
            return response()->noContent();
        }
        $this->assertFlavorCanBeDeactivated($flavor);
        $flavor->update(['active' => false]);

        return response()->noContent();
    }

    private function productRules(Request $request, bool $partial, ?Product $product = null): array
    {
        $prefix = $partial ? 'sometimes' : 'required';

        return [
            'product_category_id' => ['sometimes', 'nullable', 'integer'],
            'name' => [
                $prefix,
                'string',
                'max:150',
                Rule::unique('products', 'name')
                    ->where('branch_id', $request->user()->branch_id)
                    ->ignore($product?->id),
            ],
            'type' => [$prefix, Rule::in(['pizza', 'wings', 'fries', 'nuggets', 'cone', 'beverage', 'extra', 'other'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function categoryRules(Request $request, ?ProductCategory $category = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $uniqueName = Rule::unique('product_categories', 'name')
            ->where('branch_id', $request->user()->branch_id)
            ->ignore($category?->id);

        return [
            'name' => [$required, 'string', 'max:100', $uniqueName],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function variantRules(Product $product, bool $partial = false, ?ProductVariant $variant = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:100',
                Rule::unique('product_variants', 'name')->where('product_id', $product->id)->ignore($variant?->id),
            ],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant?->id)],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99'],
            'max_flavors' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'allows_half_and_half' => ['sometimes', 'boolean'],
            'allows_stuffed_crust' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function flavorRules(Product $product, bool $partial = false, ?ProductFlavor $flavor = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:100',
                Rule::unique('product_flavors', 'name')->where('product_id', $product->id)->ignore($flavor?->id),
            ],
            'ingredient_id' => ['sometimes', 'nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function assertCategoryIsUsable(Request $request, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }
        if (! ProductCategory::query()
            ->whereKey($categoryId)
            ->where('branch_id', $request->user()->branch_id)
            ->where('active', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'product_category_id' => 'La categoría no pertenece a la sucursal o está inactiva.',
            ]);
        }
    }

    private function assertIngredientsAreUsable(Request $request, array $ingredientIds, string $key): void
    {
        $ids = collect($ingredientIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $valid = Ingredient::query()
            ->whereIn('id', $ids)
            ->where('branch_id', $request->user()->branch_id)
            ->where('active', true)
            ->count();
        if ($valid !== $ids->count()) {
            throw ValidationException::withMessages([$key => 'Existe un insumo inactivo o de otra sucursal.']);
        }
    }

    private function assertVariantSemantics(Product $product, array $data, string $key, ?ProductVariant $current = null): void
    {
        $half = (bool) ($data['allows_half_and_half'] ?? $current?->allows_half_and_half ?? false);
        $stuffed = (bool) ($data['allows_stuffed_crust'] ?? $current?->allows_stuffed_crust ?? false);
        $maxFlavors = (int) ($data['max_flavors'] ?? $current?->max_flavors ?? 1);

        if ($half && ($product->type !== 'pizza' || $maxFlavors < 2)) {
            throw ValidationException::withMessages([
                "{$key}.allows_half_and_half" => 'Mitad y mitad requiere una pizza con al menos dos sabores permitidos.',
            ]);
        }
        if ($stuffed && $product->type !== 'pizza') {
            throw ValidationException::withMessages([
                "{$key}.allows_stuffed_crust" => 'La orilla rellena solo se puede habilitar en pizzas.',
            ]);
        }
    }

    private function assertUniquePayloadValues(array $rows, string $field, string $key, bool $caseInsensitive = true): void
    {
        $values = collect($rows)
            ->pluck($field)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => $caseInsensitive ? mb_strtolower(trim($value)) : trim($value));
        if ($values->unique()->count() !== $values->count()) {
            throw ValidationException::withMessages([$key => "No se puede repetir {$field} en la misma solicitud."]);
        }
    }

    private function normalizeSku(array &$data): void
    {
        if (array_key_exists('sku', $data)) {
            $data['sku'] = trim((string) $data['sku']) ?: null;
        }
    }

    private function assertCategoryCanBeDeactivated(ProductCategory $category): void
    {
        if ($category->products()->where('active', true)->exists()) {
            throw ValidationException::withMessages([
                'active' => 'Desactiva o cambia de categoría los productos activos antes de desactivar la categoría.',
            ]);
        }
    }

    private function assertProductIsNotInActiveCombo(Product $product): void
    {
        $used = DB::table('combo_items')
            ->join('combos', 'combos.id', '=', 'combo_items.combo_id')
            ->join('product_variants', 'product_variants.id', '=', 'combo_items.product_variant_id')
            ->where('product_variants.product_id', $product->id)
            ->where('combos.active', true)
            ->where('combo_items.active', true)
            ->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'active' => 'El producto forma parte de un combo activo. Actualiza o desactiva ese combo primero.',
            ]);
        }
    }

    private function assertVariantCanBeDeactivated(ProductVariant $variant): void
    {
        if ($variant->product->active
            && ! $variant->product->variants()->where('active', true)->whereKeyNot($variant->id)->exists()) {
            throw ValidationException::withMessages([
                'active' => 'No puedes desactivar la única variante activa de un producto activo.',
            ]);
        }

        $used = DB::table('combo_items')
            ->join('combos', 'combos.id', '=', 'combo_items.combo_id')
            ->where('combo_items.product_variant_id', $variant->id)
            ->where('combo_items.active', true)
            ->where('combos.active', true)
            ->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'active' => 'La variante forma parte de un combo activo. Actualiza o desactiva ese combo primero.',
            ]);
        }
    }

    private function assertFlavorCanBeDeactivated(ProductFlavor $flavor): void
    {
        if ($flavor->recipes()->where('active', true)->exists()) {
            throw ValidationException::withMessages([
                'active' => 'Desactiva primero las recetas activas que usan este sabor.',
            ]);
        }

        $used = DB::table('combo_allowed_options')
            ->join('combo_items', 'combo_items.id', '=', 'combo_allowed_options.combo_item_id')
            ->join('combos', 'combos.id', '=', 'combo_items.combo_id')
            ->where('combo_allowed_options.product_flavor_id', $flavor->id)
            ->where('combo_items.active', true)
            ->where('combos.active', true)
            ->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'active' => 'El sabor está permitido en un combo activo. Actualiza o desactiva ese combo primero.',
            ]);
        }
    }

    private function includeInactive(Request $request): bool
    {
        $request->validate(['include_inactive' => ['sometimes', 'boolean']]);
        $include = $request->boolean('include_inactive');
        if ($include) {
            abort_unless($request->user()->hasPermission('admin.only'), 403);
        }

        return $include;
    }

    private function relations(bool $includeInactive): array
    {
        if ($includeInactive) {
            return [
                'category',
                'flavors.ingredient',
                'variants.recipes.items.ingredient',
                'variants.modifierRules.modifier.items.ingredient',
            ];
        }

        return [
            'category',
            'flavors' => fn (Relation $query) => $query->where('active', true),
            'flavors.ingredient',
            'variants' => fn (Relation $query) => $query->where('active', true),
            'variants.recipes' => fn (Relation $query) => $query->where('active', true),
            'variants.recipes.items.ingredient',
            'variants.modifierRules' => fn (Relation $query) => $query
                ->where('allowed', true)
                ->whereHas('modifier', fn ($modifier) => $modifier->where('active', true)),
            'variants.modifierRules.modifier.items.ingredient',
        ];
    }

    private function ownCategory(Request $request, ProductCategory $category): void
    {
        abort_unless($category->branch_id === $request->user()->branch_id, 404);
    }

    private function ownProduct(Request $request, Product $product): void
    {
        abort_unless($product->branch_id === $request->user()->branch_id, 404);
    }

    private function ownVariant(Request $request, ProductVariant $variant): void
    {
        $variant->loadMissing('product');
        abort_unless($variant->product?->branch_id === $request->user()->branch_id, 404);
    }

    private function ownFlavor(Request $request, ProductFlavor $flavor): void
    {
        $flavor->loadMissing('product');
        abort_unless($flavor->product?->branch_id === $request->user()->branch_id, 404);
    }
}
