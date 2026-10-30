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

    // Markup plugins render via generateImageFromModel — the path that must not write current_screen_image.
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

    // TRMNL_SKIP_DISPLAY makes the plugin render nothing, so the offline screen must fall back.
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

test('sleeping device falls back to the default sleep face when the sleep plugin throws while rendering', function (): void {
    $user = User::factory()->create();

    // Blade markup reaching into $data[0] throws because the payload is not a list.
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'name' => 'Broken Sleep Plugin',
        'data_strategy' => 'polling',
        'polling_url' => null,
        'data_stale_minutes' => 60,
        'markup_language' => 'blade',
        'render_markup' => '<div>{{ $data[0]["invalid"] }}</div>',
        'data_payload' => ['error' => 'Failed to fetch data'],
        'data_payload_updated_at' => now(),
        'current_image' => null,
    ]);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'mac_address' => '00:11:22:33:44:08',
        'api_key' => 'sleep-throw-key',
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

    // Paused with a sleep plugin but no pause plugin: pause is evaluated first and must not borrow the sleep plugin.
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

/**
 * Create a device asleep at the current test time, showing a recipe plugin that has a
 * cached image. The cached bytes differ from the fake pipeline's output, so a render
 * can't be deduplicated back to the cached UUID: a new UUID means a render happened.
 *
 * @param  array<string, mixed>  $pluginAttributes
 * @param  array<string, mixed>|null  $imageMetadata  Defaults to metadata matching the device
 * @return array{0: Device, 1: Plugin}
 */
function sleepingDeviceWithCachedPlugin(array $pluginAttributes = [], ?array $imageMetadata = null): array
{
    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));
    Storage::disk('public')->put('images/generated/cached-sleep-image.png', 'cached-bytes');

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'polling_url' => null,
        'data_stale_minutes' => 60,
        'data_strategy' => 'polling',
        'render_markup_view' => 'trmnl',
        'data_payload_updated_at' => now(),
        ...$pluginAttributes,
    ]);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'awake-screen',
        'sleep_plugin_id' => $plugin->id,
    ]);

    $plugin->update([
        'current_image' => 'cached-sleep-image',
        'current_image_metadata' => $imageMetadata ?? ImageGenerationService::buildImageMetadataFromDevice($device),
    ]);

    return [$device, $plugin];
}

test('sleeping device serves the cached plugin image when the data is fresh', function (): void {
    [$device, $plugin] = sleepingDeviceWithCachedPlugin();

    $response = fetchDisplay($device);

    expect($response->json('image_url'))->toContain('images/generated/cached-sleep-image.png')
        ->and($device->fresh()->offline_screen_image)->toBe('cached-sleep-image')
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen')
        ->and($plugin->fresh()->current_image)->toBe('cached-sleep-image');
});

test('sleeping device caches its first render on the plugin and reuses it on the next poll', function (): void {
    Carbon::setTestNow(Carbon::parse('2000-01-01 20:00:00'));

    $user = User::factory()->create();

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'polling_url' => null,
        'data_stale_minutes' => 60,
        'data_strategy' => 'polling',
        'render_markup_view' => 'trmnl',
        'data_payload_updated_at' => null,
    ]);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'current_screen_image' => 'awake-screen',
        'sleep_plugin_id' => $plugin->id,
    ]);

    fetchDisplay($device)->assertOk();

    $firstImage = $device->fresh()->offline_screen_image;

    expect($firstImage)->not->toBeNull()
        ->and($plugin->fresh()->current_image)->toBe($firstImage)
        ->and($plugin->fresh()->current_image_metadata)->not->toBeNull()
        ->and($plugin->fresh()->data_payload_updated_at->equalTo(now()))->toBeTrue()
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen');

    // Changed bytes stop a second render from deduplicating back to the same UUID.
    Storage::disk('public')->put("images/generated/{$firstImage}.png", 'cached-bytes');

    $secondPoll = fetchDisplay($device);

    expect($secondPoll->json('image_url'))->toContain("images/generated/{$firstImage}.png")
        ->and($plugin->fresh()->current_image)->toBe($firstImage);
});

test('sleeping device re-renders the plugin when its data is stale', function (): void {
    [$device, $plugin] = sleepingDeviceWithCachedPlugin([
        'data_payload_updated_at' => Carbon::parse('2000-01-01 18:00:00'),
    ]);

    $response = fetchDisplay($device);

    $renderedImage = $device->fresh()->offline_screen_image;

    expect($renderedImage)->not->toBe('cached-sleep-image')
        ->and($response->json('image_url'))->toContain("images/generated/{$renderedImage}.png")
        ->and($plugin->fresh()->current_image)->toBe($renderedImage)
        ->and($plugin->fresh()->data_payload_updated_at->equalTo(now()))->toBeTrue()
        ->and($device->fresh()->current_screen_image)->toBe('awake-screen');
    Storage::disk('public')->assertMissing('images/generated/cached-sleep-image.png');
});

test('sleeping device re-renders the plugin when the cached image was made for another screen size', function (): void {
    [$device, $plugin] = sleepingDeviceWithCachedPlugin(imageMetadata: [
        'width' => 1,
        'height' => 1,
        'rotation' => 0,
        'palette_id' => null,
        'mime_type' => 'image/png',
    ]);

    $response = fetchDisplay($device);

    $renderedImage = $device->fresh()->offline_screen_image;

    expect($renderedImage)->not->toBe('cached-sleep-image')
        ->and($response->json('image_url'))->toContain("images/generated/{$renderedImage}.png")
        ->and($plugin->fresh()->current_image)->toBe($renderedImage);
});

test('sleeping device clears the cached image and shows the sleep face when stale markup opts out of display', function (): void {
    [$device, $plugin] = sleepingDeviceWithCachedPlugin([
        'render_markup_view' => null,
        'markup_language' => 'blade',
        'render_markup' => '<script>window.TRMNL_SKIP_DISPLAY = true;</script>',
        'data_payload_updated_at' => Carbon::parse('2000-01-01 18:00:00'),
    ]);

    $response = fetchDisplay($device);

    expect($response->json('image_url'))->not->toContain('cached-sleep-image')
        ->and($plugin->fresh()->current_image)->toBeNull()
        ->and($plugin->fresh()->current_image_metadata)->toBeNull()
        ->and($device->fresh()->offline_screen_image)->toBeNull();
});
