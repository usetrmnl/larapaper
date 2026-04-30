<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('sortPlaylistItem reorders playlist items by zero-based position', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);

    $first = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 0]);
    $second = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 1]);
    $third = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 2]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('sortPlaylistItem', $second->id, 0);

    expect(PlaylistItem::query()->find($second->id)?->order)->toBe(0);
    expect(PlaylistItem::query()->find($first->id)?->order)->toBe(1);
    expect(PlaylistItem::query()->find($third->id)?->order)->toBe(2);
});

test('sortPlaylistItem does not reorder when user does not own the device', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);

    $first = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 0]);
    $target = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'order' => 1]);

    $this->actingAs($intruder);

    Livewire::test('playlists.index')->call('sortPlaylistItem', $target->id, 0);

    expect(PlaylistItem::query()->find($target->id)?->order)->toBe(1);
    expect(PlaylistItem::query()->find($first->id)?->order)->toBe(0);
});

test('clearPluginImageCache nulls current_image and metadata for the playlist item plugin', function (): void {
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

    Livewire::test('playlists.index')
        ->call('clearPluginImageCache', $item->id)
        ->assertDispatched('toast-show');

    $plugin->refresh();
    expect($plugin->current_image)->toBeNull();
    expect($plugin->current_image_metadata)->toBeNull();
});

test('clearPluginImageCache does nothing when plugin is not type recipe', function (): void {
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

    Livewire::test('playlists.index')
        ->call('clearPluginImageCache', $item->id)
        ->assertNotDispatched('toast-show');

    expect($plugin->fresh()->current_image)->toBe('webhook-uuid');
    expect($plugin->fresh()->current_image_metadata)->toBeArray();
});

test('clearPluginImageCache does nothing for mashup playlist items', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $p1 = Plugin::factory()->create([
        'user_id' => $user->id,
        'current_image' => 'uuid-one',
        'current_image_metadata' => ['width' => 400],
    ]);
    $p2 = Plugin::factory()->create([
        'user_id' => $user->id,
        'current_image' => 'uuid-two',
        'current_image_metadata' => ['width' => 400],
    ]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $p1->id,
        'mashup' => [
            'mashup_layout' => '1Lx1R',
            'mashup_name' => 'Mash',
            'plugin_ids' => [$p1->id, $p2->id],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('clearPluginImageCache', $item->id);

    expect($p1->fresh()->current_image)->toBe('uuid-one');
    expect($p1->fresh()->current_image_metadata)->toBeArray();
    expect($p2->fresh()->current_image)->toBe('uuid-two');
    expect($p2->fresh()->current_image_metadata)->toBeArray();
});

test('clearPluginImageCache aborts when user does not own the device', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create([
        'user_id' => $owner->id,
        'plugin_type' => 'recipe',
        'current_image' => 'keep-me',
        'current_image_metadata' => ['width' => 100],
    ]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);

    $this->actingAs($intruder);

    Livewire::test('playlists.index')
        ->call('clearPluginImageCache', $item->id)
        ->assertForbidden();

    expect($plugin->fresh()->current_image)->toBe('keep-me');
});
