<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            // A retried request after a lost response must not add stock twice.
            $table->string('idempotency_key', 100)->nullable()->after('user_id');
            $table->unique(['branch_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
