<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_devices', 'notification_sound_mode')) {
            Schema::table('user_devices', function (Blueprint $table): void {
                $table->string('notification_sound_mode', 10)->default('fixed')->after('push_token');
            });
        }
        if (! Schema::hasColumn('user_devices', 'notification_channels')) {
            Schema::table('user_devices', function (Blueprint $table): void {
                $table->json('notification_channels')->nullable()->after('notification_sound_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_devices', 'notification_channels')) {
            Schema::table('user_devices', fn (Blueprint $table) => $table->dropColumn('notification_channels'));
        }
        if (Schema::hasColumn('user_devices', 'notification_sound_mode')) {
            Schema::table('user_devices', fn (Blueprint $table) => $table->dropColumn('notification_sound_mode'));
        }
    }
};
