<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

test('playlists index adds a recipe item after the last item', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $existing = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Already There']);
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Weather Glance']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $existing->id,
        'order' => 5,
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->assertSee('Add item')
        ->call('prepareAddPlaylistItem', $playlist->id)
        ->set('mashup_layout', 'full')
        ->set('mashup_plugins', [(string) $plugin->id])
        ->call('savePlaylistItem')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $item = $playlist->items()->where('plugin_id', $plugin->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->mashup)->toBeNull()
        ->and($item->order)->toBe(6)
        ->and($item->is_active)->toBeTrue();
});

test('playlists index adds a mashup and ignores plugin ids beyond the layout', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $primary = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Left Glance']);
    $secondary = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Right Glance']);
    $unused = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Unused Glance']);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareAddPlaylistItem', $playlist->id)
        ->set('mashup_layout', '1Lx1R')
        ->set('mashup_plugins', [(string) $primary->id, (string) $secondary->id, (string) $unused->id])
        ->call('savePlaylistItem')
        ->assertHasNoErrors();

    $item = $playlist->fresh()->items->first();

    expect($item->plugin_id)->toBe($primary->id)
        ->and($item->mashup['mashup_layout'])->toBe('1Lx1R')
        ->and($item->mashup['mashup_name'])->toBe('Left Glance Mashup')
        ->and($item->mashup['plugin_ids'])->toBe([$primary->id, $secondary->id])
        ->and($item->order)->toBe(1);
});

test('playlists index updates mashup slots and keeps order', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $primary = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Left Glance']);
    $secondary = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Right Glance']);
    $replacement = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'New Right']);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $primary->id,
        'order' => 4,
        'is_active' => false,
        'mashup' => [
            'mashup_layout' => '1Lx1R',
            'mashup_name' => 'Old Mashup',
            'plugin_ids' => [$primary->id, $secondary->id],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->assertSee('Edit layout')
        ->call('prepareEditPlaylistItem', $item->id)
        ->set('mashup_plugins', [(string) $primary->id, (string) $replacement->id])
        ->call('savePlaylistItem')
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->order)->toBe(4)
        ->and($item->is_active)->toBeFalse()
        ->and($item->plugin_id)->toBe($primary->id)
        ->and($item->mashup['plugin_ids'])->toBe([$primary->id, $replacement->id])
        ->and($item->mashup['mashup_name'])->toBe('Left Glance Mashup')
        ->and($item->mashup['mashup_layout'])->toBe('1Lx1R');
});

test('playlists index converts a mashup item to a single plugin', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $primary = Plugin::factory()->create(['user_id' => $user->id]);
    $secondary = Plugin::factory()->create(['user_id' => $user->id]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $primary->id,
        'order' => 2,
        'is_active' => false,
        'mashup' => [
            'mashup_layout' => '1Lx1R',
            'mashup_name' => 'Split',
            'plugin_ids' => [$primary->id, $secondary->id],
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareEditPlaylistItem', $item->id)
        ->set('mashup_layout', 'full')
        ->set('mashup_plugins', [(string) $secondary->id])
        ->call('savePlaylistItem')
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->plugin_id)->toBe($secondary->id)
        ->and($item->mashup)->toBeNull()
        ->and($item->order)->toBe(2)
        ->and($item->is_active)->toBeFalse();
});

test('playlists index converts a recipe item into a mashup', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $primary = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Main Glance']);
    $secondary = Plugin::factory()->create(['user_id' => $user->id]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $primary->id,
        'order' => 3,
        'is_active' => true,
        'mashup' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareEditPlaylistItem', $item->id)
        ->set('mashup_layout', '1Tx1B')
        ->set('mashup_plugins', [(string) $primary->id, (string) $secondary->id])
        ->call('savePlaylistItem')
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->order)->toBe(3)
        ->and($item->is_active)->toBeTrue()
        ->and($item->mashup['mashup_layout'])->toBe('1Tx1B')
        ->and($item->mashup['mashup_name'])->toBe('Main Glance Mashup')
        ->and($item->mashup['plugin_ids'])->toBe([$primary->id, $secondary->id]);
});

test('playlists index requires a plugin for each mashup slot', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareAddPlaylistItem', $playlist->id)
        ->set('mashup_layout', '1Lx1R')
        ->set('mashup_plugins', [(string) $plugin->id])
        ->call('savePlaylistItem')
        ->assertHasErrors(['mashup_plugins']);

    expect($playlist->fresh()->items)->toBeEmpty();
});

test('playlists index rejects a plugin owned by another user', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $foreign = Plugin::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareAddPlaylistItem', $playlist->id)
        ->set('mashup_layout', 'full')
        ->set('mashup_plugins', [(string) $foreign->id])
        ->call('savePlaylistItem')
        ->assertHasErrors(['mashup_plugins.0']);

    expect($playlist->fresh()->items)->toBeEmpty();
});

test('playlists index rejects a plugin that is not a recipe', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $webhook = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareAddPlaylistItem', $playlist->id)
        ->set('mashup_layout', 'full')
        ->set('mashup_plugins', [(string) $webhook->id])
        ->call('savePlaylistItem')
        ->assertHasErrors(['mashup_plugins.0']);

    expect($playlist->fresh()->items)->toBeEmpty();
});

test('playlists index rejects adding an item to another users playlist', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create(['user_id' => $intruder->id]);

    $this->actingAs($intruder);

    Livewire::test('playlists.index')
        ->set('item_playlist_id', $playlist->id)
        ->set('mashup_layout', 'full')
        ->set('mashup_plugins', [(string) $plugin->id])
        ->call('savePlaylistItem')
        ->assertForbidden();

    expect($playlist->fresh()->items)->toBeEmpty();
});

test('playlists index rejects editing an item on another users playlist', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $owner->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create(['user_id' => $owner->id]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'order' => 1,
    ]);

    $this->actingAs($intruder);

    Livewire::test('playlists.index')
        ->call('prepareEditPlaylistItem', $item->id)
        ->assertForbidden();

    expect($item->fresh()->plugin_id)->toBe($plugin->id)
        ->and($item->fresh()->mashup)->toBeNull();
});

test('playlists index rejects editing the layout of a non-recipe item', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $webhook = Plugin::factory()->imageWebhook()->create(['user_id' => $user->id]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $webhook->id,
    ]);

    $this->actingAs($user);

    Livewire::test('playlists.index')
        ->call('prepareEditPlaylistItem', $item->id)
        ->assertForbidden();
});
