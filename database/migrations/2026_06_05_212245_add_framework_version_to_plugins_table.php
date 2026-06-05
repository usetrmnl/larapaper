<?php

use App\Models\Plugin;
use Database\Seeders\ExampleRecipesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('framework_version')->nullable()->after('preferred_renderer');
        });

        Plugin::query()
            ->wherePluginType('recipe')
            ->whereNotIn('uuid', ExampleRecipesSeeder::exampleUuids())
            ->update(['framework_version' => '2.3.7']);
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropColumn('framework_version');
        });
    }
};
