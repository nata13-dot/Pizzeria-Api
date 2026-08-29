<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'sales_channel')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('sales_channel', 30)->default('local')->after('type')->index();
            });
            DB::table('orders')->where('type', 'whatsapp')->update(['sales_channel' => 'whatsapp']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'sales_channel')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('sales_channel');
            });
        }
    }
};
