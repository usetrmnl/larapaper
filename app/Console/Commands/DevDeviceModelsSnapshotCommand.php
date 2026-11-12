<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DeviceCatalogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

#[Signature('dev:device-models:snapshot {--path= : Directory where models.json and palettes.json are written}')]
#[Description('Download the TRMNL device catalog into local JSON snapshots')]
final class DevDeviceModelsSnapshotCommand extends Command
{
    public function handle(DeviceCatalogService $catalog): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('This command can only be run in the local environment.');

            return self::FAILURE;
        }

        try {
            $palettes = $catalog->fetch(DeviceCatalogService::PALETTES_PATH);
            $models = $catalog->fetch(DeviceCatalogService::MODELS_PATH);
        } catch (Throwable $exception) {
            $this->error('Failed to download the device catalog: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $palettes->successful()) {
            $this->error('Failed to download palettes (HTTP '.$palettes->status().').');

            return self::FAILURE;
        }

        if (! $models->successful()) {
            $this->error('Failed to download device models (HTTP '.$models->status().').');

            return self::FAILURE;
        }

        $paletteDocument = $palettes->json();
        $modelDocument = $models->json();

        if (! is_array($paletteDocument) || ! is_array($modelDocument)) {
            $this->error('Catalog response was not JSON.');

            return self::FAILURE;
        }

        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? $path : database_path('data');

        File::ensureDirectoryExists($directory);

        try {
            $this->writeSnapshot($directory.'/palettes.json', $paletteDocument);
            $this->writeSnapshot($directory.'/models.json', $modelDocument);
        } catch (JsonException $exception) {
            $this->error('Failed to encode catalog JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Updated device catalog snapshots in '.$directory.'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeSnapshot(string $path, array $document): void
    {
        $encoded = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        File::put($path, $encoded.PHP_EOL);
    }
}
