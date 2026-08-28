<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->unique(
                ['order_id', 'loyalty_rule_id', 'type'],
                'loyalty_order_rule_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->dropUnique('loyalty_order_rule_type_unique');
        });
    }
};
