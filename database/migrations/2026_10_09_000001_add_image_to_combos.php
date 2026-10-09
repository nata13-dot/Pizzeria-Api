<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('combos', 'image_data_uri')) {
            Schema::table('combos', function (Blueprint $table): void {
                $table->mediumText('image_data_uri')->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('combos', 'image_data_uri')) {
            Schema::table('combos', function (Blueprint $table): void {
                $table->dropColumn('image_data_uri');
            });
        }
    }
};
