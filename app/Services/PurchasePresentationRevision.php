<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\Unit;

class PurchasePresentationRevision
{
    public function make(IngredientPresentation $presentation, Ingredient $ingredient, Unit $equivalentUnit, Unit $baseUnit): string
    {
        return hash('sha256', json_encode([
            'presentation' => [
                (int) $presentation->id,
                (int) $presentation->ingredient_id,
                (string) $presentation->name,
                (string) $presentation->quantity,
                (int) $presentation->equivalent_unit_id,
                (string) $presentation->base_quantity,
                (bool) $presentation->active,
            ],
            'ingredient' => [(int) $ingredient->id, (int) $ingredient->base_unit_id, (bool) $ingredient->active],
            'equivalent_unit' => [(int) $equivalentUnit->id, $equivalentUnit->dimension, (string) $equivalentUnit->base_factor, (bool) $equivalentUnit->active],
            'base_unit' => [(int) $baseUnit->id, $baseUnit->dimension, (string) $baseUnit->base_factor, (bool) $baseUnit->active],
        ], JSON_THROW_ON_ERROR));
    }
}
