<?php
// database/migrations/2026_06_25_000002_add_is_shared_to_plugins_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn('is_shared');
        });
    }
};
