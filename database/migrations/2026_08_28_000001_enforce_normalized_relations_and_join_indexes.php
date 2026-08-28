<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name'], 'categories_branch_name_unique');
            $table->index(['branch_id', 'active', 'sort_order'], 'categories_branch_active_sort_index');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name'], 'products_branch_name_unique');
            $table->index(['branch_id', 'active'], 'products_branch_active_index');
            $table->index('product_category_id', 'products_category_index');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'name'], 'variants_product_name_unique');
            $table->index(['product_id', 'active'], 'variants_product_active_index');
        });
        Schema::table('product_flavors', function (Blueprint $table): void {
            $table->unique(['product_id', 'name'], 'flavors_product_name_unique');
            $table->index(['product_id', 'active'], 'flavors_product_active_index');
            $table->index('ingredient_id', 'flavors_ingredient_index');
        });
        Schema::table('modifiers', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name'], 'modifiers_branch_name_unique');
            $table->index(['branch_id', 'active'], 'modifiers_branch_active_index');
        });
        Schema::table('modifier_recipe_items', function (Blueprint $table): void {
            $table->unique(['modifier_id', 'ingredient_id'], 'modifier_ingredient_unique');
            $table->index('ingredient_id', 'modifier_items_ingredient_index');
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->index(['product_variant_id', 'active'], 'recipes_variant_active_index');
            $table->index('product_flavor_id', 'recipes_flavor_index');
        });
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->index('ingredient_id', 'recipe_items_ingredient_index');
        });
        Schema::table('combos', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name'], 'combos_branch_name_unique');
            $table->index(['branch_id', 'active'], 'combos_branch_active_index');
        });
        Schema::table('combo_items', function (Blueprint $table): void {
            $table->index('product_variant_id', 'combo_items_variant_index');
        });
        Schema::table('combo_allowed_options', function (Blueprint $table): void {
            $table->unique(['combo_item_id', 'product_flavor_id'], 'combo_flavor_option_unique');
            $table->unique(['combo_item_id', 'modifier_id'], 'combo_modifier_option_unique');
        });
        Schema::table('ingredient_presentations', function (Blueprint $table): void {
            $table->unique(['ingredient_id', 'name'], 'presentations_ingredient_name_unique');
            $table->index('equivalent_unit_id', 'presentations_unit_index');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name'], 'suppliers_branch_name_unique');
            $table->index(['branch_id', 'active'], 'suppliers_branch_active_index');
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->index(['branch_id', 'purchased_at'], 'purchases_branch_date_index');
            $table->index('supplier_id', 'purchases_supplier_index');
        });
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->index('purchase_id', 'purchase_items_purchase_index');
            $table->index('ingredient_id', 'purchase_items_ingredient_index');
            $table->index('ingredient_presentation_id', 'purchase_items_presentation_index');
        });
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->index(['branch_id', 'ingredient_id', 'available_quantity'], 'batches_branch_ingredient_available_index');
            $table->index('purchase_item_id', 'batches_purchase_item_index');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->index(['branch_id', 'ingredient_id', 'created_at'], 'movements_branch_ingredient_date_index');
            $table->index('inventory_batch_id', 'movements_batch_index');
        });
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->index(['ingredient_id', 'expires_at'], 'reservations_ingredient_expiry_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['branch_id', 'order_date', 'status'], 'orders_branch_date_status_index');
            $table->index(['customer_id', 'order_date'], 'orders_customer_date_index');
            $table->index(['scheduled_at', 'status'], 'orders_schedule_status_index');
            $table->index('user_id', 'orders_user_index');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id', 'order_items_order_index');
            $table->index('product_variant_id', 'order_items_variant_index');
            $table->index('combo_id', 'order_items_combo_index');
        });
        Schema::table('order_item_flavors', function (Blueprint $table): void {
            $table->unique(['order_item_id', 'product_flavor_id'], 'order_item_flavor_unique');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table): void {
            $table->unique(['order_item_id', 'modifier_id'], 'order_item_modifier_unique');
        });
        Schema::table('order_item_ingredients', function (Blueprint $table): void {
            $table->unique(['order_item_id', 'ingredient_id'], 'order_item_ingredient_unique');
        });
        Schema::table('order_item_components', function (Blueprint $table): void {
            $table->index('order_item_id', 'order_components_item_index');
            $table->index('product_variant_id', 'order_components_variant_index');
        });
        Schema::table('order_payments', function (Blueprint $table): void {
            $table->index(['order_id', 'method'], 'payments_order_method_index');
        });
        Schema::table('order_status_histories', function (Blueprint $table): void {
            $table->index(['order_id', 'created_at'], 'order_history_order_date_index');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['branch_id', 'active'], 'customers_branch_active_index');
            $table->index(['branch_id', 'email'], 'customers_branch_email_index');
        });
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->unique(['customer_id', 'label'], 'addresses_customer_label_unique');
            $table->index(['customer_id', 'is_default'], 'addresses_customer_default_index');
        });
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at'], 'loyalty_customer_date_index');
            $table->index(['expires_at', 'remaining_points'], 'loyalty_expiry_remaining_index');
        });
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->index(['cash_day_id', 'created_at'], 'cash_movements_day_date_index');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['branch_id', 'active'], 'users_branch_active_index');
            $table->index('role_id', 'users_role_index');
        });
    }

    public function down(): void
    {
        $this->drop('users', ['users_branch_active_index', 'users_role_index']);
        $this->drop('cash_movements', ['cash_movements_day_date_index']);
        $this->drop('loyalty_transactions', ['loyalty_customer_date_index', 'loyalty_expiry_remaining_index']);
        $this->drop('customer_addresses', ['addresses_customer_label_unique', 'addresses_customer_default_index']);
        $this->drop('customers', ['customers_branch_active_index', 'customers_branch_email_index']);
        $this->drop('order_status_histories', ['order_history_order_date_index']);
        $this->drop('order_payments', ['payments_order_method_index']);
        $this->drop('order_item_components', ['order_components_item_index', 'order_components_variant_index']);
        $this->drop('order_item_ingredients', ['order_item_ingredient_unique']);
        $this->drop('order_item_modifiers', ['order_item_modifier_unique']);
        $this->drop('order_item_flavors', ['order_item_flavor_unique']);
        $this->drop('order_items', ['order_items_order_index', 'order_items_variant_index', 'order_items_combo_index']);
        $this->drop('orders', ['orders_branch_date_status_index', 'orders_customer_date_index', 'orders_schedule_status_index', 'orders_user_index']);
        $this->drop('stock_reservations', ['reservations_ingredient_expiry_index']);
        $this->drop('inventory_movements', ['movements_branch_ingredient_date_index', 'movements_batch_index']);
        $this->drop('inventory_batches', ['batches_branch_ingredient_available_index', 'batches_purchase_item_index']);
        $this->drop('purchase_items', ['purchase_items_purchase_index', 'purchase_items_ingredient_index', 'purchase_items_presentation_index']);
        $this->drop('purchases', ['purchases_branch_date_index', 'purchases_supplier_index']);
        $this->drop('suppliers', ['suppliers_branch_name_unique', 'suppliers_branch_active_index']);
        $this->drop('ingredient_presentations', ['presentations_ingredient_name_unique', 'presentations_unit_index']);
        $this->drop('combo_allowed_options', ['combo_flavor_option_unique', 'combo_modifier_option_unique']);
        $this->drop('combo_items', ['combo_items_variant_index']);
        $this->drop('combos', ['combos_branch_name_unique', 'combos_branch_active_index']);
        $this->drop('recipe_items', ['recipe_items_ingredient_index']);
        $this->drop('recipes', ['recipes_variant_active_index', 'recipes_flavor_index']);
        $this->drop('modifier_recipe_items', ['modifier_ingredient_unique', 'modifier_items_ingredient_index']);
        $this->drop('modifiers', ['modifiers_branch_name_unique', 'modifiers_branch_active_index']);
        $this->drop('product_flavors', ['flavors_product_name_unique', 'flavors_product_active_index', 'flavors_ingredient_index']);
        $this->drop('product_variants', ['variants_product_name_unique', 'variants_product_active_index']);
        $this->drop('products', ['products_branch_name_unique', 'products_branch_active_index', 'products_category_index']);
        $this->drop('product_categories', ['categories_branch_name_unique', 'categories_branch_active_sort_index']);
    }

    private function drop(string $tableName, array $indexes): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
            foreach ($indexes as $index) {
                $table->dropIndex($index);
            }
        });
    }
};
