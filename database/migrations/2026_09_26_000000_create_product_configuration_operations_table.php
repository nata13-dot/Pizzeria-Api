<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_configuration_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('operation_key', 100);
            $table->char('request_hash', 64);
            $table->char('applied_revision', 64);
            $table->timestamps();
            $table->unique(['branch_id', 'user_id', 'product_id', 'operation_key'], 'product_config_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_configuration_operations');
    }
};
