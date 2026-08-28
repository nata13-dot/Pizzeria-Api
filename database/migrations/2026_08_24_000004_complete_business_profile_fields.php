<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->string('secondary_color', 20)->default('#29231f')->after('primary_color');
            $table->json('social_links')->nullable()->after('tax_id');
            $table->boolean('show_business_details')->default(true)->after('social_links');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn(['secondary_color', 'social_links', 'show_business_details']);
        });
    }
};
