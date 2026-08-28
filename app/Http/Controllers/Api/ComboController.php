<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Combo;
use App\Models\ComboItem;
use App\Models\Modifier;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComboController extends Controller
{
    public function index(Request $request)
    {
        $includeInactive = $this->includeInactive($request);
        $relation = $includeInactive ? 'allItems' : 'items';
        $combos = Combo::query()
            ->where('branch_id', $request->user()->branch_id)
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->with([
                "{$relation}.variant.product",
                "{$relation}.options.flavor",
                "{$relation}.options.modifier",
            ])
            ->orderBy('name')
            ->get();

        if ($includeInactive) {
            $combos->each(fn (Combo $combo) => $this->exposeAllItems($combo));
        }

        return $combos;
    }

    public function show(Request $request, Combo $combo)
    {
        $this->ownCombo($request, $combo);
        $includeInactive = $this->includeInactive($request);
        abort_if(! $includeInactive && ! $combo->active, 404);

        $relation = $includeInactive ? 'allItems' : 'items';
        $combo->load([
            "{$relation}.variant.product",
            "{$relation}.options.flavor",
            "{$relation}.options.modifier",
        ]);
        if ($includeInactive) {
            $this->exposeAllItems($combo);
        }

        return $combo;
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $active = (bool) ($data['active'] ?? true);
        $items = $this->prepareAndValidateItems($request, $data['items'], $active);
        if ($active && collect($items)->doesntContain(fn ($item) => $item['active'])) {
            throw ValidationException::withMessages(['items' => 'Un combo activo necesita al menos un componente activo.']);
        }

        $combo = DB::transaction(function () use ($request, $data, $items) {
            $combo = Combo::create([
                'branch_id' => $request->user()->branch_id,
                'name' => $data['name'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
            ]);
            foreach ($items as $row) {
                $item = $combo->allItems()->create(collect($row)->except('id', 'options')->all());
                $item->options()->createMany($row['options']);
            }

            return $combo;
        });

        return response()->json($this->loadAllItems($combo), 201);
    }

    public function update(Request $request, Combo $combo)
    {
        $this->ownCombo($request, $combo);
        $data = $request->validate($this->rules($request, true, $combo));
        $active = (bool) ($data['active'] ?? $combo->active);
        $items = null;

        if (array_key_exists('items', $data)) {
            $items = $this->prepareAndValidateItems($request, $data['items'], $active, $combo);
            if ($active && collect($items)->doesntContain(fn ($item) => $item['active'])) {
                throw ValidationException::withMessages(['items' => 'Un combo activo necesita al menos un componente activo.']);
            }
        } elseif (array_key_exists('active', $data) && $data['active']) {
            $items = $this->currentItemsPayload($combo);
            $items = $this->prepareAndValidateItems($request, $items, true, $combo);
            if (collect($items)->doesntContain(fn ($item) => $item['active'])) {
                throw ValidationException::withMessages(['active' => 'Agrega o reactiva un componente antes de activar el combo.']);
            }
            $items = null;
        }

        DB::transaction(function () use ($combo, $data, $items): void {
            $combo->update(collect($data)->except('items')->all());
            if ($items === null) {
                return;
            }

            $keptIds = [];
            foreach ($items as $row) {
                if (isset($row['id'])) {
                    $item = $combo->allItems()->whereKey($row['id'])->firstOrFail();
                    $item->update(collect($row)->except('id', 'options')->all());
                    $keptIds[] = $item->id;
                } else {
                    $item = $combo->allItems()->create(collect($row)->except('id', 'options')->all());
                    $keptIds[] = $item->id;
                }
                $item->options()->delete();
                $item->options()->createMany($row['options']);
            }

            $combo->allItems()->whereNotIn('id', $keptIds)->update(['active' => false]);
        });

        return $this->loadAllItems($combo->fresh());
    }

    public function destroy(Request $request, Combo $combo)
    {
        $this->ownCombo($request, $combo);
        if (! $combo->active) {
            return response()->noContent();
        }
        $combo->update(['active' => false]);

        return response()->noContent();
    }

    private function rules(Request $request, bool $partial = false, ?Combo $combo = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:150',
                Rule::unique('combos', 'name')
                    ->where('branch_id', $request->user()->branch_id)
                    ->ignore($combo?->id),
            ],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99'],
            'active' => ['sometimes', 'boolean'],
            'items' => [$required, 'array', 'min:1'],
            'items.*.id' => ['sometimes', 'integer', 'distinct'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'items.*.flavor_required' => ['sometimes', 'boolean'],
            'items.*.active' => ['sometimes', 'boolean'],
            'items.*.options' => ['sometimes', 'array'],
            'items.*.options.*.product_flavor_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.options.*.modifier_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    private function prepareAndValidateItems(
        Request $request,
        array $rows,
        bool $comboActive,
        ?Combo $combo = null,
    ): array {
        $existing = $combo?->allItems()->get()->keyBy('id') ?? collect();

        return collect($rows)->map(function (array $row, int $index) use ($request, $comboActive, $existing) {
            $current = isset($row['id']) ? $existing->get($row['id']) : null;
            if (isset($row['id']) && ! $current) {
                throw ValidationException::withMessages([
                    "items.{$index}.id" => 'El componente no pertenece a este combo.',
                ]);
            }

            $row['active'] = array_key_exists('active', $row) ? (bool) $row['active'] : ($current?->active ?? true);
            $row['flavor_required'] = (bool) ($row['flavor_required'] ?? false);
            $row['options'] = $row['options'] ?? [];
            $variant = ProductVariant::with('product')->find($row['product_variant_id']);
            if (! $variant || $variant->product?->branch_id !== $request->user()->branch_id) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => 'La variante no pertenece a la sucursal.',
                ]);
            }
            $mustBeUsable = $comboActive && $row['active'];
            if ($mustBeUsable && (! $variant->active || ! $variant->product->active)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => 'La variante o su producto están inactivos.',
                ]);
            }

            $seen = [];
            foreach ($row['options'] as $optionIndex => $option) {
                $flavorId = $option['product_flavor_id'] ?? null;
                $modifierId = $option['modifier_id'] ?? null;
                if (($flavorId === null) === ($modifierId === null)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.options.{$optionIndex}" => 'Cada opción debe contener exactamente un sabor o un modificador.',
                    ]);
                }

                $optionKey = $flavorId !== null ? "flavor:{$flavorId}" : "modifier:{$modifierId}";
                if (isset($seen[$optionKey])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.options.{$optionIndex}" => 'No repitas una opción dentro del mismo componente.',
                    ]);
                }
                $seen[$optionKey] = true;

                if ($flavorId !== null) {
                    $flavor = ProductFlavor::query()
                        ->whereKey($flavorId)
                        ->where('product_id', $variant->product_id)
                        ->first();
                    if (! $flavor || ($mustBeUsable && ! $flavor->active)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.options.{$optionIndex}.product_flavor_id" => 'El sabor no corresponde al producto o está inactivo.',
                        ]);
                    }
                }

                if ($modifierId !== null) {
                    $modifier = Modifier::query()
                        ->whereKey($modifierId)
                        ->where('branch_id', $request->user()->branch_id)
                        ->first();
                    $allowed = $variant->modifierRules()
                        ->where('modifier_id', $modifierId)
                        ->where('allowed', true)
                        ->exists();
                    if (! $modifier || ! $allowed || ($mustBeUsable && ! $modifier->active)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.options.{$optionIndex}.modifier_id" => 'El modificador no está habilitado para la variante o está inactivo.',
                        ]);
                    }
                }
            }

            return $row;
        })->values()->all();
    }

    private function currentItemsPayload(Combo $combo): array
    {
        return $combo->allItems()
            ->with('options')
            ->get()
            ->map(fn (ComboItem $item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'flavor_required' => $item->flavor_required,
                'active' => $item->active,
                'options' => $item->options->map->only(['product_flavor_id', 'modifier_id'])->all(),
            ])->all();
    }

    private function loadAllItems(Combo $combo): Combo
    {
        $combo->load(['allItems.variant.product', 'allItems.options.flavor', 'allItems.options.modifier']);
        $this->exposeAllItems($combo);

        return $combo;
    }

    private function exposeAllItems(Combo $combo): void
    {
        $combo->setRelation('items', $combo->getRelation('allItems'));
        $combo->unsetRelation('allItems');
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

    private function ownCombo(Request $request, Combo $combo): void
    {
        abort_unless($combo->branch_id === $request->user()->branch_id, 404);
    }
}
