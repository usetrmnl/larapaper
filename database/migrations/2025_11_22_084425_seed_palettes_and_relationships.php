<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Device palettes are seeded from database/data by the catalog snapshot migration.
    }

    public function down(): void {}
};
