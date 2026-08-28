<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combo_items', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('flavor_required');
            $table->index(['combo_id', 'active'], 'combo_items_combo_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('combo_items', function (Blueprint $table): void {
            $table->dropIndex('combo_items_combo_active_index');
            $table->dropColumn('active');
        });
    }
};
