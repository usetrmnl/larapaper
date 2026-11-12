<?php

declare(strict_types=1);

use App\Models\DeviceModel;
use App\Models\DevicePalette;
use App\Services\DeviceCatalogService;
use Database\Seeders\DeviceCatalogSeeder;
use Illuminate\Support\Facades\Http;

test('device catalog seeder loads models and palettes from local files', function (): void {
    Http::preventStrayRequests();

    DeviceModel::query()->delete();
    DevicePalette::query()->delete();

    (new DeviceCatalogSeeder)->run(app(DeviceCatalogService::class));

    $deviceModel = DeviceModel::query()->where('name', 'v2')->first();

    expect($deviceModel)->not->toBeNull()
        ->and($deviceModel->source)->toBe('api')
        ->and($deviceModel->palette?->name)->toBe('gray-16')
        ->and(DevicePalette::query()->where('name', 'gray-16')->exists())->toBeTrue();
});

test('device catalog seeder does not overwrite manual device models', function (): void {
    Http::preventStrayRequests();

    $deviceModel = DeviceModel::query()->where('name', 'v2')->firstOrFail();
    $deviceModel->update([
        'label' => 'Local V2',
        'source' => 'manual',
    ]);

    (new DeviceCatalogSeeder)->run(app(DeviceCatalogService::class));

    $deviceModel->refresh();

    expect($deviceModel->label)->toBe('Local V2')
        ->and($deviceModel->source)->toBe('manual')
        ->and(DeviceModel::query()->where('name', 'v2')->count())->toBe(1);
});

test('device catalog seeder imports trmnl models first and stores gray-4 for og plus', function (): void {
    Http::preventStrayRequests();

    DeviceModel::query()->truncate();
    DevicePalette::query()->truncate();

    (new DeviceCatalogSeeder)->run(app(DeviceCatalogService::class));

    expect(DeviceModel::query()->find(1)?->name)->toBe('og_png')
        ->and(DeviceModel::query()->find(2)?->name)->toBe('og_plus')
        ->and(DeviceModel::query()->find(3)?->name)->toBe('v2')
        ->and(DeviceModel::query()->find(4)?->name)->toBe('og_bwry');

    expect(DeviceModel::query()->where('name', 'og_plus')->first()?->palette?->name)->toBe('gray-4');
    expect(DeviceModel::query()->where('name', 'v2')->first()?->palette?->name)->toBe('gray-16');
    expect(DeviceModel::query()->where('name', 'amazon_kindle_2024')->first()?->id)->toBeGreaterThan(4);
});

test('device catalog seeder skips unsupported device models', function (): void {
    Http::preventStrayRequests();

    DeviceModel::query()->delete();

    (new DeviceCatalogSeeder)->run(app(DeviceCatalogService::class));

    expect(DeviceModel::query()->whereIn('name', ['vestaboard', 'byod_custom', 'tidbyt'])->exists())->toBeFalse();
    expect(DeviceModel::query()->where('name', 'og_png')->exists())->toBeTrue();
});

test('device catalog seeder does not overwrite manual palettes', function (): void {
    Http::preventStrayRequests();

    $palette = DevicePalette::query()->where('name', 'gray-16')->firstOrFail();
    $palette->update([
        'description' => 'Custom grays',
        'source' => 'manual',
    ]);

    (new DeviceCatalogSeeder)->run(app(DeviceCatalogService::class));

    $palette->refresh();

    expect($palette->description)->toBe('Custom grays')
        ->and($palette->source)->toBe('manual');
});
