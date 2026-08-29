<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            ['product_categories', ['branch_id', 'name'], 'categories_branch_name_unique', true],
            ['product_categories', ['branch_id', 'active', 'sort_order'], 'categories_branch_active_sort_index', false],
            ['products', ['branch_id', 'name'], 'products_branch_name_unique', true],
            ['products', ['branch_id', 'active'], 'products_branch_active_index', false],
            ['products', ['product_category_id'], 'products_category_index', false],
            ['product_variants', ['product_id', 'name'], 'variants_product_name_unique', true],
            ['product_variants', ['product_id', 'active'], 'variants_product_active_index', false],
            ['product_flavors', ['product_id', 'name'], 'flavors_product_name_unique', true],
            ['product_flavors', ['product_id', 'active'], 'flavors_product_active_index', false],
            ['product_flavors', ['ingredient_id'], 'flavors_ingredient_index', false],
            ['modifiers', ['branch_id', 'name'], 'modifiers_branch_name_unique', true],
            ['modifiers', ['branch_id', 'active'], 'modifiers_branch_active_index', false],
            ['modifier_recipe_items', ['modifier_id', 'ingredient_id'], 'modifier_ingredient_unique', true],
            ['modifier_recipe_items', ['ingredient_id'], 'modifier_items_ingredient_index', false],
            ['recipes', ['product_variant_id', 'active'], 'recipes_variant_active_index', false],
            ['recipes', ['product_flavor_id'], 'recipes_flavor_index', false],
            ['recipe_items', ['ingredient_id'], 'recipe_items_ingredient_index', false],
            ['combos', ['branch_id', 'name'], 'combos_branch_name_unique', true],
            ['combos', ['branch_id', 'active'], 'combos_branch_active_index', false],
            ['combo_items', ['product_variant_id'], 'combo_items_variant_index', false],
            ['combo_allowed_options', ['combo_item_id', 'product_flavor_id'], 'combo_flavor_option_unique', true],
            ['combo_allowed_options', ['combo_item_id', 'modifier_id'], 'combo_modifier_option_unique', true],
            ['ingredient_presentations', ['ingredient_id', 'name'], 'presentations_ingredient_name_unique', true],
            ['ingredient_presentations', ['equivalent_unit_id'], 'presentations_unit_index', false],
            ['suppliers', ['branch_id', 'name'], 'suppliers_branch_name_unique', true],
            ['suppliers', ['branch_id', 'active'], 'suppliers_branch_active_index', false],
            ['purchases', ['branch_id', 'purchased_at'], 'purchases_branch_date_index', false],
            ['purchases', ['supplier_id'], 'purchases_supplier_index', false],
            ['purchase_items', ['purchase_id'], 'purchase_items_purchase_index', false],
            ['purchase_items', ['ingredient_id'], 'purchase_items_ingredient_index', false],
            ['purchase_items', ['ingredient_presentation_id'], 'purchase_items_presentation_index', false],
            ['inventory_batches', ['branch_id', 'ingredient_id', 'available_quantity'], 'batches_branch_ingredient_available_index', false],
            ['inventory_batches', ['purchase_item_id'], 'batches_purchase_item_index', false],
            ['inventory_movements', ['branch_id', 'ingredient_id', 'created_at'], 'movements_branch_ingredient_date_index', false],
            ['inventory_movements', ['inventory_batch_id'], 'movements_batch_index', false],
            ['stock_reservations', ['ingredient_id', 'expires_at'], 'reservations_ingredient_expiry_index', false],
            ['orders', ['branch_id', 'order_date', 'status'], 'orders_branch_date_status_index', false],
            ['orders', ['customer_id', 'order_date'], 'orders_customer_date_index', false],
            ['orders', ['scheduled_at', 'status'], 'orders_schedule_status_index', false],
            ['orders', ['user_id'], 'orders_user_index', false],
            ['order_items', ['order_id'], 'order_items_order_index', false],
            ['order_items', ['product_variant_id'], 'order_items_variant_index', false],
            ['order_items', ['combo_id'], 'order_items_combo_index', false],
            ['order_item_flavors', ['order_item_id', 'product_flavor_id'], 'order_item_flavor_unique', true],
            ['order_item_modifiers', ['order_item_id', 'modifier_id'], 'order_item_modifier_unique', true],
            ['order_item_ingredients', ['order_item_id', 'ingredient_id'], 'order_item_ingredient_unique', true],
            ['order_item_components', ['order_item_id'], 'order_components_item_index', false],
            ['order_item_components', ['product_variant_id'], 'order_components_variant_index', false],
            ['order_payments', ['order_id', 'method'], 'payments_order_method_index', false],
            ['order_status_histories', ['order_id', 'created_at'], 'order_history_order_date_index', false],
            ['customers', ['branch_id', 'active'], 'customers_branch_active_index', false],
            ['customers', ['branch_id', 'email'], 'customers_branch_email_index', false],
            ['customer_addresses', ['customer_id', 'label'], 'addresses_customer_label_unique', true],
            ['customer_addresses', ['customer_id', 'is_default'], 'addresses_customer_default_index', false],
            ['loyalty_transactions', ['customer_id', 'created_at'], 'loyalty_customer_date_index', false],
            ['loyalty_transactions', ['expires_at', 'remaining_points'], 'loyalty_expiry_remaining_index', false],
            ['cash_movements', ['cash_day_id', 'created_at'], 'cash_movements_day_date_index', false],
            ['users', ['branch_id', 'active'], 'users_branch_active_index', false],
            ['users', ['role_id'], 'users_role_index', false],
        ];

        foreach ($indexes as [$tableName, $columns, $indexName, $unique]) {
            $this->addIndex($tableName, $columns, $indexName, $unique);
        }
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
        foreach ($indexes as $index) {
            if (Schema::hasIndex($tableName, $index)) {
                Schema::table($tableName, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }
    }

    private function addIndex(string $tableName, array $columns, string $indexName, bool $unique): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique): void {
            if ($unique) {
                $table->unique($columns, $indexName);
            } else {
                $table->index($columns, $indexName);
            }
        });
    }
};
