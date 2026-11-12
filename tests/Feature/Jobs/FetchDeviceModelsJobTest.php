<?php

declare(strict_types=1);

use App\Jobs\FetchDeviceModelsJob;
use App\Models\DeviceModel;
use App\Models\DevicePalette;
use App\Services\DeviceCatalogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    DeviceModel::truncate();
});

test('fetch device models job can be dispatched', function (): void {
    $job = new FetchDeviceModelsJob();
    expect($job)->toBeInstanceOf(FetchDeviceModelsJob::class);
});

test('fetch device models job handles successful api response', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'test-model',
                    'label' => 'Test Model',
                    'description' => 'A test device model',
                    'width' => 800,
                    'height' => 480,
                    'colors' => 4,
                    'bit_depth' => 2,
                    'scale_factor' => 1.0,
                    'rotation' => 0,
                    'mime_type' => 'image/png',
                    'offset_x' => 0,
                    'offset_y' => 0,
                    'kind' => 'trmnl',
                    'published_at' => '2023-01-01T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $deviceModel = DeviceModel::where('name', 'test-model')->first();
    expect($deviceModel)->not->toBeNull()
        ->and($deviceModel->label)->toBe('Test Model')
        ->and($deviceModel->description)->toBe('A test device model')
        ->and($deviceModel->width)->toBe(800)
        ->and($deviceModel->height)->toBe(480)
        ->and($deviceModel->colors)->toBe(4)
        ->and($deviceModel->bit_depth)->toBe(2)
        ->and($deviceModel->scale_factor)->toBe(1.0)
        ->and($deviceModel->rotation)->toBe(0)
        ->and($deviceModel->mime_type)->toBe('image/png')
        ->and($deviceModel->offset_x)->toBe(0)
        ->and($deviceModel->offset_y)->toBe(0)
        ->and($deviceModel->kind)->toBe('trmnl')
        ->and($deviceModel->source)->toBe('api');
});

test('fetch device models job handles multiple device models', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'model-1',
                    'label' => 'Model 1',
                    'description' => 'First model',
                    'width' => 800,
                    'height' => 480,
                    'colors' => 4,
                    'bit_depth' => 2,
                    'scale_factor' => 1.0,
                    'rotation' => 0,
                    'mime_type' => 'image/png',
                    'offset_x' => 0,
                    'offset_y' => 0,
                    'published_at' => '2023-01-01T00:00:00Z',
                ],
                [
                    'name' => 'model-2',
                    'label' => 'Model 2',
                    'description' => 'Second model',
                    'width' => 1200,
                    'height' => 800,
                    'colors' => 16,
                    'bit_depth' => 4,
                    'scale_factor' => 1.5,
                    'rotation' => 90,
                    'mime_type' => 'image/bmp',
                    'offset_x' => 10,
                    'offset_y' => 20,
                    'published_at' => '2023-01-02T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 2]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::where('name', 'model-1')->exists())->toBeTrue()
        ->and(DeviceModel::where('name', 'model-2')->exists())->toBeTrue();
});

test('fetch device models job handles empty data array', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 0]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles missing data field', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'message' => 'No data available',
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 0]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles non-array data', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => 'invalid-data',
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('error')
        ->once()
        ->with('Invalid response format from device models API', Mockery::type('array'));

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles api failure', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'error' => 'Internal Server Error',
        ], 500),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('error')
        ->once()
        ->with('Failed to fetch device models from API', [
            'status' => 500,
            'body' => '{"error":"Internal Server Error"}',
        ]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles network exception', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => function (): void {
            throw new Exception('Network connection failed');
        },
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('error')
        ->once()
        ->with('Exception occurred while fetching device models', Mockery::type('array'));

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles device model with missing name', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'label' => 'Model without name',
                    'description' => 'This model has no name',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Device model data missing name field', Mockery::type('array'));

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::count())->toBe(0);
});

test('fetch device models job handles device model with partial data', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'minimal-model',
                    // Only name provided, other fields should use defaults
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $deviceModel = DeviceModel::where('name', 'minimal-model')->first();
    expect($deviceModel)->not->toBeNull()
        ->and($deviceModel->label)->toBe('')
        ->and($deviceModel->description)->toBe('')
        ->and($deviceModel->width)->toBe(0)
        ->and($deviceModel->height)->toBe(0)
        ->and($deviceModel->colors)->toBe(0)
        ->and($deviceModel->bit_depth)->toBe(0)
        ->and($deviceModel->scale_factor)->toBe(1.0)
        ->and($deviceModel->rotation)->toBe(0)
        ->and($deviceModel->mime_type)->toBe('')
        ->and($deviceModel->offset_x)->toBe(0)
        ->and($deviceModel->offset_y)->toBe(0)
        ->and($deviceModel->kind)->toBeNull()
        ->and($deviceModel->source)->toBe('api');
});

test('fetch device models job updates existing device model', function (): void {
    // Create an existing device model
    $existingModel = DeviceModel::factory()->create([
        'name' => 'existing-model',
        'label' => 'Old Label',
        'width' => 400,
        'height' => 300,
    ]);

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'existing-model',
                    'label' => 'Updated Label',
                    'description' => 'Updated description',
                    'width' => 800,
                    'height' => 600,
                    'colors' => 4,
                    'bit_depth' => 2,
                    'scale_factor' => 1.0,
                    'rotation' => 0,
                    'mime_type' => 'image/png',
                    'offset_x' => 0,
                    'offset_y' => 0,
                    'published_at' => '2023-01-01T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $existingModel->refresh();
    expect($existingModel->label)->toBe('Updated Label')
        ->and($existingModel->description)->toBe('Updated description')
        ->and($existingModel->width)->toBe(800)
        ->and($existingModel->height)->toBe(600)
        ->and($existingModel->source)->toBe('api');
});

test('fetch device models job handles processing exception for individual model', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'valid-model',
                    'label' => 'Valid Model',
                    'width' => 800,
                    'height' => 480,
                ],
                [
                    'name' => null, // This will cause an exception in processing
                    'label' => 'Invalid Model',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Device model data missing name field', Mockery::type('array'));

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 2]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    // Should still create the valid model
    expect(DeviceModel::where('name', 'valid-model')->exists())->toBeTrue();
    expect(DeviceModel::count())->toBe(1);
});

test('fetch device models job sets palette from default palette id', function (): void {
    DevicePalette::query()->delete();

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response([
            'data' => [
                [
                    'id' => 'bw',
                    'name' => 'Black & White',
                    'grays' => 2,
                    'framework_class' => 'screen--1bit',
                ],
                [
                    'id' => 'gray-16',
                    'name' => '16 Grays',
                    'grays' => 16,
                    'framework_class' => 'screen--4bit',
                ],
            ],
        ], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'palette-model',
                    'label' => 'Palette Model',
                    'width' => 800,
                    'height' => 480,
                    'palette_ids' => ['bw'],
                    'default_palette_id' => 'gray-16',
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 2]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $deviceModel = DeviceModel::query()->where('name', 'palette-model')->first();
    $palette = DevicePalette::query()->where('name', 'gray-16')->first();

    expect($deviceModel)->not->toBeNull()
        ->and($palette)->not->toBeNull()
        ->and($deviceModel->palette_id)->toBe($palette->id);
});

test('fetch device models job leaves manual device models unchanged', function (): void {
    $manualModel = DeviceModel::factory()->create([
        'name' => 'custom-model',
        'label' => 'Custom Label',
        'source' => 'manual',
    ]);
    $width = $manualModel->width;

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'custom-model',
                    'label' => 'API Label',
                    'width' => 100,
                    'height' => 100,
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $manualModel->refresh();

    expect($manualModel->label)->toBe('Custom Label')
        ->and($manualModel->source)->toBe('manual')
        ->and($manualModel->width)->toBe($width);
});

test('fetch device models job skips unsupported device models', function (): void {
    $vestaboard = DeviceModel::factory()->create([
        'name' => 'vestaboard',
        'label' => 'Existing Vestaboard',
        'source' => 'api',
    ]);

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'vestaboard',
                    'label' => 'Updated Vestaboard',
                ],
                [
                    'name' => 'byod_custom',
                    'label' => 'Custom Device',
                ],
                [
                    'name' => 'tidbyt',
                    'label' => 'Tidbyt',
                ],
                [
                    'name' => 'og_png',
                    'label' => 'TRMNL OG (1-bit)',
                ],
            ],
        ], 200),
    ]);

    (new FetchDeviceModelsJob)->handle(app(DeviceCatalogService::class));

    $vestaboard->refresh();

    expect($vestaboard->label)->toBe('Existing Vestaboard');
    expect(DeviceModel::query()->where('name', 'og_png')->exists())->toBeTrue();
    expect(DeviceModel::query()->whereIn('name', ['byod_custom', 'tidbyt'])->exists())->toBeFalse();
});

test('fetch device models job assigns the first ids to trmnl models and stores gray-4 for og plus', function (): void {
    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response([
            'data' => [
                [
                    'id' => 'gray-4',
                    'name' => '4 Grays',
                    'grays' => 4,
                    'framework_class' => 'screen--2bit',
                ],
            ],
        ], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'v2',
                    'label' => 'TRMNL X',
                    'default_palette_id' => 'gray-16',
                ],
                [
                    'name' => 'other-model',
                    'label' => 'Other',
                ],
                [
                    'name' => 'og_png',
                    'label' => 'TRMNL OG (1-bit)',
                    'default_palette_id' => 'bw',
                ],
                [
                    'name' => 'og_plus',
                    'label' => 'TRMNL OG (2-bit)',
                    'default_palette_id' => 'bw',
                ],
                [
                    'name' => 'og_bwry',
                    'label' => 'TRMNL OG (B/W/R/Y)',
                    'default_palette_id' => 'color-4bwry',
                ],
            ],
        ], 200),
    ]);

    (new FetchDeviceModelsJob)->handle(app(DeviceCatalogService::class));

    expect(DeviceModel::query()->orderBy('id')->pluck('name')->all())->toBe([
        'og_png',
        'og_plus',
        'v2',
        'og_bwry',
        'other-model',
    ])
        ->and(DeviceModel::query()->find(1)?->name)->toBe('og_png')
        ->and(DeviceModel::query()->find(2)?->name)->toBe('og_plus')
        ->and(DeviceModel::query()->find(3)?->name)->toBe('v2')
        ->and(DeviceModel::query()->find(4)?->name)->toBe('og_bwry');

    expect(DeviceModel::query()->where('name', 'og_plus')->first()?->palette?->name)->toBe('gray-4');
});

test('fetch device models job keeps existing ids and still stores gray-4 for og plus', function (): void {
    $ogPlus = DeviceModel::factory()->create([
        'name' => 'og_plus',
        'label' => 'TRMNL OG (2-bit)',
        'source' => 'api',
    ]);
    $ogPlusId = $ogPlus->id;

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response([
            'data' => [
                [
                    'id' => 'gray-4',
                    'name' => '4 Grays',
                    'grays' => 4,
                    'framework_class' => 'screen--2bit',
                ],
            ],
        ], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'v2',
                    'label' => 'TRMNL X',
                ],
                [
                    'name' => 'og_png',
                    'label' => 'TRMNL OG (1-bit)',
                ],
                [
                    'name' => 'og_plus',
                    'label' => 'TRMNL OG (2-bit)',
                    'default_palette_id' => 'bw',
                ],
            ],
        ], 200),
    ]);

    (new FetchDeviceModelsJob)->handle(app(DeviceCatalogService::class));

    $ogPlus->refresh();

    expect($ogPlus->id)->toBe($ogPlusId)
        ->and($ogPlus->palette?->name)->toBe('gray-4');

    expect(DeviceModel::query()->where('name', 'v2')->value('id'))
        ->toBeLessThan(DeviceModel::query()->where('name', 'og_png')->value('id'));
});

test('fetch device models job keeps published at when the payload omits it', function (): void {
    $deviceModel = DeviceModel::factory()->create([
        'name' => 'dated-model',
        'label' => 'Old Label',
        'published_at' => '2024-01-01 00:00:00',
        'source' => 'api',
    ]);

    Http::fake([
        config('services.trmnl.base_url').'/api/palettes' => Http::response(['data' => []], 200),
        config('services.trmnl.base_url').'/api/models' => Http::response([
            'data' => [
                [
                    'name' => 'dated-model',
                    'label' => 'Updated Label',
                    'width' => 800,
                    'height' => 480,
                ],
            ],
        ], 200),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated palettes', ['count' => 0]);

    Log::shouldReceive('info')
        ->once()
        ->with('Successfully fetched and updated device models', ['count' => 1]);

    $job = new FetchDeviceModelsJob();
    $job->handle(app(DeviceCatalogService::class));

    $deviceModel->refresh();

    expect($deviceModel->label)->toBe('Updated Label')
        ->and($deviceModel->published_at?->toDateTimeString())->toBe('2024-01-01 00:00:00');
});
