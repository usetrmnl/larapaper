<?php

use App\Jobs\PrerenderUpcomingPlaylistItemsJob;
use App\Jobs\RenderPlaylistItemImageJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use OffloadProject\Toggle\Facades\Toggle;

beforeEach(function (): void {
    Queue::fake([RenderPlaylistItemImageJob::class]);
    $this->freezeTime();
    Toggle::enable(PrerenderUpcomingPlaylistItemsJob::TOGGLE);
});

/**
 * A device that last called $secondsAgo with a 15-minute refresh interval, whose next
 * playlist item is a polling recipe with a cached image and 30-minute stale window.
 *
 * @param  array<string, mixed>  $pluginAttributes
 * @param  array<string, mixed>  $deviceAttributes
 */
function deviceWithUpcomingRecipe(int $secondsAgo, array $pluginAttributes = [], array $deviceAttributes = [], ?int $playlistRefreshTime = null): PlaylistItem
{
    $user = User::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'default_refresh_interval' => 900,
        'last_refreshed_at' => now()->subSeconds($secondsAgo),
        ...$deviceAttributes,
    ]);

    $plugin = Plugin::factory()->create([
        'user_id' => $user->id,
        'data_strategy' => 'polling',
        'data_stale_minutes' => 30,
        'data_payload_updated_at' => now(),
        'current_image' => 'cached-image-uuid',
        ...$pluginAttributes,
    ]);

    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
        'refresh_time' => $playlistRefreshTime,
    ]);

    return PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'is_active' => true,
    ]);
}

/** Seconds since the last call so that a 15-minute refresh is due in 90 seconds. */
const DUE_IN_90_SECONDS = 900 - 90;

test('nothing is queued while the lab toggle is off', function (): void {
    Toggle::disable(PrerenderUpcomingPlaylistItemsJob::TOGGLE);
    deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['current_image' => null]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
});

test('refreshes the next item when its data goes stale before the device calls', function (): void {
    $item = deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['data_payload_updated_at' => now()->subMinutes(29)]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertPushed(RenderPlaylistItemImageJob::class, fn (RenderPlaylistItemImageJob $job): bool => $job->playlistItemId === $item->id && $job->force);
});

test('warms the next item when nothing is cached', function (): void {
    $item = deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['current_image' => null]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertPushed(RenderPlaylistItemImageJob::class, fn (RenderPlaylistItemImageJob $job): bool => $job->playlistItemId === $item->id && ! $job->force);
});

test('skips items whose cache is still fresh when the device calls', function (): void {
    deviceWithUpcomingRecipe(DUE_IN_90_SECONDS);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
});

test('skips devices not due within one to two minutes', function (int $dueInSeconds): void {
    deviceWithUpcomingRecipe(900 - $dueInSeconds, ['current_image' => null]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
})->with([
    'due in 30 seconds' => 30,
    'due in exactly 60 seconds' => 60,
    'due in 150 seconds' => 150,
]);

test('uses the playlist refresh time over the device default', function (): void {
    $item = deviceWithUpcomingRecipe(300 - 90, ['current_image' => null], playlistRefreshTime: 300);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertPushed(RenderPlaylistItemImageJob::class, fn (RenderPlaylistItemImageJob $job): bool => $job->playlistItemId === $item->id);
});

test('skips mashup items', function (): void {
    $item = deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['current_image' => null]);
    $item->update(['mashup' => ['mashup_layout' => '1Lx1R', 'mashup_name' => 'Split', 'plugin_ids' => [$item->plugin_id]]]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
});

test('skips mirrored devices', function (): void {
    $source = Device::factory()->create();
    deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['current_image' => null], ['mirror_device_id' => $source->id]);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
});

test('skips devices asleep or paused at the expected call', function (array $deviceAttributes): void {
    deviceWithUpcomingRecipe(DUE_IN_90_SECONDS, ['current_image' => null], $deviceAttributes);

    PrerenderUpcomingPlaylistItemsJob::dispatchSync();

    Queue::assertNotPushed(RenderPlaylistItemImageJob::class);
})->with([
    'asleep' => fn (): array => [
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => now()->subHour()->format('H:i'),
        'sleep_mode_to' => now()->addHour()->format('H:i'),
    ],
    'paused' => fn (): array => ['pause_until' => now()->addMinutes(10)],
]);
