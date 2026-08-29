<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_item_components')) {
            $requiredColumns = [
                'id', 'order_item_id', 'combo_item_id', 'product_variant_id',
                'name', 'quantity', 'flavors', 'modifiers', 'notes',
                'created_at', 'updated_at',
            ];
            $missingColumns = array_diff($requiredColumns, Schema::getColumnListing('order_item_components'));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'La tabla order_item_components ya existe pero está incompleta. Faltan: '.implode(', ', $missingColumns),
                );
            }

            return;
        }

        Schema::create('order_item_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combo_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 2);
            $table->json('flavors')->nullable();
            $table->json('modifiers')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_components');
    }
};
