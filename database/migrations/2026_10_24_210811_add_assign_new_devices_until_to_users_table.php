<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Auto-Join becomes a time-limited window. Existing permanent Auto-Join
     * settings are intentionally not carried over.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('assign_new_devices_until')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assign_new_devices');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('assign_new_devices')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assign_new_devices_until');
        });
    }
};
