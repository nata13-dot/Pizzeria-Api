<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->string('delivery_zone', 100)->nullable()->after('map_url');
            $table->text('notes')->nullable()->after('delivery_zone');
        });

        Schema::table('loyalty_rules', function (Blueprint $table): void {
            $table->string('type', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rules', function (Blueprint $table): void {
            $table->enum('type', ['per_amount', 'per_order', 'product', 'category'])->change();
        });

        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->dropColumn(['delivery_zone', 'notes']);
        });
    }
};
