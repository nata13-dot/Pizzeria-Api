<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModifierController extends Controller
{
    public function index(Request $request)
    {
        $includeInactive = $this->includeInactive($request);

        return Modifier::query()
            ->where('branch_id', $request->user()->branch_id)
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->with('items.ingredient')
            ->orderBy('name')
            ->get();
    }

    public function show(Request $request, Modifier $modifier)
    {
        $this->ownModifier($request, $modifier);
        $includeInactive = $this->includeInactive($request);
        abort_if(! $includeInactive && ! $modifier->active, 404);

        return $modifier->load('items.ingredient');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $this->assertItems($request, $data['type'], $data['items'] ?? [], true);

        $modifier = DB::transaction(function () use ($request, $data) {
            $modifier = Modifier::create([
                'branch_id' => $request->user()->branch_id,
                'name' => $data['name'],
                'type' => $data['type'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
            ]);
            $modifier->items()->createMany($data['items'] ?? []);

            return $modifier;
        });

        return response()->json($modifier->load('items.ingredient'), 201);
    }

    public function update(Request $request, Modifier $modifier)
    {
        $this->ownModifier($request, $modifier);
        $data = $request->validate($this->rules($request, true, $modifier));
        $modifier->loadMissing('items');
        $type = $data['type'] ?? $modifier->type;
        $items = $data['items'] ?? $modifier->items->map->only(['ingredient_id', 'quantity'])->all();
        $active = (bool) ($data['active'] ?? $modifier->active);
        $this->assertItems($request, $type, $items, $active);

        if ($modifier->active && array_key_exists('active', $data) && ! $data['active']) {
            $this->assertNotUsedByActiveCombo($modifier);
        }

        DB::transaction(function () use ($modifier, $data): void {
            $modifier->update(collect($data)->except('items')->all());
            if (array_key_exists('items', $data)) {
                $modifier->items()->delete();
                $modifier->items()->createMany($data['items']);
            }
        });

        return $modifier->fresh()->load('items.ingredient');
    }

    public function destroy(Request $request, Modifier $modifier)
    {
        $this->ownModifier($request, $modifier);
        if (! $modifier->active) {
            return response()->noContent();
        }
        $this->assertNotUsedByActiveCombo($modifier);
        $modifier->update(['active' => false]);

        return response()->noContent();
    }

    public function attach(Request $request, ProductVariant $variant)
    {
        $this->ownVariant($request, $variant);
        $data = $request->validate([
            'modifier_id' => ['required', 'integer'],
            'allowed' => ['sometimes', 'boolean'],
            'price_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        $modifier = Modifier::query()
            ->whereKey($data['modifier_id'])
            ->where('branch_id', $request->user()->branch_id)
            ->first();
        if (! $modifier) {
            throw ValidationException::withMessages(['modifier_id' => 'El modificador no pertenece a la sucursal.']);
        }

        $allowed = (bool) ($data['allowed'] ?? true);
        if ($allowed && (! $modifier->active || ! $variant->active || ! $variant->product->active)) {
            throw ValidationException::withMessages([
                'allowed' => 'Solo puedes habilitar modificadores, variantes y productos activos.',
            ]);
        }

        $existingRule = $variant->modifierRules()->where('modifier_id', $modifier->id)->first();
        $priceOverride = array_key_exists('price_override', $data)
            ? $data['price_override']
            : $existingRule?->price_override;
        $rule = $variant->modifierRules()->updateOrCreate(
            ['modifier_id' => $modifier->id],
            ['allowed' => $allowed, 'price_override' => $priceOverride],
        );

        return response()->json($rule->load('modifier.items.ingredient'), 201);
    }

    public function detach(Request $request, ProductVariant $variant, Modifier $modifier)
    {
        $this->ownVariant($request, $variant);
        $this->ownModifier($request, $modifier);
        $variant->modifierRules()->where('modifier_id', $modifier->id)->update(['allowed' => false]);

        return response()->noContent();
    }

    private function rules(Request $request, bool $partial = false, ?Modifier $modifier = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:150',
                Rule::unique('modifiers', 'name')
                    ->where('branch_id', $request->user()->branch_id)
                    ->ignore($modifier?->id),
            ],
            'type' => [$required, Rule::in(['add', 'remove', 'instruction', 'half_and_half', 'additional_flavor', 'stuffed_crust'])],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99'],
            'active' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999'],
        ];
    }

    private function assertItems(Request $request, string $type, array $items, bool $mustBeActive): void
    {
        if ($type === 'instruction' && $items !== []) {
            throw ValidationException::withMessages([
                'items' => 'Un modificador de instrucción no puede alterar inventario.',
            ]);
        }

        $ids = collect($items)->pluck('ingredient_id')->map(fn ($id) => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return;
        }
        $valid = Ingredient::query()
            ->whereIn('id', $ids)
            ->where('branch_id', $request->user()->branch_id)
            ->when($mustBeActive, fn ($query) => $query->where('active', true))
            ->count();
        if ($valid !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => $mustBeActive
                    ? 'Existe un insumo inactivo o de otra sucursal.'
                    : 'Existe un insumo de otra sucursal.',
            ]);
        }
    }

    private function assertNotUsedByActiveCombo(Modifier $modifier): void
    {
        $used = DB::table('combo_allowed_options')
            ->join('combo_items', 'combo_items.id', '=', 'combo_allowed_options.combo_item_id')
            ->join('combos', 'combos.id', '=', 'combo_items.combo_id')
            ->where('combo_allowed_options.modifier_id', $modifier->id)
            ->where('combo_items.active', true)
            ->where('combos.active', true)
            ->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'active' => 'El modificador está permitido en un combo activo. Actualiza o desactiva ese combo primero.',
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

    private function ownModifier(Request $request, Modifier $modifier): void
    {
        abort_unless($modifier->branch_id === $request->user()->branch_id, 404);
    }

    private function ownVariant(Request $request, ProductVariant $variant): void
    {
        $variant->loadMissing('product');
        abort_unless($variant->product?->branch_id === $request->user()->branch_id, 404);
    }
}
