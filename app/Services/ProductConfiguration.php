<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductConfigurationOperation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductConfiguration
{
    use ProductValidation;

    public function read(Request $request, Product $product): array
    {
        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product) {
            $this->ownProduct($request, $product);
            $product->load($this->relations(true));

            return ['product' => $product, 'revision' => $this->revision($product)];
        });
    }

    private function revision(Product $product): string
    {
        // Includes dependencies and modifier prices; timestamps alone miss writes in the same second.
        return hash('sha256', $product->fresh()->load($this->relations(true))->toJson());
    }

    public function operation(Request $request, Product $product, string $key): ?ProductConfigurationOperation
    {
        $this->ownProduct($request, $product);

        return ProductConfigurationOperation::query()->where([
            'branch_id' => $request->user()->branch_id,
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'operation_key' => $key,
        ])->first();
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409));
    }

    public function save(Request $request, Product $product): array
    {
        $envelope = $request->validate([
            'expected_revision' => ['required', 'string', 'size:64'],
            'operation_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'product' => ['required', 'array'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*' => ['required', 'array'],
            'variants.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'flavors' => ['present', 'array', 'max:100'],
            'flavors.*' => ['required', 'array'],
            'flavors.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'removed_variant_ids' => ['present', 'array'],
            'removed_variant_ids.*' => ['integer', 'min:1', 'distinct'],
            'removed_flavor_ids' => ['present', 'array'],
            'removed_flavor_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        foreach (['product', 'variants', 'flavors'] as $section) {
            $envelope[$section] = $request->input($section);
        }
        $hash = hash('sha256', json_encode($this->canonical($envelope), JSON_THROW_ON_ERROR));

        return CatalogWrite::run($request->user()->branch_id, [$product], function () use ($request, $product, $envelope, $hash) {
            $this->ownProduct($request, $product);
            $previous = $this->operation($request, $product, $envelope['operation_key']);
            if ($previous) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    $this->conflict('operation_conflict', 'Esta operación corresponde a otros cambios. Comprueba el guardado anterior.');
                }

                return ['saved' => true, 'revision' => $previous->applied_revision, 'replayed' => true];
            }
            if (! hash_equals($this->revision($product), $envelope['expected_revision'])) {
                $this->conflict('configuration_conflict', 'El producto cambió mientras lo editabas. Conservamos tus campos; revisa la configuración actual.');
            }
            $fields = Validator::make($envelope['product'], $this->productRules($request, true, $product))->validate();
            $this->assertCategoryIsUsable($request, array_key_exists('product_category_id', $fields) ? $fields['product_category_id'] : $product->product_category_id);
            $prospective = clone $product;
            $prospective->fill($fields);
            if ($product->active && ! $prospective->active) {
                $this->assertProductIsNotInActiveCombo($product);
            }
            $variants = $product->variants()->get()->keyBy('id');
            $flavors = $product->flavors()->get()->keyBy('id');
            foreach (['variants' => [$variants, 'removed_variant_ids'], 'flavors' => [$flavors, 'removed_flavor_ids']] as $section => [$existing, $removedKey]) {
                $editedIds = collect($envelope[$section])->pluck('id')->filter()->all();
                foreach (array_merge($editedIds, $envelope[$removedKey]) as $id) {
                    if (! $existing->has($id)) {
                        throw ValidationException::withMessages([$section => 'Una referencia no pertenece a este producto.']);
                    }
                }
                if (array_intersect($editedIds, $envelope[$removedKey])) {
                    throw ValidationException::withMessages([$section => 'No puedes editar y eliminar la misma opción.']);
                }
            }
            $variantChanges = [];
            foreach ($envelope['variants'] as $row) {
                $variant = $variants[$row['id']];
                $data = Validator::make($row, $this->variantRules($product, true, $variant))->validate();
                $this->normalizeSku($data);
                $this->assertVariantSemantics($prospective, $data, 'variants', $variant);
                $variantChanges[$variant->id] = $data;
            }
            $final = $variants->map(function ($variant) use ($variantChanges, $envelope) {
                $copy = clone $variant;
                $copy->fill($variantChanges[$variant->id] ?? []);
                if (in_array($variant->id, $envelope['removed_variant_ids'])) {
                    $copy->active = false;
                }

                return $copy;
            });
            if ($prospective->active && ! $final->contains(fn ($variant) => $variant->active)) {
                throw ValidationException::withMessages(['variants' => 'Un producto activo necesita una variante activa.']);
            }
            foreach ($final as $variant) {
                if ($variant->active) {
                    $this->assertVariantSemantics($prospective, $variant->toArray(), 'variants', $variant);
                }
            }
            $this->assertUniquePayloadValues($final->toArray(), 'name', 'variants');
            $flavorChanges = [];
            foreach ($envelope['flavors'] as $row) {
                $flavor = isset($row['id']) ? $flavors[$row['id']] : null;
                $data = Validator::make($row, $this->flavorRules($product, $flavor !== null, $flavor))->validate();
                $this->assertIngredientsAreUsable($request, array_filter([$data['ingredient_id'] ?? $flavor?->ingredient_id]), 'flavors');
                if ($flavor?->active && isset($data['active']) && ! $data['active']) {
                    $this->assertFlavorCanBeDeactivated($flavor);
                }
                $flavorChanges[] = [$flavor, $data];
            }
            $this->assertUniquePayloadValues($envelope['flavors'], 'name', 'flavors');
            foreach ($envelope['removed_flavor_ids'] as $id) {
                $this->assertFlavorCanBeDeactivated($flavors[$id]);
            }
            $enableCrustIds = $final->filter(fn ($variant) => $variant->allows_stuffed_crust && ! $variants[$variant->id]->allows_stuffed_crust)->keys()->all();
            // Activate alternatives before deactivating variants; evaluate dependencies under the same lock.
            $product->update($fields);
            foreach ($variantChanges as $id => $data) {
                if (array_key_exists('active', $data) && ! (bool) $data['active']) {
                    continue;
                }
                $variants[$id]->update($data);
            }
            foreach ($final as $variant) {
                if (! $variant->active && isset($variantChanges[$variant->id]) || in_array($variant->id, $envelope['removed_variant_ids'])) {
                    $current = $variants[$variant->id];
                    $current->setRelation('product', $product);
                    if ($current->active) {
                        $this->assertVariantCanBeDeactivated($current);
                    }
                    $current->update(array_merge($variantChanges[$variant->id] ?? [], ['active' => false]));
                }
            }
            foreach ($flavorChanges as [$flavor, $data]) {
                if ($flavor) {
                    $flavor->update($data);
                } else {
                    $product->flavors()->create($data);
                }
            }
            foreach ($envelope['removed_flavor_ids'] as $id) {
                $flavors[$id]->update(['active' => false]);
            }
            $this->crust($product, $enableCrustIds);
            $revision = $this->revision($product);
            ProductConfigurationOperation::create([
                'branch_id' => $request->user()->branch_id, 'user_id' => $request->user()->id,
                'product_id' => $product->id, 'operation_key' => $envelope['operation_key'],
                'request_hash' => $hash, 'applied_revision' => $revision,
            ]);

            return ['saved' => true, 'revision' => $revision, 'replayed' => false];
        });
    }

    private function crust(Product $product, array $enableCrustIds): void
    {
        foreach ($product->variants()->with('modifierRules.modifier')->get() as $variant) {
            $rules = $variant->modifierRules->filter(fn ($rule) => $rule->modifier?->type === 'stuffed_crust');
            if (! $variant->active || ! $variant->allows_stuffed_crust) {
                foreach ($rules as $rule) {
                    $rule->update(['allowed' => false]);
                }

                continue;
            }
            if ($rules->isNotEmpty()) {
                foreach ($rules as $rule) {
                    if ($rule->modifier->active && in_array($variant->id, $enableCrustIds)) {
                        $rule->update(['allowed' => true]);
                    }
                }

                continue;
            }
            foreach (['habanero', 'chipotle'] as $flavor) {
                $modifier = Modifier::where('branch_id', $product->branch_id)->where('type', 'stuffed_crust')
                    ->where('active', true)->where('name', 'like', '%'.$flavor.'%')->orderBy('id')->first();
                if (! $modifier && Modifier::where('branch_id', $product->branch_id)->where('name', 'Orilla rellena de queso con '.$flavor)->exists()) {
                    throw ValidationException::withMessages(['variants' => 'La orilla configurada está inactiva. Revisa los modificadores antes de habilitarla.']);
                }
                $modifier ??= Modifier::create(['branch_id' => $product->branch_id, 'name' => 'Orilla rellena de queso con '.$flavor, 'type' => 'stuffed_crust', 'price' => '20.00', 'active' => true]);
                $variant->modifierRules()->create(['modifier_id' => $modifier->id, 'allowed' => true, 'price_override' => null]);
            }
        }
    }
}
