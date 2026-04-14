<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
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
