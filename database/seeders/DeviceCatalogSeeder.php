<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\DeviceCatalogService;
use Illuminate\Database\Seeder;
use RuntimeException;

class DeviceCatalogSeeder extends Seeder
{
    public function run(DeviceCatalogService $catalog): void
    {
        $catalog->sync(
            $this->records('models.json'),
            $this->records('palettes.json'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(string $filename): array
    {
        $path = database_path('data/'.$filename);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read device catalog file [{$path}].");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            throw new RuntimeException("Device catalog file [{$path}] is missing a data array.");
        }

        return $decoded['data'];
    }
}
