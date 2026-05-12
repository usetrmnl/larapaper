<?php

declare(strict_types=1);

use App\Enums\ImageFormat;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    EpaperPipeline::fake();
});

it('get_image_settings returns device model settings when available', function (): void {
    // Create a DeviceModel
    $deviceModel = DeviceModel::factory()->create([
        'width' => 1024,
        'height' => 768,
        'colors' => 256,
        'bit_depth' => 8,
        'scale_factor' => 1.5,
        'rotation' => 90,
        'mime_type' => 'image/png',
        'offset_x' => 10,
        'offset_y' => 20,
    ]);

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    // Use reflection to access private method
    $reflection = new ReflectionClass(ImageGenerationService::class);
    $method = $reflection->getMethod('getImageSettings');

    $settings = $method->invoke(null, $device);

    // Assert DeviceModel settings are used
    expect($settings['width'])->toBe(1024);
    expect($settings['height'])->toBe(768);
    expect($settings['colors'])->toBe(256);
    expect($settings['bit_depth'])->toBe(8);
    expect($settings['scale_factor'])->toBe(1.5);
    expect($settings['rotation'])->toBe(90);
    expect($settings['mime_type'])->toBe('image/png');
    expect($settings['offset_x'])->toBe(10);
    expect($settings['offset_y'])->toBe(20);
    expect($settings['use_model_settings'])->toBe(true);
});

it('get_image_settings falls back to device settings when no device model', function (): void {
    // Create a device without DeviceModel
    $device = Device::factory()->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 180,
        'image_format' => ImageFormat::PNG_8BIT_GRAYSCALE->value,
    ]);

    // Use reflection to access private method
    $reflection = new ReflectionClass(ImageGenerationService::class);
    $method = $reflection->getMethod('getImageSettings');

    $settings = $method->invoke(null, $device);

    // Assert device settings are used
    expect($settings['width'])->toBe(800);
    expect($settings['height'])->toBe(480);
    expect($settings['rotation'])->toBe(180);
    expect($settings['image_format'])->toBe(ImageFormat::PNG_8BIT_GRAYSCALE->value);
    expect($settings['use_model_settings'])->toBe(false);
});

it('get_image_settings uses defaults for missing device properties', function (): void {
    // Create a device without DeviceModel and missing properties
    $device = Device::factory()->create([
        'width' => null,
        'height' => null,
        'rotate' => null,
        // image_format has a default value of 'auto', so we can't set it to null
    ]);

    // Use reflection to access private method
    $reflection = new ReflectionClass(ImageGenerationService::class);
    $method = $reflection->getMethod('getImageSettings');

    $settings = $method->invoke(null, $device);

    // Assert default values are used
    expect($settings['width'])->toBe(800);
    expect($settings['height'])->toBe(480);
    expect($settings['rotation'])->toBe(0);
    expect($settings['colors'])->toBe(2);
    expect($settings['bit_depth'])->toBe(1);
    expect($settings['scale_factor'])->toBe(1.0);
    expect($settings['mime_type'])->toBe('image/png');
    expect($settings['offset_x'])->toBe(0);
    expect($settings['offset_y'])->toBe(0);
    // image_format defaults to 'auto' when not set
    expect($settings['image_format'])->toBe('auto');
});

it('determine_image_format_from_model returns correct formats', function (): void {
    // Use reflection to access private method
    $reflection = new ReflectionClass(ImageGenerationService::class);
    $method = $reflection->getMethod('determineImageFormatFromModel');

    // Test BMP format
    $bmpModel = DeviceModel::factory()->create([
        'mime_type' => 'image/bmp',
        'bit_depth' => 1,
        'colors' => 2,
    ]);
    $format = $method->invoke(null, $bmpModel);
    expect($format)->toBe(ImageFormat::BMP3_1BIT_SRGB->value);

    // Test PNG 8-bit grayscale format
    $pngGrayscaleModel = DeviceModel::factory()->create([
        'mime_type' => 'image/png',
        'bit_depth' => 8,
        'colors' => 2,
    ]);
    $format = $method->invoke(null, $pngGrayscaleModel);
    expect($format)->toBe(ImageFormat::PNG_8BIT_GRAYSCALE->value);

    // Test PNG 8-bit 256 color format
    $png256Model = DeviceModel::factory()->create([
        'mime_type' => 'image/png',
        'bit_depth' => 8,
        'colors' => 256,
    ]);
    $format = $method->invoke(null, $png256Model);
    expect($format)->toBe(ImageFormat::PNG_8BIT_256C->value);

    // Test PNG 2-bit 4 color format
    $png4ColorModel = DeviceModel::factory()->create([
        'mime_type' => 'image/png',
        'bit_depth' => 2,
        'colors' => 4,
    ]);
    $format = $method->invoke(null, $png4ColorModel);
    expect($format)->toBe(ImageFormat::PNG_2BIT_4C->value);

    // Test unknown format returns AUTO
    $unknownModel = DeviceModel::factory()->create([
        'mime_type' => 'image/jpeg',
        'bit_depth' => 16,
        'colors' => 65536,
    ]);
    $format = $method->invoke(null, $unknownModel);
    expect($format)->toBe(ImageFormat::AUTO->value);
});

it('cleanup_folder identifies active images correctly', function (): void {
    // Create devices with images
    $device1 = Device::factory()->create(['current_screen_image' => 'active-uuid-1']);
    $device2 = Device::factory()->create(['current_screen_image' => 'active-uuid-2']);
    $device3 = Device::factory()->create(['current_screen_image' => null]);

    // Create a plugin with image
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'plugin-uuid']);

    // For unit testing, we could test the logic that determines active UUIDs
    $activeDeviceImageUuids = Device::pluck('current_screen_image')->filter()->toArray();
    $activePluginImageUuids = App\Models\Plugin::pluck('current_image')->filter()->toArray();
    $activeImageUuids = array_merge($activeDeviceImageUuids, $activePluginImageUuids);

    expect($activeImageUuids)->toContain('active-uuid-1');
    expect($activeImageUuids)->toContain('active-uuid-2');
    expect($activeImageUuids)->toContain('plugin-uuid');
    expect($activeImageUuids)->not->toContain(null);
});

it('reset_if_not_cacheable does not reset recipe cache when other devices exist', function (): void {
    // Cache validity is now determined at use-time via metadata
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid', 'plugin_type' => 'recipe']);
    Device::factory()->create(['device_model_id' => DeviceModel::factory()->create()->id]);

    ImageGenerationService::resetIfNotCacheable($plugin);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('reset_if_not_cacheable preserves cache for standard devices', function (): void {
    // Create a plugin
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid']);

    // Create devices with standard dimensions
    Device::factory()->count(3)->create([
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
    ]);

    // Test that the method preserves cache for standard devices
    ImageGenerationService::resetIfNotCacheable($plugin);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('reset_if_not_cacheable preserves cache for og_png and og_plus device models', function (): void {
    // Create a plugin
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid']);

    // Create og_png device model
    $ogPngModel = DeviceModel::factory()->create([
        'name' => 'test_og_png',
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
    ]);

    // Create og_plus device model
    $ogPlusModel = DeviceModel::factory()->create([
        'name' => 'test_og_plus',
        'width' => 800,
        'height' => 480,
        'rotation' => 0,
    ]);

    // Create devices with og_png and og_plus device models
    Device::factory()->create(['device_model_id' => $ogPngModel->id]);
    Device::factory()->create(['device_model_id' => $ogPlusModel->id]);

    // Test that the method preserves cache for standard device models
    ImageGenerationService::resetIfNotCacheable($plugin);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('reset_if_not_cacheable does not reset cache for non-standard device models', function (): void {
    // Cache is now validated at use-time via metadata comparison
    $plugin = App\Models\Plugin::factory()->create(['current_image' => 'test-uuid', 'plugin_type' => 'recipe']);
    $kindleModel = DeviceModel::factory()->create([
        'name' => 'test_amazon_kindle_2024',
        'width' => 1400,
        'height' => 840,
        'rotation' => 90,
    ]);
    Device::factory()->create(['device_model_id' => $kindleModel->id]);

    ImageGenerationService::resetIfNotCacheable($plugin);

    $plugin->refresh();
    expect($plugin->current_image)->toBe('test-uuid');
});

it('reset_if_not_cacheable handles null plugin', function (): void {
    // Test that the method handles null plugin gracefully
    expect(fn () => ImageGenerationService::resetIfNotCacheable(null))->not->toThrow(Exception::class);
});

it('image_format enum includes new 2bit 4c format', function (): void {
    // Test that the new format is properly defined in the enum
    expect(ImageFormat::PNG_2BIT_4C->value)->toBe('png_2bit_4c');
    expect(ImageFormat::PNG_2BIT_4C->label())->toBe('PNG 2-bit Grayscale 4c');
});

it('device model relationship works correctly', function (): void {
    // Create a DeviceModel
    $deviceModel = DeviceModel::factory()->create();

    // Create a device with the DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => $deviceModel->id,
    ]);

    // Test the relationship
    expect($device->deviceModel)->toBeInstanceOf(DeviceModel::class);
    expect($device->deviceModel->id)->toBe($deviceModel->id);
});

it('device without device model returns null relationship', function (): void {
    // Create a device without DeviceModel
    $device = Device::factory()->create([
        'device_model_id' => null,
    ]);

    // Test the relationship returns null
    expect($device->deviceModel)->toBeNull();
});
