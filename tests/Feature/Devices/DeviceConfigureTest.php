<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

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
        ->set('sleep_mode_from', null)
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

    expect(PlaylistItem::query()->find($second->id)?->order)->toBe(0);
    expect(PlaylistItem::query()->find($first->id)?->order)->toBe(1);
    expect(PlaylistItem::query()->find($third->id)?->order)->toBe(2);
});
