<?php

use App\Jobs\FetchProxyCloudResponses;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/test-image.bmp*' => Http::response([], 200),
        'https://trmnl.app/api/log' => Http::response([], 200),
        'https://example.com/api/log' => Http::response([], 200),
    ]);
    config(['services.trmnl.proxy_base_url' => 'https://example.com']);
});

function createTestDevice(array $attributes = []): Device
{
    return Device::factory()->create(array_merge([
        'proxy_cloud' => true,
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'last_rssi_level' => -70,
        'last_battery_voltage' => 3.7,
        'default_refresh_interval' => 300,
        'last_firmware_version' => '1.0.0',
    ], $attributes));
}

function assertDeviceHeaders(Device $device): void
{
    Http::assertSent(fn ($request): bool => $request->hasHeader('id', $device->mac_address) &&
        $request->hasHeader('access-token', $device->api_key) &&
        $request->hasHeader('width', 800) &&
        $request->hasHeader('height', 480) &&
        $request->hasHeader('rssi', $device->last_rssi_level) &&
        $request->hasHeader('battery_voltage', $device->last_battery_voltage) &&
        $request->hasHeader('refresh-rate', $device->default_refresh_interval) &&
        $request->hasHeader('fw-version', $device->last_firmware_version));
}

test('it fetches and processes proxy cloud responses for devices', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ]),
        'https://example.com/test-image.bmp' => Http::response('fake-image-content'),
    ]);

    (new FetchProxyCloudResponses)->handle();

    assertDeviceHeaders($device);
    $device->refresh();

    expect($device->current_screen_image)->toBe('test-image')
        ->and($device->proxy_cloud_response)->toBe([
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ]);

    Storage::disk('public')->assertExists('images/generated/test-image.bmp');
});

test('it handles log requests when present', function (): void {
    $device = createTestDevice([
        'last_log_request' => ['message' => 'test log'],
    ]);

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ]),
        'https://example.com/test-image.bmp' => Http::response('fake-image-content'),
        config('services.trmnl.proxy_base_url').'/api/log' => Http::response(null, 200),
    ]);

    (new FetchProxyCloudResponses)->handle();

    Http::assertSent(fn ($request): bool => $request->url() === config('services.trmnl.proxy_base_url').'/api/log' &&
        $request->hasHeader('id', $device->mac_address) &&
        $request->body() === json_encode(['message' => 'test log']));

    $device->refresh();
    expect($device->last_log_request)->toBeNull();
});

test('it handles API errors gracefully', function (): void {
    createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response(null, 500),
    ]);

    expect(fn () => (new FetchProxyCloudResponses)->handle())->not->toThrow(Exception::class);
});

test('it handles proxy cloud 500 errors with error message gracefully', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'status' => 500,
            'error' => 'Device not found',
            'reset_firmware' => true,
        ], 500),
    ]);

    expect(fn () => (new FetchProxyCloudResponses)->handle())->not->toThrow(Exception::class);

    $device->refresh();
    expect($device->proxy_cloud_response)->toBeNull();
});

test('it only processes proxy cloud enabled devices', function (): void {
    Http::fake();
    $enabledDevice = Device::factory()->create(['proxy_cloud' => true]);
    $disabledDevice = Device::factory()->create(['proxy_cloud' => false]);

    (new FetchProxyCloudResponses)->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('id', $enabledDevice->mac_address));
    Http::assertNotSent(fn ($request) => $request->hasHeader('id', $disabledDevice->mac_address));
});

test('it fetches and processes proxy cloud responses for devices with BMP images', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'https://example.com/test-image.bmp?response-content-type=image/bmp',
            'filename' => 'test-image',
        ]),
        'https://example.com/test-image.bmp?response-content-type=image/bmp' => Http::response('fake-image-content'),
    ]);

    (new FetchProxyCloudResponses)->handle();

    assertDeviceHeaders($device);
    $device->refresh();

    expect($device->current_screen_image)->toBe('test-image')
        ->and($device->proxy_cloud_response)->toBe([
            'image_url' => 'https://example.com/test-image.bmp?response-content-type=image/bmp',
            'filename' => 'test-image',
        ]);

    expect(Storage::disk('public')->exists('images/generated/test-image.bmp'))->toBeTrue();
    expect(Storage::disk('public')->exists('images/generated/test-image.png'))->toBeFalse();
});

test('it fetches and processes proxy cloud responses for devices with PNG images', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'https://example.com/test-image.png?response-content-type=image/png',
            'filename' => 'test-image',
        ]),
        'https://example.com/test-image.png?response-content-type=image/png' => Http::response('fake-image-content'),
    ]);

    (new FetchProxyCloudResponses)->handle();

    assertDeviceHeaders($device);
    $device->refresh();

    expect($device->current_screen_image)->toBe('test-image')
        ->and($device->proxy_cloud_response)->toBe([
            'image_url' => 'https://example.com/test-image.png?response-content-type=image/png',
            'filename' => 'test-image',
        ]);

    expect(Storage::disk('public')->exists('images/generated/test-image.png'))->toBeTrue();
    expect(Storage::disk('public')->exists('images/generated/test-image.bmp'))->toBeFalse();
});

test('it handles missing content type in image URL gracefully', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ]),
        'https://example.com/test-image.bmp' => Http::response('fake-image-content'),
    ]);

    (new FetchProxyCloudResponses)->handle();

    $device->refresh();

    expect($device->current_screen_image)->toBe('test-image')
        ->and($device->proxy_cloud_response)->toBe([
            'image_url' => 'https://example.com/test-image.bmp',
            'filename' => 'test-image',
        ]);

    expect(Storage::disk('public')->exists('images/generated/test-image.bmp'))->toBeTrue();
    expect(Storage::disk('public')->exists('images/generated/test-image.png'))->toBeFalse();
});

test('it handles null image URL gracefully', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => null,
            'filename' => 'test-image',
        ]),
    ]);

    expect(fn () => (new FetchProxyCloudResponses)->handle())->not->toThrow(TypeError::class);

    $device->refresh();
    expect($device->proxy_cloud_response)->toBe([
        'image_url' => null,
        'filename' => 'test-image',
    ]);

    expect($device->current_screen_image)->toBeNull();
    expect(Storage::disk('public')->exists('images/generated/test-image.bmp'))->toBeFalse();
    expect(Storage::disk('public')->exists('images/generated/test-image.png'))->toBeFalse();
});

test('it handles malformed image URL gracefully', function (): void {
    $device = createTestDevice();

    Http::fake([
        config('services.trmnl.proxy_base_url').'/api/display' => Http::response([
            'image_url' => 'not-a-valid-url://',
            'filename' => 'test-image',
        ]),
    ]);

    expect(fn () => (new FetchProxyCloudResponses)->handle())->not->toThrow(TypeError::class);

    $device->refresh();
    expect($device->proxy_cloud_response)->toBe([
        'image_url' => 'not-a-valid-url://',
        'filename' => 'test-image',
    ]);

    expect($device->current_screen_image)->toBeNull();
});
