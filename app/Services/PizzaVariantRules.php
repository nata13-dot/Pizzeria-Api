<?php

namespace App\Services;

use Illuminate\Support\Str;

final class PizzaVariantRules
{
    public static function allowsStuffedCrust(string $variantName): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($variantName)), -1, PREG_SPLIT_NO_EMPTY);

        return ! in_array('cuadrada', $words, true) && ! in_array('napolitana', $words, true);
    }
}
