<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('sleep_plugin_id')->nullable()->after('sleep_mode_to')->constrained('plugins')->nullOnDelete();
            $table->foreignId('pause_plugin_id')->nullable()->after('pause_until')->constrained('plugins')->nullOnDelete();
            $table->string('offline_screen_image')->nullable()->after('current_screen_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sleep_plugin_id');
            $table->dropConstrainedForeignId('pause_plugin_id');
            $table->dropColumn('offline_screen_image');
        });
    }
};
