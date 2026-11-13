<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\User;
use Livewire\Livewire;

function recipeFor(User $user): Plugin
{
    return Plugin::factory()->create([
        'user_id' => $user->id,
        'plugin_type' => 'recipe',
        'data_strategy' => 'static',
    ]);
}

test('recipe can be added to own playlist as a mashup with own plugins', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = recipeFor($user);
    $otherOwnPlugin = recipeFor($user);
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->set('mashup_layout', '1Lx1R')
        ->set('mashup_plugins', [(string) $otherOwnPlugin->id])
        ->call('addToPlaylist')
        ->assertHasNoErrors();

    expect($playlist->fresh()->items)->toHaveCount(1)
        ->and($playlist->fresh()->items->first()->mashup['plugin_ids'])->toBe([$plugin->id, $otherOwnPlugin->id]);
});

test('recipe mashup rejects another users plugin', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = recipeFor($user);
    $foreignPlugin = recipeFor(User::factory()->create());
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->set('mashup_layout', '1Lx1R')
        ->set('mashup_plugins', [(string) $foreignPlugin->id])
        ->call('addToPlaylist')
        ->assertHasErrors(['mashup_plugins.0']);

    expect($playlist->fresh()->items)->toBeEmpty();
});

test('recipe cannot be added to another users device', function (): void {
    $user = User::factory()->create();
    $foreignDevice = Device::factory()->for(User::factory())->create();
    $plugin = recipeFor($user);
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $foreignDevice->id])
        ->set('device_playlists.'.$foreignDevice->id, 'new')
        ->set('device_playlist_names.'.$foreignDevice->id, 'Hijacked')
        ->set('mashup_layout', 'full')
        ->call('addToPlaylist')
        ->assertHasErrors(['checked_devices.0']);

    expect($foreignDevice->playlists()->count())->toBe(0);
});

test('recipe cannot be added to a playlist of another users device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $foreignPlaylist = Playlist::factory()->for(Device::factory()->for(User::factory()))->create();
    $plugin = recipeFor($user);
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $foreignPlaylist->id)
        ->set('mashup_layout', 'full')
        ->call('addToPlaylist')
        ->assertNotFound();

    expect($foreignPlaylist->fresh()->items)->toBeEmpty();
});

test('recipe mashup keeps only the slots the layout needs', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = recipeFor($user);
    $slot = recipeFor($user);
    $unused = recipeFor($user);
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->set('mashup_layout', '1Lx1R')
        ->assertSee('Mashup Slots')
        ->assertSee('Main Plugin')
        ->set('mashup_plugins', [(string) $slot->id, (string) $unused->id])
        ->call('addToPlaylist')
        ->assertHasNoErrors();

    expect($playlist->fresh()->items->first()->mashup['plugin_ids'])->toBe([$plugin->id, $slot->id]);
});

test('recipe mashup requires a plugin for each slot', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($device)->create();
    $plugin = recipeFor($user);
    $slot = recipeFor($user);
    $this->actingAs($user);

    Livewire::test('plugins.recipe', ['plugin' => $plugin])
        ->set('checked_devices', [(string) $device->id])
        ->set('device_playlists.'.$device->id, (string) $playlist->id)
        ->set('mashup_layout', '2x2')
        ->set('mashup_plugins', [(string) $slot->id])
        ->call('addToPlaylist')
        ->assertHasErrors(['mashup_plugins']);

    expect($playlist->fresh()->items)->toBeEmpty();
});
