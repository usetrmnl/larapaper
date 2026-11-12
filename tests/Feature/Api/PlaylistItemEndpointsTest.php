<?php

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function createOwnedPlaylistItem(User $user, array $attributes = []): PlaylistItem
{
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Weather']);

    return PlaylistItem::factory()->create(array_merge([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'order' => 3,
        'is_active' => true,
    ], $attributes));
}

test('authenticated user can list their playlist items in the shape of TRMNL', function (): void {
    $user = User::factory()->create();
    $item = createOwnedPlaylistItem($user, ['last_displayed_at' => '2030-01-01 12:00:00']);
    createOwnedPlaylistItem(User::factory()->create());

    Sanctum::actingAs($user);

    $this->getJson('/api/playlists/items')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJson(['data' => [[
            'id' => $item->id,
            'device_id' => $item->playlist->device_id,
            'plugin_setting_id' => $item->plugin_id,
            'mashup_id' => null,
            'mirror' => false,
            'row_order' => 3,
            'visible' => true,
            'rendered_at' => '2030-01-01T12:00:00Z',
            'created_at' => $item->created_at->toIso8601ZuluString(),
            'updated_at' => $item->updated_at->toIso8601ZuluString(),
            'plugin_setting' => ['id' => $item->plugin_id, 'name' => 'Weather', 'plugin_id' => $item->plugin_id],
        ]]]);
});

test('a playlist item that was never displayed has no rendered_at', function (): void {
    $user = User::factory()->create();
    createOwnedPlaylistItem($user, ['last_displayed_at' => null]);

    Sanctum::actingAs($user);

    $this->getJson('/api/playlists/items')->assertOk()->assertJsonPath('data.0.rendered_at', null);
});

test('playlist items require authentication with 401', function (): void {
    $this->getJson('/api/playlists/items')->assertUnauthorized();
});

test('authenticated user can hide a playlist item', function (): void {
    $user = User::factory()->create();
    $item = createOwnedPlaylistItem($user);

    Sanctum::actingAs($user);

    $this->patchJson("/api/playlists/items/{$item->id}", ['visible' => false])
        ->assertOk()
        ->assertJsonPath('data.visible', false);

    expect($item->refresh()->is_active)->toBeFalse();
});

test('playlist item update rejects a visible that is not a boolean with 422', function (): void {
    $user = User::factory()->create();
    $item = createOwnedPlaylistItem($user);

    Sanctum::actingAs($user);

    $this->patchJson("/api/playlists/items/{$item->id}", ['visible' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visible');
});

test('user cannot update a playlist item they do not own', function (): void {
    $item = createOwnedPlaylistItem(User::factory()->create());

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/playlists/items/{$item->id}", ['visible' => false])->assertNotFound();

    expect($item->refresh()->is_active)->toBeTrue();
});
