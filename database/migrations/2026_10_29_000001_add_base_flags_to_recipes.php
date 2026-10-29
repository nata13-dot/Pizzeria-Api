<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            // A flavorless recipe marked as base is also consumed when flavors are chosen.
            $table->boolean('applies_to_flavors')->default(false)->after('product_flavor_id');
            // A flavor recipe that intentionally adds nothing to the base.
            $table->boolean('base_only')->default(false)->after('applies_to_flavors');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn(['applies_to_flavors', 'base_only']);
        });
    }
};
