<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IngredientType;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private function model(string $catalog): string
    {
        return match ($catalog) {
            'units' => Unit::class,'ingredient-types' => IngredientType::class,'suppliers' => Supplier::class,default => abort(404)
        };
    }

    public function index(Request $request, string $catalog)
    {
        $model = $this->model($catalog);

        return $model::when($catalog === 'suppliers', fn ($query) => $query->where('branch_id', $request->user()->branch_id))->orderBy('name')->get();
    }

    public function store(Request $request, string $catalog)
    {
        $data = $this->validateData($request, $catalog);
        if ($catalog === 'suppliers') {
            $data['branch_id'] = $request->user()->branch_id;
        }

        return response()->json($this->model($catalog)::create($data), 201);
    }

    public function update(Request $request, string $catalog, int $id)
    {
        $model = $this->model($catalog)::findOrFail($id);
        $this->ownSupplier($request, $catalog, $model);
        $data = $this->validateData($request, $catalog, true, $model);

        if ($catalog === 'units') {
            $model = $this->updateUnit($model, $data);
        } else {
            $model->update($data);
        }

        return $model;
    }

    public function destroy(Request $request, string $catalog, int $id)
    {
        $model = $this->model($catalog)::findOrFail($id);
        $this->ownSupplier($request, $catalog, $model);
        $model->update(['active' => false]);

        return response()->noContent();
    }

    private function ownSupplier(Request $request, string $catalog, mixed $model): void
    {
        if ($catalog === 'suppliers') {
            abort_unless($model->branch_id === $request->user()->branch_id, 404);
        }
    }

    private function validateData(Request $r, string $catalog, bool $partial = false, mixed $model = null): array
    {
        $sometimes = $partial ? 'sometimes|' : '';

        return match ($catalog) {
            'units' => $r->validate(['name' => $sometimes.'required|string|max:100', 'symbol' => [$partial ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('units', 'symbol')->ignore($model?->id)], 'dimension' => $sometimes.'required|in:mass,volume,count', 'base_factor' => $sometimes.'required|numeric|gt:0', 'active' => 'sometimes|boolean']),
            'ingredient-types' => $r->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('ingredient_types', 'name')->ignore($model?->id)], 'suggested_shelf_life_days' => 'nullable|integer|min:0', 'expiry_alert_days' => $sometimes.'required|integer|min:0', 'active' => 'sometimes|boolean']),
            'suppliers' => $r->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150', Rule::unique('suppliers', 'name')->where('branch_id', $r->user()->branch_id)->ignore($model?->id)], 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string', 'notes' => 'nullable|string', 'active' => 'sometimes|boolean'])
        };
    }

    private function updateUnit(Unit $unit, array $data): Unit
    {
        return DB::transaction(function () use ($unit, $data) {
            /** @var Unit $lockedUnit */
            $lockedUnit = Unit::query()->lockForUpdate()->findOrFail($unit->id);
            $dimensionChanges = array_key_exists('dimension', $data) && $data['dimension'] !== $lockedUnit->dimension;
            $factorChanges = array_key_exists('base_factor', $data)
                && abs((float) $data['base_factor'] - (float) $lockedUnit->base_factor) > 0.0000001;

            if (($dimensionChanges || $factorChanges) && $this->unitIsUsed($lockedUnit->id)) {
                $errors = [];
                if ($dimensionChanges) {
                    $errors['dimension'] = 'No se puede cambiar la dimensión de una unidad que ya está en uso.';
                }
                if ($factorChanges) {
                    $errors['base_factor'] = 'No se puede cambiar el factor de una unidad que ya está en uso.';
                }

                throw ValidationException::withMessages($errors);
            }

            $lockedUnit->update($data);

            return $lockedUnit;
        });
    }

    private function unitIsUsed(int $unitId): bool
    {
        return DB::table('ingredients')->where('base_unit_id', $unitId)->exists()
            || DB::table('ingredient_presentations')->where('equivalent_unit_id', $unitId)->exists()
            || DB::table('production_recipes')->where('yield_unit_id', $unitId)->exists();
    }
}
