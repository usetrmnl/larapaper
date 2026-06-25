<?php
// database/migrations/2026_06_25_000001_add_admin_columns_to_users_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('remember_token');
            $table->timestamp('confirmed_at')->nullable()->after('is_admin');
        });

        // All existing users are confirmed (no one gets locked out on upgrade)
        DB::table('users')->update(['confirmed_at' => now()]);

        // First user is always admin
        DB::table('users')->where('id', 1)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_admin', 'confirmed_at']);
        });
    }
};
