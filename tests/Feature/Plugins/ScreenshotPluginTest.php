<?php

declare(strict_types=1);

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\ScreenshotPlugin;
use Bnussbau\TrmnlPipeline\TrmnlPipeline;
use Illuminate\Support\Facades\Storage;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    TrmnlPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

test('GenerateScreenJob runs BrowserStage with URL and updates plugin metadata', function (): void {
    $user = User::factory()->create();
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'palette_id' => null,
    ]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'device_model_id' => $deviceModel->id,
    ]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => ScreenshotPlugin::KEY,
        'data_strategy' => 'static',
        'data_stale_minutes' => 60,
        'configuration' => ['url' => 'https://example.com'],
    ]);

    $job = new GenerateScreenJob($device->id, $plugin->id, '');
    $job->handle();

    $plugin->refresh();

    expect($plugin->current_image)->not->toBeNull();
    expect($plugin->current_image_metadata)->toBeArray();
    expect($plugin->current_image_metadata)->toHaveKeys(['width', 'height', 'rotation', 'palette_id', 'mime_type']);
    expect($plugin->current_image_metadata['width'])->toBe(800);
    expect($plugin->current_image_metadata['height'])->toBe(480);
    expect($plugin->data_payload_updated_at)->not->toBeNull();

    Storage::disk('public')->assertExists('/images/generated/'.$plugin->current_image.'.png');
});

test('GenerateScreenJob throws when URL configuration is missing', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => ScreenshotPlugin::KEY,
        'data_strategy' => 'static',
        'configuration' => [],
    ]);

    $job = new GenerateScreenJob($device->id, $plugin->id, '');

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, "missing 'url' configuration");
});

test('screenshot instance is stale when data_payload_updated_at is unset', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => ScreenshotPlugin::KEY,
        'data_strategy' => 'static',
        'data_stale_minutes' => 60,
        'data_payload_updated_at' => null,
    ]);

    expect($plugin->isDataStale())->toBeTrue();
});

test('screenshot instance is fresh within data_stale_minutes window', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => ScreenshotPlugin::KEY,
        'data_strategy' => 'static',
        'data_stale_minutes' => 60,
        'data_payload_updated_at' => now()->subMinutes(5),
    ]);

    expect($plugin->isDataStale())->toBeFalse();
});
