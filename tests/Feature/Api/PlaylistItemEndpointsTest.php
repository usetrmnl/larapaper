<?php

use App\Jobs\RenderPlaylistItemImageJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{0: User, 1: Playlist}
 */
function createUserWithPlaylist(): array
{
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['device_id' => $device->id]);

    return [$user, $playlist];
}

/**
 * @param  array<string, mixed>  $pluginAttributes
 */
function createRecipeItem(User $user, Playlist $playlist, array $pluginAttributes = []): PlaylistItem
{
    $plugin = Plugin::factory()->create(array_merge([
        'user_id' => $user->id,
        'current_image' => 'cached-image-uuid',
        'current_image_metadata' => ['width' => 800],
    ], $pluginAttributes));

    return PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
    ]);
}

test('playlist items are listed in order', function (): void {
    [$user, $playlist] = createUserWithPlaylist();
    $plugin = Plugin::factory()->create(['user_id' => $user->id, 'name' => 'Weather']);
    $second = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'plugin_id' => $plugin->id, 'order' => 2]);
    $first = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'plugin_id' => $plugin->id, 'order' => 1, 'is_active' => true]);
    PlaylistItem::factory()->create(['plugin_id' => $plugin->id]);

    Sanctum::actingAs($user);

    $this->getJson(route('api.playlists.items.index', $playlist))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJson(['data' => [[
            'playlist_id' => $playlist->id,
            'plugin_id' => $plugin->id,
            'plugin_name' => 'Weather',
            'order' => 1,
            'is_active' => true,
            'is_mashup' => false,
            'mashup' => null,
            'supports_image_cache' => true,
        ]]]);
});

test('playlist items list exposes mashup details', function (): void {
    [$user, $playlist] = createUserWithPlaylist();
    $plugins = Plugin::factory()->count(2)->create(['user_id' => $user->id]);
    PlaylistItem::createMashup($playlist, '1Lx1R', $plugins->pluck('id')->all(), 'Split', 0);

    Sanctum::actingAs($user);

    $this->getJson(route('api.playlists.items.index', $playlist))
        ->assertOk()
        ->assertJsonPath('data.0.is_mashup', true)
        ->assertJsonPath('data.0.supports_image_cache', false)
        ->assertJsonPath('data.0.mashup', [
            'layout' => '1Lx1R',
            'name' => 'Split',
            'plugin_ids' => $plugins->pluck('id')->all(),
        ]);
});

test('playlist items of another user are not found', function (): void {
    [, $playlist] = createUserWithPlaylist();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.playlists.items.index', $playlist))->assertNotFound();
});

test('playlist items require authentication', function (): void {
    [, $playlist] = createUserWithPlaylist();

    $this->getJson(route('api.playlists.items.index', $playlist))->assertUnauthorized();
});

test('clearing image cache resets the plugin image', function (): void {
    [$user, $playlist] = createUserWithPlaylist();
    $item = createRecipeItem($user, $playlist);

    Sanctum::actingAs($user);

    $this->postJson(route('api.playlist-items.cache.clear', $item))
        ->assertOk()
        ->assertJsonPath('data.id', $item->id);

    $plugin = $item->plugin->refresh();
    expect($plugin->current_image)->toBeNull()
        ->and($plugin->current_image_metadata)->toBeNull();
});

test('warming and refreshing the cache queue a render job', function (string $action, bool $force): void {
    Queue::fake();
    [$user, $playlist] = createUserWithPlaylist();
    $item = createRecipeItem($user, $playlist);

    Sanctum::actingAs($user);

    $this->postJson(route("api.playlist-items.cache.{$action}", $item))
        ->assertAccepted()
        ->assertJsonPath('data.id', $item->id);

    Queue::assertPushed(
        RenderPlaylistItemImageJob::class,
        fn (RenderPlaylistItemImageJob $job): bool => $job->playlistItemId === $item->id && $job->force === $force,
    );
    expect($item->plugin->refresh()->current_image)->toBe('cached-image-uuid');
})->with([
    'warm' => ['warm', false],
    'refresh' => ['refresh', true],
]);

dataset('cache actions', ['clear', 'warm', 'refresh']);

test('cache actions on another user item are not found', function (string $action): void {
    Queue::fake();
    [$owner, $playlist] = createUserWithPlaylist();
    $item = createRecipeItem($owner, $playlist);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route("api.playlist-items.cache.{$action}", $item))->assertNotFound();

    Queue::assertNothingPushed();
    expect($item->plugin->refresh()->current_image)->toBe('cached-image-uuid');
})->with('cache actions');

test('cache actions on a plugin owned by someone else are forbidden', function (string $action): void {
    Queue::fake();
    [$user, $playlist] = createUserWithPlaylist();
    $item = createRecipeItem(User::factory()->create(), $playlist);

    Sanctum::actingAs($user);

    $this->postJson(route("api.playlist-items.cache.{$action}", $item))->assertForbidden();

    Queue::assertNothingPushed();
    expect($item->plugin->refresh()->current_image)->toBe('cached-image-uuid');
})->with('cache actions');

test('cache actions are rejected for non-recipe items', function (string $action): void {
    Queue::fake();
    [$user, $playlist] = createUserWithPlaylist();
    $item = createRecipeItem($user, $playlist, ['plugin_type' => 'image_webhook']);

    Sanctum::actingAs($user);

    $this->postJson(route("api.playlist-items.cache.{$action}", $item))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Image cache is only available for recipe items.');

    Queue::assertNothingPushed();
    expect($item->plugin->refresh()->current_image)->toBe('cached-image-uuid');
})->with('cache actions');

test('cache actions are rejected for mashup items', function (string $action): void {
    Queue::fake();
    [$user, $playlist] = createUserWithPlaylist();
    $plugins = Plugin::factory()->count(2)->create(['user_id' => $user->id]);
    $item = PlaylistItem::createMashup($playlist, '1Lx1R', $plugins->pluck('id')->all(), 'Split', 0);

    Sanctum::actingAs($user);

    $this->postJson(route("api.playlist-items.cache.{$action}", $item))->assertUnprocessable();

    Queue::assertNothingPushed();
})->with('cache actions');
