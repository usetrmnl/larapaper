<?php

namespace Tests\Feature;

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\Firmware;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('configure view displays last_refreshed_at timestamp', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'last_refreshed_at' => now()->subMinutes(5),
    ]);

    $response = actingAs($user)
        ->get(route('devices.configure', $device));

    $response->assertOk()
        ->assertSee('5 minutes ago');
});

test('configure edit modal shows mirror checkbox and allows unchecking mirror', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $deviceAttributes = [
        'user_id' => $user->id,
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => 'png',
        'maximum_compatibility' => false,
    ];
    $sourceDevice = Device::factory()->create($deviceAttributes);
    $mirrorDevice = Device::factory()->create([
        ...$deviceAttributes,
        'mirror_device_id' => $sourceDevice->id,
    ]);

    $response = $this->get(route('devices.configure', $mirrorDevice));
    $response->assertOk()
        ->assertSee('Mirrors Device')
        ->assertSee('Select Device to Mirror');

    Livewire::test('devices.configure', ['device' => $mirrorDevice])
        ->set('is_mirror', false)
        ->call('updateDevice')
        ->assertHasNoErrors();

    $mirrorDevice->refresh();
    expect($mirrorDevice->mirror_device_id)->toBeNull();
});

test('configure update requires sleep mode times when sleep mode is enabled', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => 'png',
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '22:00',
        'sleep_mode_to' => '06:00',
    ]);

    Livewire::test('devices.configure', ['device' => $device])
        ->set('sleep_mode_enabled', true)
        ->set('sleep_mode_from')
        ->set('sleep_mode_to', '06:00')
        ->call('updateDevice')
        ->assertHasErrors(['sleep_mode_from' => ['required_if']]);
});

test('enabling sleep mode applies default times when none are set', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'width' => 800,
        'height' => 480,
        'rotate' => 0,
        'image_format' => 'png',
        'sleep_mode_enabled' => false,
        'sleep_mode_from' => null,
        'sleep_mode_to' => null,
    ]);

    Livewire::test('devices.configure', ['device' => $device])
        ->set('sleep_mode_enabled', true)
        ->assertSet('sleep_mode_from', '22:00')
        ->assertSet('sleep_mode_to', '06:00');
});

test('sortPlaylistItem reorders playlist items by zero-based position', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);

    $first = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 0]);
    $second = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 1]);
    $third = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 2]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->call('sortPlaylistItem', $second->id, 0);

    expect(PlaylistItem::query()->find($second->id)?->order)->toBe(0)
        ->and(PlaylistItem::query()->find($first->id)?->order)->toBe(1)
        ->and(PlaylistItem::query()->find($third->id)?->order)->toBe(2);
});

test('devices configure clearPluginImageCache clears recipe plugin cache and shows toast', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'current_image' => 'cached-uuid',
        'current_image_metadata' => ['width' => 800, 'height' => 480],
    ]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->call('clearPluginImageCache', $item->id)
        ->assertDispatched('toast-show');

    $plugin->refresh();
    expect($plugin->current_image)->toBeNull()
        ->and($plugin->current_image_metadata)->toBeNull();
});

test('devices configure clearPluginImageCache does nothing when plugin is not type recipe', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
        'current_image' => 'webhook-uuid',
        'current_image_metadata' => ['width' => 800, 'height' => 480],
    ]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->call('clearPluginImageCache', $item->id)
        ->assertNotDispatched('toast-show');

    expect($plugin->fresh()->current_image)->toBe('webhook-uuid');
});

test('check firmware updates polls for new firmware and refreshes list', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    Http::preventStrayRequests();
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '2.0.0',
            'url' => 'https://example.com/firmware.bin',
        ], 200),
    ]);

    Firmware::factory()->trmnl()->latest()->create([
        'version_tag' => '1.0.0',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('devices.configure', ['device' => $device])
        ->set('selected_firmware_model', FirmwareModel::Trmnl->value)
        ->call('checkFirmwareUpdates');

    $latestFirmware = Firmware::query()
        ->where('version_tag', '2.0.0')
        ->forModel(FirmwareModel::Trmnl)
        ->first();

    expect($latestFirmware)->not->toBeNull()
        ->and($latestFirmware->latest)->toBeTrue()
        ->and(Firmware::where('version_tag', '1.0.0')->first()->latest)->toBeFalse();

    $component->assertSet('selected_firmware_id', $latestFirmware->id);
});

test('configure shows empty playlist next step', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    Playlist::factory()->create(['device_id' => $device->id]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->assertSee('This playlist is empty')
        ->assertSee(route('plugins.index'), false);
});

test('configure playlist item name links to the recipe', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'name' => 'Weather Glance',
    ]);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->assertSee('Weather Glance')
        ->assertSee(route('plugins.recipe', $plugin), false);
});

test('configure playlist item name links to the native plugin instance', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->imageWebhook()->create([
        'user_id' => $user->id,
        'name' => 'Camera Feed',
    ]);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);

    $this->actingAs($user);

    Livewire::test('devices.configure', ['device' => $device])
        ->assertSee('Camera Feed')
        ->assertSee(route('plugins.type-instance', ['type' => 'image_webhook', 'plugin' => $plugin]), false);
});
