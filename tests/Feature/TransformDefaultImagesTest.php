<?php

use App\Models\Device;
use App\Models\DeviceModel;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/default-screens');
    Storage::disk('public')->makeDirectory('/images/generated');

    // Create fallback image files that the service expects
    Storage::disk('public')->put('/images/setup-logo.bmp', 'fake-bmp-content');
    Storage::disk('public')->put('/images/sleep.bmp', 'fake-bmp-content');
});

test('command transforms default images for all device models', function (): void {
    // Ensure we have device models
    $deviceModels = DeviceModel::all();
    expect($deviceModels)->not->toBeEmpty();

    // Run the command
    $this->artisan('images:generate-defaults')
        ->assertExitCode(0);

    // Check that the default-screens directory was created
    expect(Storage::disk('public')->exists('images/default-screens'))->toBeTrue();

    // Check that images were generated for each device model
    foreach ($deviceModels as $deviceModel) {
        $extension = $deviceModel->mime_type === 'image/bmp' ? 'bmp' : 'png';
        $filename = "{$deviceModel->width}_{$deviceModel->height}_{$deviceModel->bit_depth}_{$deviceModel->rotation}.{$extension}";

        $setupPath = "images/default-screens/setup-logo_{$filename}";
        $sleepPath = "images/default-screens/sleep_{$filename}";

        expect(Storage::disk('public')->exists($setupPath))->toBeTrue();
        expect(Storage::disk('public')->exists($sleepPath))->toBeTrue();
    }
});

test('getDeviceSpecificDefaultImage falls back to original images for device without model', function (): void {
    $device = new Device();
    $device->deviceModel = null;

    $setupImage = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');
    $sleepImage = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'sleep');

    expect($setupImage)->toBe('images/setup-logo.bmp');
    expect($sleepImage)->toBe('images/sleep.bmp');
});

test('generateDefaultScreenImage creates images from Blade templates', function (): void {
    $device = Device::factory()->create();

    $setupUuid = ImageGenerationService::generateDefaultScreenImage($device, 'setup-logo');
    $sleepUuid = ImageGenerationService::generateDefaultScreenImage($device, 'sleep');

    expect($setupUuid)->not->toBeEmpty();
    expect($sleepUuid)->not->toBeEmpty();
    expect($setupUuid)->not->toBe($sleepUuid);

    // Check that the generated images exist
    $setupPath = "images/generated/{$setupUuid}.png";
    $sleepPath = "images/generated/{$sleepUuid}.png";

    expect(Storage::disk('public')->exists($setupPath))->toBeTrue();
    expect(Storage::disk('public')->exists($sleepPath))->toBeTrue();
})->skipOnCI();

test('generateDefaultScreenImage throws exception for invalid image type', function (): void {
    $device = Device::factory()->create();

    expect(fn (): string => ImageGenerationService::generateDefaultScreenImage($device, 'invalid-type'))
        ->toThrow(InvalidArgumentException::class);
});

test('getDeviceSpecificDefaultImage returns null for invalid image type', function (): void {
    $device = new Device();
    $device->deviceModel = DeviceModel::first();

    $result = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'invalid-type');
    expect($result)->toBeNull();
});
