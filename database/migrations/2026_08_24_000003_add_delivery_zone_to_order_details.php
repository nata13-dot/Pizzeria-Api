<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_delivery_details', function (Blueprint $table): void {
            $table->string('delivery_zone', 100)->nullable()->after('map_url');
        });
    }

    public function down(): void
    {
        Schema::table('order_delivery_details', function (Blueprint $table): void {
            $table->dropColumn('delivery_zone');
        });
    }
};
