<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combos', function (Blueprint $table): void {
            // Days of the week (0 = Sunday) when the combo is sold; null means every day.
            $table->json('available_days')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table): void {
            $table->dropColumn('available_days');
        });
    }
};
