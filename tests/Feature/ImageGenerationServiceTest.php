<?php

declare(strict_types=1);

use App\Enums\ImageFormat;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
    EpaperPipeline::fake();
});

afterEach(function (): void {
    EpaperPipeline::restore();
});

it('plugins table has current_image_metadata column', function (): void {
    expect(Schema::hasColumn('plugins', 'current_image_metadata'))->toBeTrue();
});

it('generates image for device without device model', function (): void {
    // Create a device without a DeviceModel (legacy behavior)
    $device = Device::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => ImageFormat::PNG_8BIT_GRAYSCALE->value,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('generates image for device with device model', function (): void {
    // Create a DeviceModel
    $deviceModel = DeviceModel::factory()->create([
        'width' => 1024,
        'height' => 768,
        'colors' => 256,
        'bit_depth' => 8,
        'scale_factor' => 1.0,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'offset_x' => 0,
        'offset_y' => 0,
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('generates BMP with device model', function (): void {
    // Create a DeviceModel for BMP format
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'colors' => 2,
        'bit_depth' => 1,
        'scale_factor' => 1.0,
        'rotation' => 0,
        'mime_type' => 'image/bmp',
        'offset_x' => 0,
        'offset_y' => 0,
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert BMP file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.bmp");
});

it('applies scale factor from device model', function (): void {
    // Create a DeviceModel with scale factor
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'colors' => 256,
        'bit_depth' => 8,
        'scale_factor' => 2.0, // Scale up by 2x
        'rotation' => 0,
        'mime_type' => 'image/png',
        'offset_x' => 0,
        'offset_y' => 0,
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('applies rotation from device model', function (): void {
    // Create a DeviceModel with rotation
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'colors' => 256,
        'bit_depth' => 8,
        'scale_factor' => 1.0,
        'rotation' => 90, // Rotate 90 degrees
        'mime_type' => 'image/png',
        'offset_x' => 0,
        'offset_y' => 0,
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('applies offset from device model', function (): void {
    // Create a DeviceModel with offset
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'colors' => 256,
        'bit_depth' => 8,
        'scale_factor' => 1.0,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'offset_x' => 10, // Offset by 10 pixels
        'offset_y' => 20, // Offset by 20 pixels
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('falls back to device settings when no device model', function (): void {
    // Create a device with custom settings but no DeviceModel
    $device = Device::factory()->create([
        'width' => 1024,
        'height' => 768,
        'rotate' => 180,
        'image_format' => ImageFormat::PNG_8BIT_256C->value,
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('handles auto image format for legacy devices', function (): void {
    // Create a device with AUTO format (legacy behavior)
    $device = Device::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => ImageFormat::AUTO->value,
        'last_firmware_version' => '1.6.0', // Modern firmware
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert PNG file was created (modern firmware defaults to PNG)
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.png");
});

it('cleanupFolder removes unused images', function (): void {
    // Create active devices with images
    Device::factory()->create(['current_screen_image' => 'active-uuid-1']);
    Device::factory()->create(['current_screen_image' => 'active-uuid-2']);

    // Create some test files
    Storage::disk('public')->put('/images/generated/active-uuid-1.png', 'test');
    Storage::disk('public')->put('/images/generated/active-uuid-2.png', 'test');
    Storage::disk('public')->put('/images/generated/inactive-uuid.png', 'test');
    Storage::disk('public')->put('/images/generated/another-inactive.png', 'test');

    // Run cleanup
    ImageGenerationService::cleanupFolder();

    // Assert active files are preserved
    Storage::disk('public')->assertExists('/images/generated/active-uuid-1.png');
    Storage::disk('public')->assertExists('/images/generated/active-uuid-2.png');

    // Assert inactive files are removed
    Storage::disk('public')->assertMissing('/images/generated/inactive-uuid.png');
    Storage::disk('public')->assertMissing('/images/generated/another-inactive.png');
});

it('cleanupFolder preserves .gitignore', function (): void {
    // Create gitignore file
    Storage::disk('public')->put('/images/generated/.gitignore', '*');

    // Create some test files
    Storage::disk('public')->put('/images/generated/test.png', 'test');

    // Run cleanup
    ImageGenerationService::cleanupFolder();

    // Assert gitignore is preserved
    Storage::disk('public')->assertExists('/images/generated/.gitignore');
});

it('resetIfNotCacheable does not reset recipe cache based on other devices', function (): void {
    // Cache validity is now determined at use-time via current_image_metadata
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid', 'plugin_type' => 'recipe']);

    Device::factory()->create(['device_model_id' => DeviceModel::factory()->create()->id]);
    ImageGenerationService::resetIfNotCacheable($plugin);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('resetIfNotCacheable preserves image for standard devices', function (): void {
    // Create a plugin
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid']);

    // Create devices with standard dimensions (should not trigger cache reset)
    Device::factory()->count(3)->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
    ]);

    // Run reset check
    ImageGenerationService::resetIfNotCacheable($plugin);

    // Assert plugin image was preserved
    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('cache is reset when plugin markup changes', function (): void {
    // Create a plugin with cached image and metadata
    $plugin = App\Models\Plugin::factory()->create([
        'current_image' => 'cached-uuid',
        'current_image_metadata' => ['width' => 800, 'height' => 480, 'rotation' => 0, 'palette_id' => null, 'mime_type' => 'image/png'],
        'render_markup' => '<div>Original markup</div>',
    ]);

    $plugin->update(['render_markup' => '<div>Updated markup</div>']);

    $plugin->refresh();
    expect($plugin->current_image)->toBeNull();
    expect($plugin->current_image_metadata)->toBeNull();
});

it('buildImageMetadataFromDevice returns canonical metadata shape', function (): void {
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'palette_id' => null,
    ]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id]);

    $meta = ImageGenerationService::buildImageMetadataFromDevice($device);

    expect($meta)->toHaveKeys(['width', 'height', 'rotation', 'palette_id', 'mime_type']);
    expect($meta['width'])->toBe(800);
    expect($meta['height'])->toBe(480);
    expect($meta['rotation'])->toBe(0);
    expect($meta['mime_type'])->toBe('image/png');
});

it('buildImageMetadataFromDeviceModel returns canonical metadata shape', function (): void {
    $model = DeviceModel::factory()->create([
        'width' => 1024,
        'height' => 768,
        'rotation' => 90,
        'mime_type' => 'image/bmp',
        'palette_id' => null,
    ]);

    $meta = ImageGenerationService::buildImageMetadataFromDeviceModel($model);

    expect($meta)->toHaveKeys(['width', 'height', 'rotation', 'palette_id', 'mime_type']);
    expect($meta['width'])->toBe(1024);
    expect($meta['height'])->toBe(768);
    expect($meta['rotation'])->toBe(90);
    expect($meta['mime_type'])->toBe('image/bmp');
});

it('imageMetadataMatches returns false when stored is null or empty', function (): void {
    $device = Device::factory()->create(['width' => 800, 'height' => 480, 'rotate' => 0]);

    expect(ImageGenerationService::imageMetadataMatches(null, $device))->toBeFalse();
    expect(ImageGenerationService::imageMetadataMatches([], $device))->toBeFalse();
});

it('imageMetadataMatches returns true when metadata matches device', function (): void {
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
        'mime_type' => 'image/png',
        'palette_id' => null,
    ]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id]);
    $stored = ImageGenerationService::buildImageMetadataFromDevice($device);

    expect(ImageGenerationService::imageMetadataMatches($stored, $device))->toBeTrue();
});

it('imageMetadataMatches returns false when metadata differs', function (): void {
    $device = Device::factory()->create(['width' => 800, 'height' => 480, 'rotate' => 0]);
    $stored = ['width' => 800, 'height' => 480, 'rotation' => 0, 'palette_id' => null, 'mime_type' => 'image/png'];

    $device->update(['width' => 1024]);
    $device->refresh();

    expect(ImageGenerationService::imageMetadataMatches($stored, $device))->toBeFalse();
});

it('resetIfNotCacheable clears recipe cache when metadata does not match', function (): void {
    $plugin = App\Models\Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'current_image' => 'cached-uuid',
        'current_image_metadata' => ['width' => 800, 'height' => 480, 'rotation' => 0, 'palette_id' => null, 'mime_type' => 'image/png'],
    ]);
    $device = Device::factory()->create(['width' => 1024, 'height' => 768, 'rotate' => 0]);

    ImageGenerationService::resetIfNotCacheable($plugin, $device);

    $plugin->refresh();
    expect($plugin->current_image)->toBeNull();
    expect($plugin->current_image_metadata)->toBeNull();
});

it('resetIfNotCacheable preserves cache when metadata matches', function (): void {
    $deviceModel = DeviceModel::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
        'mime_type' => 'image/png',
    ]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id]);
    $meta = ImageGenerationService::buildImageMetadataFromDevice($device);
    $plugin = App\Models\Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'current_image' => 'cached-uuid',
        'current_image_metadata' => $meta,
    ]);

    ImageGenerationService::resetIfNotCacheable($plugin, $device);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('cached-uuid');
});

it('determines correct image format from device model', function (): void {
    // Test BMP format detection
    $bmpModel = DeviceModel::factory()->create([
        'mime_type' => 'image/bmp',
        'bit_depth' => 1,
        'colors' => 2,
    ]);

    $device = Device::factory()->create(['device_model_id' => $bmpModel->id]);
    $markup = '<div>Test</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.bmp");

    // Test PNG 8-bit grayscale format detection
    $pngGrayscaleModel = DeviceModel::factory()->create([
        'mime_type' => 'image/png',
        'bit_depth' => 8,
        'colors' => 2,
    ]);

    $device2 = Device::factory()->create(['device_model_id' => $pngGrayscaleModel->id]);
    $uuid2 = ImageGenerationService::generateImage($markup, $device2->id);

    $device2->refresh();
    expect($device2->current_screen_image)->toBe($uuid2);
    Storage::disk('public')->assertExists("/images/generated/{$uuid2}.png");

    // Test PNG 8-bit 256 color format detection
    $png256Model = DeviceModel::factory()->create([
        'mime_type' => 'image/png',
        'bit_depth' => 8,
        'colors' => 256,
    ]);

    $device3 = Device::factory()->create(['device_model_id' => $png256Model->id]);
    $uuid3 = ImageGenerationService::generateImage($markup, $device3->id);

    $device3->refresh();
    expect($device3->current_screen_image)->toBe($uuid3);
    Storage::disk('public')->assertExists("/images/generated/{$uuid3}.png");
});

it('generates BMP for legacy device with bmp3_1bit_srgb format', function (): void {
    // Create a device with BMP format but no DeviceModel (legacy behavior)
    $device = Device::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => ImageFormat::BMP3_1BIT_SRGB->value,
        'device_model_id' => null, // Explicitly no DeviceModel
    ]);

    $markup = '<div style="background: white; color: black; padding: 20px;">Test Content</div>';
    $uuid = ImageGenerationService::generateImage($markup, $device->id);

    // Assert the device was updated with a new image UUID
    $device->refresh();
    expect($device->current_screen_image)->toBe($uuid);

    // Assert BMP file was created
    Storage::disk('public')->assertExists("/images/generated/{$uuid}.bmp");

    // Verify the BMP file has content and isn't blank
    $imagePath = Storage::disk('public')->path("/images/generated/{$uuid}.bmp");
    $imageSize = filesize($imagePath);
    expect($imageSize)->toBeGreaterThan(100); // Should be at least 100 bytes for a BMP

    // Verify it's a valid BMP file
    $imageInfo = getimagesize($imagePath);
    expect($imageInfo[0])->toBe(800); // Width
    expect($imageInfo[1])->toBe(480); // Height
    expect($imageInfo[2])->toBe(IMAGETYPE_BMP); // BMP type
});
