<?php

use App\Models\Device;
use App\Models\Plugin;
use App\Models\User;
use App\Services\DeviceScreenFilename;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

afterEach(function (): void {
    EpaperPipeline::restore();
    Carbon::setTestNow();
});

/**
 * Store a device-ready image on disk and return an image_webhook plugin whose
 * cached `current_image` points at it. Image Webhook plugins emit ProcessedImage
 * output, so the display cycle serves the cached bytes as-is without re-rendering
 * per device — the case where the offline screen must not disturb `current_screen_image`.
 */
function offlinePluginWithImage(User $user, string $imageUuid): Plugin
{
    Storage::disk('public')->put("images/generated/{$imageUuid}.png", 'offline-screen-bytes');

    return Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
        'current_image' => $imageUuid,
        'data_payload_updated_at' => now(),
    ]);
}

/**
 * @return Illuminate\Testing\TestResponse
 */
function fetchDisplay(Device $device)
{
    return test()->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display');
}

test('sleeping device with a sleep plugin serves the rendered plugin image without touching the current screen', function (): void {
    $user = User::factory()->create();
    $plugin = offlinePluginWithImage($user, 'sleep-plugin-image');

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:01',
        'api_key' => 'sleep-plugin-key',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'awake-screen',
        'sleep_plugin_id' => $plugin->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));

    $response = fetchDisplay($device);
    $response->assertOk();

    $json = $response->json();

    expect($device->fresh()->offline_screen_image)->toBe('sleep-plugin-image')
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($json['image_url'])->toContain('images/generated/sleep-plugin-image.png')
        ->and($json['filename'])->toBe(
            app(DeviceScreenFilename::class)->make('images/generated/sleep-plugin-image.png', 'sleep', DeviceScreenFilename::PREFIX_SYSTEM)
        );
});

test('sleeping device with a markup sleep plugin renders a fresh image without touching the current screen', function (): void {
    $user = User::factory()->create();

    // A recipe (markup) plugin renders through generateImageFromModel, unlike an
    // image_webhook. This is the path that must NOT write current_screen_image.
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Date Plugin',
        'polling_url' => null,
        'data_stale_minutes' => 60,
        'data_strategy' => 'polling',
        'polling_verb' => 'get',
        'render_markup_view' => 'trmnl',
        'is_native' => false,
        'data_payload_updated_at' => now(),
        'current_image' => null,
    ]);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:07',
        'api_key' => 'sleep-markup-key',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'awake-screen',
        'sleep_plugin_id' => $plugin->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));

    $response = fetchDisplay($device);
    $response->assertOk();

    $json = $response->json();
    $offlineImage = $device->fresh()->offline_screen_image;
    $servedPath = "images/generated/{$offlineImage}.png";

    expect($offlineImage)->not->toBeNull()
        ->and($offlineImage)->not->toBe('awake-screen')
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($json['image_url'])->toContain($servedPath)
        ->and($json['filename'])->toBe(
            app(DeviceScreenFilename::class)->make($servedPath, 'sleep', DeviceScreenFilename::PREFIX_SYSTEM)
        );
    Storage::disk('public')->assertExists($servedPath);
});

test('paused device with a pause plugin serves the rendered plugin image without touching the current screen', function (): void {
    $user = User::factory()->create();
    $plugin = offlinePluginWithImage($user, 'pause-plugin-image');

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:02',
        'api_key' => 'pause-plugin-key',
        'current_screen_image' => 'awake-screen',
        'pause_until' => now()->addMinutes(60),
        'pause_plugin_id' => $plugin->id,
    ]);

    $response = fetchDisplay($device);
    $response->assertOk();

    $json = $response->json();

    expect($device->fresh()->offline_screen_image)->toBe('pause-plugin-image')
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($json['image_url'])->toContain('images/generated/pause-plugin-image.png')
        ->and($json['filename'])->toBe(
            app(DeviceScreenFilename::class)->make('images/generated/pause-plugin-image.png', 'sleep', DeviceScreenFilename::PREFIX_SYSTEM)
        );
});

test('sleeping device falls back to the default sleep face when the sleep plugin opts out of display', function (): void {
    $user = User::factory()->create();

    // A plugin whose payload sets TRMNL_SKIP_DISPLAY renders nothing, so the
    // offline screen must fall back rather than throw or leave the device awake.
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'data_strategy' => 'polling',
        'polling_url' => null,
        'data_stale_minutes' => 5,
        'data_payload' => ['TRMNL_SKIP_DISPLAY' => true],
        'data_payload_updated_at' => now(),
        'current_image' => null,
    ]);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:03',
        'api_key' => 'sleep-skip-key',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'awake-screen',
        'sleep_plugin_id' => $plugin->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));

    $response = fetchDisplay($device);
    $response->assertOk();

    $json = $response->json();
    $servedPath = 'images/generated/'.basename((string) parse_url((string) $json['image_url'], PHP_URL_PATH));
    $servedImage = pathinfo($servedPath, PATHINFO_FILENAME);

    expect($json['filename'])->toBe(
        app(DeviceScreenFilename::class)->make($servedPath, 'sleep', DeviceScreenFilename::PREFIX_SYSTEM)
    )
        ->and($servedImage)->not->toBe('awake-screen')
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($device->fresh()->offline_screen_image)->toBeNull();
});

test('paused device does not borrow the sleep plugin and shows the plain sleep face', function (): void {
    $user = User::factory()->create();
    $sleepPlugin = offlinePluginWithImage($user, 'sleep-only-image');

    // Paused, with a sleep plugin configured but no pause plugin. Pause is
    // evaluated before sleep and must not reach for the sleep plugin.
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:04',
        'api_key' => 'pause-no-plugin-key',
        'current_screen_image' => 'awake-screen',
        'pause_until' => now()->addMinutes(60),
        'pause_plugin_id' => null,
        'sleep_plugin_id' => $sleepPlugin->id,
    ]);

    $response = fetchDisplay($device);
    $response->assertOk();

    $json = $response->json();

    expect($json['image_url'])->not->toContain('sleep-only-image')
        ->and($device->fresh()->offline_screen_image)->toBeNull()
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($json['filename'])->toMatch('/^'.preg_quote(DeviceScreenFilename::PREFIX_SYSTEM, '/').'[a-f0-9]{6}-\d{10}$/');
});

test('two sleeping devices sharing one sleep plugin each record their own offline image that survives cleanup', function (): void {
    $user = User::factory()->create();
    $plugin = offlinePluginWithImage($user, 'shared-sleep-image');

    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));

    $deviceOne = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:05',
        'api_key' => 'shared-key-1',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'device-one-awake',
        'sleep_plugin_id' => $plugin->id,
    ]);

    $deviceTwo = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:06',
        'api_key' => 'shared-key-2',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'device-two-awake',
        'sleep_plugin_id' => $plugin->id,
    ]);

    fetchDisplay($deviceOne)->assertOk();
    fetchDisplay($deviceTwo)->assertOk();

    expect($deviceOne->fresh()->offline_screen_image)->toBe('shared-sleep-image')
        ->and($deviceTwo->fresh()->offline_screen_image)->toBe('shared-sleep-image')
        ->and($deviceOne->fresh()->current_screen_image)->toBe('device-one-awake')
        ->and($deviceTwo->fresh()->current_screen_image)->toBe('device-two-awake');

    ImageGenerationService::cleanupFolder();

    Storage::disk('public')->assertExists('images/generated/shared-sleep-image.png');
});
