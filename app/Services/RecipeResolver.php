<?php

namespace App\Services;

use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\Recipe;
use Illuminate\Validation\ValidationException;

class RecipeResolver
{
    public function __construct(private readonly BranchSettings $settings) {}

    public function resolve(ProductVariant $variant, array $flavorIds = [], array $modifierIds = []): array
    {
        $variant->loadMissing('product');
        if ($variant->active === false || $variant->product?->active === false) {
            throw ValidationException::withMessages(['product' => 'El producto seleccionado no está activo.']);
        }

        $flavorIds = array_values(array_unique($flavorIds));
        $modifierIds = array_values(array_unique($modifierIds));
        $maxFlavors = (int) $variant->max_flavors;
        if ($variant->product->type === 'wings') {
            $maxFlavors = min($maxFlavors, max(1, $this->settings->integer($variant->product->branch_id, 'max_wing_flavors')));
        }
        if (count($flavorIds) > $maxFlavors) {
            throw ValidationException::withMessages(['flavors' => 'Se excedió el máximo de sabores.']);
        }
        if ($flavorIds && ProductFlavor::query()
            ->whereIn('id', $flavorIds)
            ->where('product_id', $variant->product_id)
            ->where('active', true)
            ->count() !== count($flavorIds)) {
            throw ValidationException::withMessages(['flavors' => 'Existe un sabor incompatible o inactivo.']);
        }
        if (count($flavorIds) > 1 && $variant->product->type === 'pizza' && ! $variant->allows_half_and_half) {
            throw ValidationException::withMessages(['flavors' => 'Esta variante no permite mitad y mitad.']);
        }

        $recipes = Recipe::query()
            ->with('items.ingredient')
            ->where('product_variant_id', $variant->id)
            ->where('active', true)
            ->when(
                $flavorIds,
                fn ($query) => $query->whereIn('product_flavor_id', $flavorIds),
                fn ($query) => $query->whereNull('product_flavor_id'),
            )
            ->get();

        if ($recipes->isEmpty()) {
            $message = $flavorIds
                ? 'No existe una receta activa para la selección de sabores.'
                : 'Debes elegir un sabor o configurar una receta base sin sabor.';
            throw ValidationException::withMessages(['recipe' => $message]);
        }
        if ($flavorIds && $recipes->pluck('product_flavor_id')->unique()->count() !== count($flavorIds)) {
            throw ValidationException::withMessages([
                'recipe' => 'Falta una receta activa para uno de los sabores seleccionados.',
            ]);
        }

        foreach ($recipes->flatMap->items as $item) {
            if (! $item->ingredient?->active || $item->ingredient?->branch_id !== $variant->product->branch_id) {
                throw ValidationException::withMessages([
                    'recipe' => 'La receta contiene un insumo inactivo o de otra sucursal.',
                ]);
            }
        }

        $totals = [];
        $add = function ($item, float $factor = 1) use (&$totals): void {
            $totals[$item->ingredient_id] = ($totals[$item->ingredient_id] ?? 0)
                + (float) $item->quantity * $factor;
        };

        if ($recipes->count() === 1) {
            $recipes->first()->items->each(fn ($item) => $add($item));
        } elseif ($variant->product->type === 'pizza') {
            foreach ($recipes->first()->items->whereIn('component', ['base', 'sauce', 'packaging']) as $item) {
                $add($item);
            }
            foreach ($recipes as $recipe) {
                foreach ($recipe->items->whereIn('component', ['topping', 'other']) as $item) {
                    $add($item, 1 / $recipes->count());
                }
            }
        } else {
            foreach ($recipes->first()->items->whereNotIn('component', ['sauce']) as $item) {
                $add($item);
            }
            foreach ($recipes as $recipe) {
                foreach ($recipe->items->where('component', 'sauce') as $item) {
                    $add($item, 1 / $recipes->count());
                }
            }
        }

        $rules = $variant->modifierRules()
            ->with('modifier.items.ingredient')
            ->whereIn('modifier_id', $modifierIds)
            ->get();
        if ($rules->count() !== count($modifierIds) || $rules->contains(
            fn ($rule) => ! $rule->allowed
                || ! $rule->modifier?->active
                || $rule->modifier?->branch_id !== $variant->product->branch_id,
        )) {
            throw ValidationException::withMessages(['modifiers' => 'Existe un modificador incompatible o inactivo.']);
        }
        foreach ($rules as $rule) {
            $factor = $rule->modifier->type === 'remove' ? -1.0 : 1.0;
            if ($rule->modifier->type === 'instruction') {
                continue;
            }
            foreach ($rule->modifier->items as $item) {
                if (! $item->ingredient?->active || $item->ingredient?->branch_id !== $variant->product->branch_id) {
                    throw ValidationException::withMessages([
                        'modifiers' => 'El modificador contiene un insumo inactivo o de otra sucursal.',
                    ]);
                }
                $add($item, $factor);
            }
        }

        return collect($totals)
            ->map(fn ($quantity, $id) => [
                'ingredient_id' => (int) $id,
                'quantity' => round(max(0, $quantity), 4),
            ])
            ->filter(fn ($row) => $row['quantity'] > 0)
            ->values()
            ->all();
    }
}
