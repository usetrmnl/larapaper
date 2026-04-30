<?php

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Models\User;
use Bnussbau\TrmnlPipeline\TrmnlPipeline;
use Illuminate\Support\Facades\Storage;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    TrmnlPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

test('it generates screen images and updates device', function (): void {
    $device = Device::factory()->create();
    $job = new GenerateScreenJob($device->id, null, view('trmnl')->render());
    $job->handle();

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->not->toBeNull();

    // Assert both PNG and BMP files were created
    $uuid = $device->current_screen_image;
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

test('it cleans up unused images', function (): void {
    // Create some test devices with images
    $activeDevice = Device::factory()->create([
        'current_screen_image' => 'uuid-to-be-replaced',
    ]);

    // Create some test files
    Storage::disk('public')->put('/images/generated/uuid-to-be-replaced.png', 'test');
    Storage::disk('public')->put('/images/generated/uuid-to-be-replaced.bmp', 'test');
    Storage::disk('public')->put('/images/generated/inactive-uuid.png', 'test');
    Storage::disk('public')->put('/images/generated/inactive-uuid.bmp', 'test');

    // Run a job which will trigger cleanup
    $job = new GenerateScreenJob($activeDevice->id, null, '<div>Test</div>');
    $job->handle();

    Storage::disk('public')->assertMissing('/images/generated/uuid-to-be-replaced.png');
    Storage::disk('public')->assertMissing('/images/generated/uuid-to-be-replaced.bmp');
    Storage::disk('public')->assertMissing('/images/generated/inactive-uuid.png');
    Storage::disk('public')->assertMissing('/images/generated/inactive-uuid.bmp');
});

test('it preserves gitignore file during cleanup', function (): void {
    Storage::disk('public')->put('/images/generated/.gitignore', '*');

    $device = Device::factory()->create();
    $job = new GenerateScreenJob($device->id, null, '<div>Test</div>');
    $job->handle();

    Storage::disk('public')->assertExists('/images/generated/.gitignore');
});

test('it copies plugin current image to device for processed image plugins without pipeline', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'previous-screen',
    ]);
    $plugin = Plugin::factory()->for($user)->imageWebhook()->create([
        'current_image' => 'webhook-ready-uuid',
    ]);

    $job = new GenerateScreenJob($device->id, $plugin->id, '<div>ignored</div>');
    $job->handle();

    expect($device->fresh()->current_screen_image)->toBe('webhook-ready-uuid');
    expect($plugin->fresh()->current_image)->toBe('webhook-ready-uuid');
});

test('it skips device update for processed image plugin when current image is missing', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'stable-screen',
    ]);
    $plugin = Plugin::factory()->for($user)->imageWebhook()->create([
        'current_image' => null,
    ]);

    $job = new GenerateScreenJob($device->id, $plugin->id, '<div>ignored</div>');
    $job->handle();

    expect($device->fresh()->current_screen_image)->toBe('stable-screen');
});

test('it saves current_image_metadata for recipe plugins', function (): void {
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'palette_id' => null,
    ]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id]);
    $plugin = Plugin::factory()->create(['plugin_type' => 'recipe']);

    $job = new GenerateScreenJob($device->id, $plugin->id, '<div>Test</div>');
    $job->handle();

    $plugin->refresh();
    expect($plugin->current_image)->not->toBeNull();
    expect($plugin->current_image_metadata)->toBeArray();
    expect($plugin->current_image_metadata)->toHaveKeys(['width', 'height', 'rotation', 'palette_id', 'mime_type']);
    expect($plugin->current_image_metadata['width'])->toBe(800);
    expect($plugin->current_image_metadata['height'])->toBe(480);
    expect($plugin->current_image_metadata['mime_type'])->toBe('image/png');
    expect($plugin->data_payload_updated_at)->not->toBeNull();
});
