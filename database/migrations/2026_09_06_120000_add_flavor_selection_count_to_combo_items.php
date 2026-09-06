<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combo_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('flavor_selection_count')->nullable()->after('flavor_required');
        });
    }

    public function down(): void
    {
        Schema::table('combo_items', function (Blueprint $table): void {
            $table->dropColumn('flavor_selection_count');
        });
    }
};
