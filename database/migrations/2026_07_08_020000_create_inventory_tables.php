<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('symbol', 20)->unique();
            $table->enum('dimension', ['mass', 'volume', 'count']); $table->decimal('base_factor', 16, 6)->default(1);
            $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('ingredient_types', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->unsignedInteger('suggested_shelf_life_days')->nullable();
            $table->unsignedInteger('expiry_alert_days')->default(3); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units'); $table->string('name'); $table->string('sku')->nullable()->unique();
            $table->decimal('minimum_stock', 16, 4)->default(0); $table->decimal('critical_stock', 16, 4)->default(0);
            $table->unsignedInteger('shelf_life_days')->nullable(); $table->unsignedInteger('expiry_alert_days')->default(3);
            $table->boolean('active')->default(true); $table->timestamps(); $table->unique(['branch_id', 'name']);
        });
        Schema::create('ingredient_presentations', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->decimal('quantity', 16, 4); $table->foreignId('equivalent_unit_id')->constrained('units');
            $table->decimal('base_quantity', 16, 4); $table->string('supplier_sku')->nullable(); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->string('name');
            $table->string('phone', 30)->nullable(); $table->text('address')->nullable(); $table->text('notes')->nullable();
            $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('user_id')->constrained();
            $table->date('purchased_at'); $table->enum('payment_source', ['cash', 'owner', 'bank', 'credit', 'other']);
            $table->decimal('total', 14, 2)->default(0); $table->string('receipt_path')->nullable(); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('purchase_id')->constrained()->cascadeOnDelete(); $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('ingredient_presentation_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('presentations_quantity', 12, 4); $table->decimal('base_quantity', 16, 4); $table->decimal('total_cost', 14, 2);
            $table->decimal('base_unit_cost', 16, 6); $table->date('expires_at')->nullable(); $table->string('lot_code')->nullable(); $table->timestamps();
        });
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete(); $table->string('lot_code')->nullable();
            $table->date('received_at'); $table->date('expires_at')->nullable()->index(); $table->decimal('initial_quantity', 16, 4);
            $table->decimal('available_quantity', 16, 4); $table->decimal('unit_cost', 16, 6)->default(0); $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['purchase', 'sale', 'production_input', 'production_output', 'adjustment', 'waste', 'return', 'reservation']);
            $table->decimal('quantity', 16, 4); $table->decimal('quantity_before', 16, 4); $table->decimal('quantity_after', 16, 4);
            $table->nullableMorphs('reference'); $table->string('reason')->nullable(); $table->text('comment')->nullable(); $table->timestamps();
        });
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('user_id')->constrained();
            $table->decimal('quantity', 16, 4); $table->enum('reason', ['waste', 'expiry', 'preparation_error', 'gift', 'internal_use', 'manual', 'loss', 'initial', 'correction']);
            $table->text('comment')->nullable(); $table->timestamps();
        });
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['low_stock', 'critical_stock', 'expiring', 'expired', 'insufficient_dough']);
            $table->enum('severity', ['info', 'warning', 'critical']); $table->string('message'); $table->timestamp('resolved_at')->nullable();
            $table->timestamps(); $table->index(['type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        foreach (['alerts','inventory_adjustments','inventory_movements','inventory_batches','purchase_items','purchases','suppliers','ingredient_presentations','ingredients','ingredient_types','units'] as $table) Schema::dropIfExists($table);
    }
};
