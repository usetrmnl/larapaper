<?php

use App\Jobs\RenderPlaylistItemImageJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

afterEach(function (): void {
    EpaperPipeline::restore();
});

/**
 * A static recipe on a device showing "live-screen". $cachedImage set means a fresh
 * cache whose metadata matches the device, so it would be served as-is.
 */
function playlistItemWithRecipe(?string $cachedImage): PlaylistItem
{
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'current_screen_image' => 'live-screen',
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'data_strategy' => 'static',
        'polling_url' => null,
        'data_stale_minutes' => 60,
        'data_payload_updated_at' => now(),
        'render_markup' => '<div>Hello</div>',
    ]);
    $plugin->update([
        'current_image' => $cachedImage,
        'current_image_metadata' => $cachedImage ? ImageGenerationService::buildImageMetadataFromDevice($device) : null,
    ]);

    return PlaylistItem::factory()->create([
        'playlist_id' => Playlist::factory()->create(['device_id' => $device->id])->id,
        'plugin_id' => $plugin->id,
    ]);
}

test('warming renders an image when nothing is cached', function (): void {
    $item = playlistItemWithRecipe(cachedImage: null);

    RenderPlaylistItemImageJob::dispatchSync($item->id, force: false);

    $plugin = $item->plugin->refresh();
    expect($plugin->current_image)->not->toBeNull()
        ->and($plugin->current_image_metadata)->not->toBeNull()
        ->and($item->playlist->device->refresh()->current_screen_image)->toBe('live-screen');
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");
});

test('warming keeps a fresh cached image', function (): void {
    $item = playlistItemWithRecipe(cachedImage: 'cached-image-uuid');

    RenderPlaylistItemImageJob::dispatchSync($item->id, force: false);

    expect($item->plugin->refresh()->current_image)->toBe('cached-image-uuid');
});

test('refreshing replaces a fresh cached image', function (): void {
    $item = playlistItemWithRecipe(cachedImage: 'cached-image-uuid');

    RenderPlaylistItemImageJob::dispatchSync($item->id, force: true);

    $plugin = $item->plugin->refresh();
    expect($plugin->current_image)->not->toBeNull()
        ->not->toBe('cached-image-uuid')
        ->and($item->playlist->device->refresh()->current_screen_image)->toBe('live-screen');
    Storage::disk('public')->assertExists("images/generated/{$plugin->current_image}.png");
});

test('mashup items are skipped', function (): void {
    $item = playlistItemWithRecipe(cachedImage: null);
    $item->update(['mashup' => ['mashup_layout' => '1Lx1R', 'mashup_name' => 'Split', 'plugin_ids' => [$item->plugin_id]]]);

    RenderPlaylistItemImageJob::dispatchSync($item->id, force: true);

    expect($item->plugin->refresh()->current_image)->toBeNull();
});
