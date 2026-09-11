<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('orders')
            ->select(['branch_id', 'order_date', 'daily_number'])
            ->groupBy('branch_id', 'order_date', 'daily_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('Cannot add the daily-number unique index while duplicates exist.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(
                ['branch_id', 'order_date', 'daily_number'],
                'orders_branch_date_daily_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_branch_date_daily_number_unique');
        });
    }
};
