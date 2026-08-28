<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('value');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_comment')->nullable()->after('cancelled_by');
        });
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->foreignId('reversal_of_transaction_id')
                ->nullable()
                ->unique()
                ->after('comment')
                ->constrained('loyalty_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_of_transaction_id');
        });
        Schema::table('loyalty_redemptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_comment']);
        });
    }
};
