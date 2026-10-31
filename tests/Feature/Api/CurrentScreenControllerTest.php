<?php

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->put('images/generated/source-screen.png', 'source');
    Storage::disk('public')->put('images/generated/offline-screen.png', 'offline');
});

afterEach(function (): void {
    EpaperPipeline::restore();
});

/**
 * @return Illuminate\Testing\TestResponse
 */
function fetchCurrentScreen(Device $device)
{
    return test()->withHeaders(['access-token' => $device->api_key])->get('/api/current_screen');
}

test('current screen does not render or advance the playlist when the plugin data is stale', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'render_markup_view' => 'trmnl',
        'data_payload_updated_at' => null,
        'current_image' => null,
    ]);
    $device = Device::factory()->create(['user_id' => $user->id, 'current_screen_image' => 'source-screen']);
    $playlist = Playlist::factory()->create(['device_id' => $device->id, 'is_active' => true]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'last_displayed_at' => null,
    ]);

    $response = fetchCurrentScreen($device);

    expect($response->json('image_url'))->toContain('images/generated/source-screen.png')
        ->and($device->fresh()->current_screen_image)->toBe('source-screen')
        ->and($plugin->fresh()->current_image)->toBeNull()
        ->and($item->fresh()->last_displayed_at)->toBeNull();
});

test('a mirror device reads the current screen of its source device', function (): void {
    $user = User::factory()->create();
    $source = Device::factory()->create(['user_id' => $user->id, 'current_screen_image' => 'source-screen']);
    $mirror = Device::factory()->create([
        'user_id' => $user->id,
        'mirror_device_id' => $source->id,
        'current_screen_image' => null,
    ]);

    $response = fetchCurrentScreen($mirror);

    expect($response->json('image_url'))->toContain('images/generated/source-screen.png');
});

test('a mirror device shows the plugin image of its paused source device', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);
    $source = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'source-screen',
        'offline_screen_image' => 'offline-screen',
        'pause_until' => now()->addHour(),
        'pause_plugin_id' => $plugin->id,
    ]);
    $mirror = Device::factory()->create(['user_id' => $user->id, 'mirror_device_id' => $source->id]);

    $response = fetchCurrentScreen($mirror);

    expect($response->json('image_url'))->toContain('images/generated/offline-screen.png')
        ->and($response->json('filename'))->toStartWith('screen-');
});

test('a sleeping device with a sleep plugin reports its offline image', function (): void {
    $this->travelTo('2026-01-01 20:00:00');
    $user = User::factory()->create(['timezone' => 'UTC']);
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'source-screen',
        'offline_screen_image' => 'offline-screen',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '19:00',
        'sleep_mode_to' => '23:00',
        'sleep_plugin_id' => $plugin->id,
    ]);

    $response = fetchCurrentScreen($device);

    expect($response->json('image_url'))->toContain('images/generated/offline-screen.png');
});

test('a paused device without a pause plugin reports the default sleep image', function (): void {
    Storage::disk('public')->put('images/sleep.bmp', 'sleep');
    $device = Device::factory()->create([
        'current_screen_image' => 'source-screen',
        'offline_screen_image' => 'offline-screen',
        'pause_until' => now()->addHour(),
    ]);

    $response = fetchCurrentScreen($device);

    expect($response->json('image_url'))->toContain('images/sleep.bmp');
});

test('a device whose pause has ended reports its current screen', function (): void {
    $user = User::factory()->create();
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'source-screen',
        'offline_screen_image' => 'offline-screen',
        'pause_until' => now()->subMinute(),
        'pause_plugin_id' => $plugin->id,
    ]);

    $response = fetchCurrentScreen($device);

    expect($response->json('image_url'))->toContain('images/generated/source-screen.png');
});
