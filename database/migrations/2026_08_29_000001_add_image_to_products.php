<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'image_data_uri')) {
            Schema::table('products', function (Blueprint $table) {
                $table->mediumText('image_data_uri')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'image_data_uri')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('image_data_uri');
            });
        }
    }
};
