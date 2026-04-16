<?php

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;

test('playlist has required attributes', function (): void {
    $playlist = Playlist::factory()->create([
        'name' => 'Test Playlist',
        'is_active' => true,
        'weekdays' => [1, 2, 3],
        'active_from' => '09:00',
        'active_until' => '17:00',
    ]);

    expect($playlist)
        ->name->toBe('Test Playlist')
        ->is_active->toBeTrue()
        ->weekdays->toBe([1, 2, 3])
        ->active_from->format('H:i')->toBe('09:00')
        ->active_until->format('H:i')->toBe('17:00');
});

test('playlist belongs to device', function (): void {
    $device = Device::factory()->create();
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);

    expect($playlist->device)
        ->toBeInstanceOf(Device::class)
        ->id->toBe($device->id);
});

test('playlist has many items', function (): void {
    $playlist = Playlist::factory()->create();
    $items = PlaylistItem::factory()->count(3)->create(['playlist_id' => $playlist->id]);

    expect($playlist->items)
        ->toHaveCount(3)
        ->each->toBeInstanceOf(PlaylistItem::class);
});

test('getNextPlaylistItem returns null when playlist is inactive', function (): void {
    $playlist = Playlist::factory()->create(['is_active' => false]);

    expect($playlist->getNextPlaylistItem())->toBeNull();
});

test('getPlaylistConstraintRating is zero when there is no time window and no weekday filter', function (): void {
    $playlist = Playlist::factory()->make([
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);

    expect($playlist->getPlaylistConstraintRating())->toBe(0);
});

test('getPlaylistConstraintRating adds one when weekdays are set and non-empty', function (): void {
    $playlist = Playlist::factory()->make([
        'weekdays' => [1, 3, 5],
        'active_from' => null,
        'active_until' => null,
    ]);

    expect($playlist->getPlaylistConstraintRating())->toBe(1);
});

test('getPlaylistConstraintRating does not add weekday score when weekdays is an empty array', function (): void {
    $playlist = Playlist::factory()->make([
        'weekdays' => [],
        'active_from' => null,
        'active_until' => null,
    ]);

    expect($playlist->getPlaylistConstraintRating())->toBe(0);
});

test('getPlaylistConstraintRating adds two when both active_from and active_until are set', function (): void {
    $playlist = Playlist::factory()->make([
        'weekdays' => null,
        'active_from' => '09:00',
        'active_until' => '17:00',
    ]);

    expect($playlist->getPlaylistConstraintRating())->toBe(2);
});

test('getPlaylistConstraintRating is three when both time window and non-empty weekdays are set', function (): void {
    $playlist = Playlist::factory()->make([
        'weekdays' => [1],
        'active_from' => '09:00',
        'active_until' => '17:00',
    ]);

    expect($playlist->getPlaylistConstraintRating())->toBe(3);
});

test('getPlaylistConstraintRating does not add time score when only one of active_from or active_until is set', function (): void {
    $onlyFrom = Playlist::factory()->make([
        'weekdays' => null,
        'active_from' => '09:00',
        'active_until' => null,
    ]);
    $onlyUntil = Playlist::factory()->make([
        'weekdays' => null,
        'active_from' => null,
        'active_until' => '17:00',
    ]);

    expect($onlyFrom->getPlaylistConstraintRating())->toBe(0)
        ->and($onlyUntil->getPlaylistConstraintRating())->toBe(0);
});
