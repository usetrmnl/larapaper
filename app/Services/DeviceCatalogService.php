<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeviceModel;
use App\Models\DevicePalette;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DeviceCatalogService
{
    public const string MODELS_PATH = '/api/models';

    public const string PALETTES_PATH = '/api/palettes';

    /**
     * Catalog fields replaced on every import from the snapshot file and the API.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array MODEL_OVERRIDES = [
        'og_plus' => [
            'default_palette_id' => 'gray-4',
        ],
    ];

    /**
     * Device models omitted on import because they are not supported yet.
     *
     * @var list<string>
     */
    private const array IGNORED_MODEL_NAMES = [
        'vestaboard',
        'byod_custom',
        'tidbyt',
    ];

    /**
     * Names inserted first when device_models is empty.
     * A fresh seed then gets ids 1 og_png, 2 og_plus, 3 v2, 4 og_bwry.
     *
     * @var list<string>
     */
    private const array INITIAL_MODEL_NAMES = [
        'og_png',
        'og_plus',
        'v2',
        'og_bwry',
    ];

    public function url(string $path): string
    {
        return config('services.trmnl.base_url').$path;
    }

    public function fetch(string $path): Response
    {
        return Http::timeout(30)->get($this->url($path));
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @param  array<int, array<string, mixed>>  $palettes
     */
    public function sync(array $models, array $palettes): void
    {
        $this->syncPalettes($palettes);
        $this->syncModels($models);
    }

    /**
     * @param  array<int, array<string, mixed>>  $palettes
     */
    private function syncPalettes(array $palettes): void
    {
        foreach ($palettes as $paletteData) {
            try {
                $this->updateOrCreatePalette($paletteData);
            } catch (Exception $exception) {
                Log::error('Failed to process palette', [
                    'palette_data' => $paletteData,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     */
    private function syncModels(array $models): void
    {
        $models = $this->applyModelOverrides($models);

        if (DeviceModel::query()->doesntExist()) {
            $models = $this->prioritizeInitialModels($models);
        }

        foreach ($models as $modelData) {
            try {
                $this->updateOrCreateDeviceModel($modelData);
            } catch (Exception $exception) {
                Log::error('Failed to process device model', [
                    'model_data' => $modelData,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private function applyModelOverrides(array $models): array
    {
        $ignored = array_flip(self::IGNORED_MODEL_NAMES);
        $prepared = [];

        foreach ($models as $modelData) {
            $name = $modelData['name'] ?? null;

            if (is_string($name) && array_key_exists($name, $ignored)) {
                continue;
            }

            if (is_string($name) && array_key_exists($name, self::MODEL_OVERRIDES)) {
                $modelData = array_merge($modelData, self::MODEL_OVERRIDES[$name]);
            }

            $prepared[] = $modelData;
        }

        return $prepared;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private function prioritizeInitialModels(array $models): array
    {
        $priority = array_flip(self::INITIAL_MODEL_NAMES);
        $leading = [];
        $remaining = [];

        foreach ($models as $modelData) {
            $name = $modelData['name'] ?? null;

            if (is_string($name) && array_key_exists($name, $priority)) {
                $leading[$priority[$name]] = $modelData;

                continue;
            }

            $remaining[] = $modelData;
        }

        ksort($leading);

        return array_merge(array_values($leading), $remaining);
    }

    /**
     * @param  array<string, mixed>  $paletteData
     */
    private function updateOrCreatePalette(array $paletteData): void
    {
        $name = $paletteData['id'] ?? null;

        if (! is_string($name) || $name === '') {
            Log::warning('Palette data missing id field', [
                'palette_data' => $paletteData,
            ]);

            return;
        }

        $palette = DevicePalette::query()->where('name', $name)->first();

        if (! $this->isCatalogRecord($palette?->source)) {
            return;
        }

        DevicePalette::unguarded(function () use ($name, $paletteData): void {
            DevicePalette::query()->updateOrCreate(
                ['name' => $name],
                [
                    'name' => $name,
                    'description' => $paletteData['name'] ?? '',
                    'grays' => $paletteData['grays'] ?? 2,
                    'colors' => $paletteData['colors'] ?? null,
                    'framework_class' => $paletteData['framework_class'] ?? '',
                    'source' => 'api',
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    private function updateOrCreateDeviceModel(array $modelData): void
    {
        $name = $modelData['name'] ?? null;

        if (! is_string($name) || $name === '') {
            Log::warning('Device model data missing name field', [
                'model_data' => $modelData,
            ]);

            return;
        }

        $deviceModel = DeviceModel::query()->where('name', $name)->first();

        if (! $this->isCatalogRecord($deviceModel?->source)) {
            return;
        }

        $attributes = [
            'label' => $modelData['label'] ?? '',
            'description' => $modelData['description'] ?? '',
            'width' => $modelData['width'] ?? 0,
            'height' => $modelData['height'] ?? 0,
            'colors' => $modelData['colors'] ?? 0,
            'bit_depth' => $modelData['bit_depth'] ?? 0,
            'scale_factor' => $modelData['scale_factor'] ?? 1,
            'rotation' => $modelData['rotation'] ?? 0,
            'mime_type' => $modelData['mime_type'] ?? '',
            'offset_x' => $modelData['offset_x'] ?? 0,
            'offset_y' => $modelData['offset_y'] ?? 0,
            'kind' => $modelData['kind'] ?? null,
            'source' => 'api',
            'css_name' => $this->parseCssNameFromApi($modelData['css'] ?? null),
            'css_variables' => $this->parseCssVariablesFromApi($modelData['css'] ?? null),
        ];

        if (array_key_exists('published_at', $modelData)) {
            $attributes['published_at'] = $modelData['published_at'];
        }

        $paletteId = $this->paletteId($this->paletteName($modelData));

        if ($paletteId !== null) {
            $attributes['palette_id'] = $paletteId;
        }

        DeviceModel::unguarded(function () use ($name, $attributes): void {
            DeviceModel::query()->updateOrCreate(
                ['name' => $name],
                $attributes,
            );
        });
    }

    private function isCatalogRecord(mixed $source): bool
    {
        return $source === null || $source === 'api';
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    private function paletteName(array $modelData): ?string
    {
        if (array_key_exists('default_palette_id', $modelData)) {
            $defaultPaletteId = $modelData['default_palette_id'];

            return is_string($defaultPaletteId) && $defaultPaletteId !== '' ? $defaultPaletteId : null;
        }

        $paletteName = null;

        if (isset($modelData['palette_ids']) && is_array($modelData['palette_ids']) && $modelData['palette_ids'] !== []) {
            $paletteName = $modelData['palette_ids'][0];
        }

        if (! $paletteName && isset($modelData['palettes']) && is_array($modelData['palettes']) && $modelData['palettes'] !== []) {
            $firstPalette = $modelData['palettes'][0];

            if (is_array($firstPalette) && isset($firstPalette['id'])) {
                $paletteName = $firstPalette['id'];
            }
        }

        return is_string($paletteName) && $paletteName !== '' ? $paletteName : null;
    }

    private function paletteId(?string $paletteName): ?int
    {
        if ($paletteName === null) {
            return null;
        }

        $palette = DevicePalette::query()->where('name', $paletteName)->first();

        if ($palette === null) {
            return null;
        }

        return (int) $palette->id;
    }

    private function parseCssNameFromApi(mixed $css): ?string
    {
        $deviceClass = is_array($css) ? Arr::get($css, 'classes.device') : null;

        return (is_string($deviceClass) ? Str::after($deviceClass, 'screen--') : null) ?: null;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseCssVariablesFromApi(mixed $css): ?array
    {
        $pairs = is_array($css) ? Arr::get($css, 'variables', []) : [];

        if (! is_array($pairs)) {
            return null;
        }

        $validPairs = Arr::where($pairs, fn (mixed $pair): bool => is_array($pair) && isset($pair[0], $pair[1]));
        $variables = Arr::pluck($validPairs, 1, 0);

        /** @var array<string, string> $variables */
        return $variables !== [] ? $variables : null;
    }
}
