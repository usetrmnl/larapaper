<?php

use Database\Seeders\DeviceCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app()->call([new DeviceCatalogSeeder, 'run']);
    }

    public function down(): void
    {
        // Catalog rows stay in place so devices keep their model references.
    }
};
