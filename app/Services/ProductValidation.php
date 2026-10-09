<?php

namespace App\Services;

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

trait ProductValidation
{
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
            'image_data_uri' => [
                'sometimes',
                'nullable',
                'string',
                'max:7000000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/]+={0,2})$#D', $value, $matches)) {
                        $fail('La imagen debe ser un archivo PNG o JPEG válido.');

                        return;
                    }
                    $binary = base64_decode($matches[2], true);
                    if ($binary === false || strlen($binary) > 5 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
                        $fail('La imagen no es válida o supera el máximo de 5 MB.');
                    }
                },
            ],
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
            'required_flavors' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8'],
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
        $variantName = (string) ($data['name'] ?? $current?->name ?? '');
        $maxFlavors = (int) ($data['max_flavors'] ?? $current?->max_flavors ?? 1);
        $requiredFlavors = array_key_exists('required_flavors', $data) ? $data['required_flavors'] : $current?->required_flavors;

        if ($requiredFlavors !== null && (int) $requiredFlavors > $maxFlavors) {
            throw ValidationException::withMessages([
                "{$key}.required_flavors" => 'Los sabores obligatorios no pueden superar el máximo permitido.',
            ]);
        }

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
        if ($stuffed && ! PizzaVariantRules::allowsStuffedCrust($variantName)) {
            throw ValidationException::withMessages([
                "{$key}.allows_stuffed_crust" => 'Las pizzas Cuadrada y Napolitana solo admiten orilla normal.',
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
