<?php

use App\Services\PizzaVariantRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.type', 'pizza')
            ->select('product_variants.id', 'product_variants.name')
            ->orderBy('product_variants.id')
            ->get()
            ->filter(fn (object $variant) => ! PizzaVariantRules::allowsStuffedCrust($variant->name))
            ->each(function (object $variant): void {
                DB::table('product_variants')->where('id', $variant->id)->update(['allows_stuffed_crust' => false]);
                DB::table('product_modifier_rules')
                    ->where('product_variant_id', $variant->id)
                    ->whereIn('modifier_id', DB::table('modifiers')->where('type', 'stuffed_crust')->select('id'))
                    ->update(['allowed' => false]);
            });
    }

    public function down(): void
    {
        // La configuración anterior no puede reconstruirse sin habilitar opciones que quizá ya estaban desactivadas.
    }
};
