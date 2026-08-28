<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('stock_shortage_authorized_by')->nullable()->after('stock_warnings')->constrained('users')->nullOnDelete();
            $table->dateTime('stock_shortage_authorized_at')->nullable()->after('stock_shortage_authorized_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_shortage_authorized_by');
            $table->dropColumn('stock_shortage_authorized_at');
        });
    }
};
