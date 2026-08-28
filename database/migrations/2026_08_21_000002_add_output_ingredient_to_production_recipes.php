<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_recipes', function (Blueprint $table): void {
            $table->foreignId('output_ingredient_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('ingredients')
                ->restrictOnDelete();
        });

        // Existing recipes can only be migrated automatically when every
        // historical production batch points to the same output ingredient.
        DB::table('production_recipes')->orderBy('id')->eachById(function (object $recipe): void {
            $outputIds = DB::table('production_batch_outputs')
                ->join('production_batches', 'production_batches.id', '=', 'production_batch_outputs.production_batch_id')
                ->where('production_batches.production_recipe_id', $recipe->id)
                ->distinct()
                ->pluck('production_batch_outputs.ingredient_id');

            if ($outputIds->count() === 1) {
                DB::table('production_recipes')
                    ->where('id', $recipe->id)
                    ->update(['output_ingredient_id' => $outputIds->first()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_recipes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('output_ingredient_id');
        });
    }
};
