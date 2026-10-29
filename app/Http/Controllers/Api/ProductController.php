<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Services\CatalogCache;
use App\Services\CatalogWrite;
use App\Services\ProductValidation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ProductValidation;

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

    public function posCatalog(Request $request)
    {
        return app(CatalogCache::class)->remember((int) $request->user()->branch_id, 'pos', fn () => Product::query()
            ->select(['id', 'branch_id', 'product_category_id', 'name', 'type', 'description', 'image_data_uri', 'active'])
            ->where('branch_id', $request->user()->branch_id)
            ->where('active', true)
            ->with([
                'category:id,name',
                'flavors' => fn (Relation $query) => $query
                    ->select(['id', 'product_id', 'name', 'active'])
                    ->where('active', true),
                'variants' => fn (Relation $query) => $query
                    ->select(['id', 'product_id', 'name', 'price', 'max_flavors', 'required_flavors', 'allows_half_and_half', 'allows_stuffed_crust', 'active'])
                    ->where('active', true),
                'variants.recipes' => fn (Relation $query) => $query
                    ->select(['id', 'product_variant_id', 'product_flavor_id', 'applies_to_flavors', 'active'])
                    ->where('active', true),
                'variants.modifierRules' => fn (Relation $query) => $query
                    ->select(['id', 'product_variant_id', 'modifier_id', 'allowed', 'price_override'])
                    ->where('allowed', true)
                    ->whereHas('modifier', fn ($modifier) => $modifier->where('active', true)),
                'variants.modifierRules.modifier:id,name,type,price,active',
            ])
            ->orderBy('name')
            ->get()
            ->each(function (Product $product): void {
                foreach ($product->variants as $variant) {
                    $variant->setAttribute('available_flavor_ids', $variant->recipes->pluck('product_flavor_id')->filter()->unique()->values()->all());
                    $variant->setAttribute('has_base_recipe', $variant->recipes->contains(fn ($recipe) => $recipe->product_flavor_id === null && ! $recipe->applies_to_flavors));
                    $variant->unsetRelation('recipes');
                }
            })
            ->toArray());
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

        return app(CatalogCache::class)->remember((int) $request->user()->branch_id, 'categories:'.(int) $includeInactive, fn () => ProductCategory::query()
            ->where('branch_id', $request->user()->branch_id)
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->withCount(['products' => fn ($query) => $query->where('active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()->toArray());
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
        return CatalogWrite::run($request->user()->branch_id, [], function () use ($request) {
            $data = $request->validate($this->categoryRules($request));
            $data['branch_id'] = $request->user()->branch_id;

            return response()->json(ProductCategory::create($data), 201);
        });
    }

    public function updateCategory(Request $request, ProductCategory $category)
    {
        return CatalogWrite::run($request->user()->branch_id, [$category], function () use ($request, $category) {
            $this->ownCategory($request, $category);
            $data = $request->validate($this->categoryRules($request, $category, true));

            if ($category->active && array_key_exists('active', $data) && ! $data['active']) {
                $this->assertCategoryCanBeDeactivated($category);
            }

            $category->update($data);

            return $category->fresh()->loadCount(['products' => fn ($query) => $query->where('active', true)]);
        });
    }

    public function destroyCategory(Request $request, ProductCategory $category)
    {
        return CatalogWrite::run($request->user()->branch_id, [$category], function () use ($request, $category) {
            $this->ownCategory($request, $category);
            if (! $category->active) {
                return response()->noContent();
            }
            $this->assertCategoryCanBeDeactivated($category);
            $category->update(['active' => false]);

            return response()->noContent();
        });
    }

    public function store(Request $request)
    {
        return CatalogWrite::run($request->user()->branch_id, [], function () use ($request) {
            $data = $request->validate([
                ...$this->productRules($request, false),
                'variants' => ['required', 'array', 'min:1'],
                'variants.*.name' => ['required', 'string', 'max:100'],
                'variants.*.sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')],
                'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
                'variants.*.max_flavors' => ['sometimes', 'integer', 'min:1', 'max:8'],
                'variants.*.required_flavors' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8'],
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
            if (($data['active'] ?? true) && collect($data['variants'])->every(fn ($variant) => ! (bool) ($variant['active'] ?? true))) {
                throw ValidationException::withMessages(['variants' => 'Un producto activo necesita al menos una variante activa.']);
            }

            $product = DB::transaction(function () use ($request, $data) {
                $product = Product::create([
                    'branch_id' => $request->user()->branch_id,
                    'product_category_id' => $data['product_category_id'] ?? null,
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                    'image_data_uri' => $data['image_data_uri'] ?? null,
                    'active' => $data['active'] ?? true,
                ]);
                $product->variants()->createMany($data['variants']);
                $product->flavors()->createMany($data['flavors'] ?? []);

                return $product;
            });

            return response()->json($product->load($this->relations(true)), 201);
        });
    }

    public function update(Request $request, Product $product)
    {
        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product) {
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
        });
    }

    public function destroy(Request $request, Product $product)
    {
        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product) {
            $this->ownProduct($request, $product);
            if (! $product->active) {
                return response()->noContent();
            }
            $this->assertProductIsNotInActiveCombo($product);
            $product->update(['active' => false]);

            return response()->noContent();
        });
    }

    public function storeVariant(Request $request, Product $product)
    {
        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product) {
            $this->ownProduct($request, $product);
            $data = $request->validate($this->variantRules($product));
            $this->normalizeSku($data);
            $this->assertVariantSemantics($product, $data, 'variant');
            $variant = $product->variants()->create($data);

            return response()->json($variant, 201);
        });
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        return CatalogWrite::run($request->user()->branch_id, [$variant], function () use ($request, $variant) {
            $this->ownVariant($request, $variant);
            $data = $request->validate($this->variantRules($variant->product, true, $variant));
            $this->normalizeSku($data);
            $this->assertVariantSemantics($variant->product, $data, 'variant', $variant);

            if ($variant->active && array_key_exists('active', $data) && ! $data['active']) {
                $this->assertVariantCanBeDeactivated($variant);
            }

            $variant->update($data);

            return $variant->fresh();
        });
    }

    public function destroyVariant(Request $request, ProductVariant $variant)
    {
        return CatalogWrite::run($request->user()->branch_id, [$variant], function () use ($request, $variant) {
            $this->ownVariant($request, $variant);
            if (! $variant->active) {
                return response()->noContent();
            }
            $this->assertVariantCanBeDeactivated($variant);
            $variant->update(['active' => false]);

            return response()->noContent();
        });
    }

    public function storeFlavor(Request $request, Product $product)
    {
        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product) {
            $this->ownProduct($request, $product);
            $data = $request->validate($this->flavorRules($product));
            $this->assertIngredientsAreUsable($request, array_filter([$data['ingredient_id'] ?? null]), 'ingredient_id');
            $flavor = $product->flavors()->create($data);

            return response()->json($flavor->load('ingredient'), 201);
        });
    }

    public function updateFlavor(Request $request, ProductFlavor $flavor)
    {
        return CatalogWrite::run($request->user()->branch_id, [$flavor], function () use ($request, $flavor) {
            $this->ownFlavor($request, $flavor);
            $data = $request->validate($this->flavorRules($flavor->product, true, $flavor));
            $ingredientId = array_key_exists('ingredient_id', $data) ? $data['ingredient_id'] : $flavor->ingredient_id;
            $this->assertIngredientsAreUsable($request, array_filter([$ingredientId]), 'ingredient_id');

            if ($flavor->active && array_key_exists('active', $data) && ! $data['active']) {
                $this->assertFlavorCanBeDeactivated($flavor);
            }

            $flavor->update($data);

            return $flavor->fresh()->load('ingredient');
        });
    }

    public function destroyFlavor(Request $request, ProductFlavor $flavor)
    {
        return CatalogWrite::run($request->user()->branch_id, [$flavor], function () use ($request, $flavor) {
            $this->ownFlavor($request, $flavor);
            if (! $flavor->active) {
                return response()->noContent();
            }
            $this->assertFlavorCanBeDeactivated($flavor);
            $flavor->update(['active' => false]);

            return response()->noContent();
        });
    }
}
