<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_recipes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->decimal('yield_quantity', 16, 4);
            $t->foreignId('yield_unit_id')->constrained('units');
            $t->unsignedInteger('shelf_life_days')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('production_recipe_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_recipe_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->decimal('quantity', 16, 4);
            $t->timestamps();
            $t->unique(['production_recipe_id', 'ingredient_id'], 'prod_recipe_item_unique');
        });
        Schema::create('production_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('production_recipe_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->decimal('multiplier', 12, 4);
            $t->dateTime('produced_at');
            $t->date('expires_at');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('production_batch_outputs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->foreignId('inventory_batch_id')->constrained();
            $t->decimal('quantity', 16, 4);
            $t->string('portion_name')->nullable();
            $t->decimal('grams_per_portion', 12, 4)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_outputs');
        Schema::dropIfExists('production_batches');
        Schema::dropIfExists('production_recipe_items');
        Schema::dropIfExists('production_recipes');
    }
};
