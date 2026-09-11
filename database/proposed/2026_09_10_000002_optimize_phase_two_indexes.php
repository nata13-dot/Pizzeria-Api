<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(
                ['branch_id', 'status', 'scheduled_at', 'created_at'],
                'orders_branch_status_schedule_created_index',
            );
            $table->index(
                ['status', 'pending_expires_at'],
                'orders_status_pending_expiry_index',
            );
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_order_index');
            $table->dropIndex('order_items_variant_index');
            $table->dropIndex('order_items_combo_index');
        });
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->dropIndex('batches_purchase_item_index');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('movements_batch_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_branch_status_schedule_created_index');
            $table->dropIndex('orders_status_pending_expiry_index');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id', 'order_items_order_index');
            $table->index('product_variant_id', 'order_items_variant_index');
            $table->index('combo_id', 'order_items_combo_index');
        });
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->index('purchase_item_id', 'batches_purchase_item_index');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index('inventory_batch_id', 'movements_batch_index');
        });
    }
};
