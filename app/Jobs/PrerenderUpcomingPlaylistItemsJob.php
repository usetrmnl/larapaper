<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Plugin;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * Predict which devices will fetch a screen in the next one to two minutes and
 * queue a pre-render of their upcoming playlist item, so the image is cached by
 * the time the device calls. Only items that would otherwise render during the
 * device request are queued. Gated behind the "prerender-playlist-items" lab toggle.
 */
class PrerenderUpcomingPlaylistItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const string TOGGLE = 'prerender-playlist-items';

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! Toggle::active(self::TOGGLE)) {
            return;
        }

        $windowStart = now()->addMinute();
        $windowEnd = now()->addMinutes(2);

        Device::query()
            ->whereNotNull('last_refreshed_at')
            ->whereNull('mirror_device_id')
            ->whereHas('playlists', fn ($query) => $query->where('is_active', true))
            ->with('user')
            ->lazyById()
            ->each(function (Device $device) use ($windowStart, $windowEnd): void {
                $this->prerenderForDevice($device, $windowStart, $windowEnd);
            });
    }

    private function prerenderForDevice(Device $device, CarbonInterface $windowStart, CarbonInterface $windowEnd): void
    {
        $nextItem = $device->getNextPlaylistItem();

        if ($nextItem === null || ! $nextItem->supportsImageCache()) {
            return;
        }

        $refreshSeconds = (int) ($nextItem->playlist->refresh_time ?? $device->default_refresh_interval);
        $expectedCallAt = $device->last_refreshed_at->copy()->addSeconds($refreshSeconds);

        if ($expectedCallAt->lte($windowStart) || $expectedCallAt->gt($windowEnd)) {
            return;
        }

        if (($device->pause_until && $device->pause_until->gt($expectedCallAt)) || $device->isSleepModeActive($expectedCallAt)) {
            return;
        }

        $force = $this->renderModeAt($nextItem->plugin, $expectedCallAt);

        if ($force === null) {
            return;
        }

        RenderPlaylistItemImageJob::dispatch($nextItem->id, force: $force);

        Log::info("Prerender plugin \"{$nextItem->plugin->name}\" for Device {$device->id}", [
            'playlist_item_id' => $nextItem->id,
            'force' => $force,
            'expected_call_at' => $expectedCallAt->toIso8601String(),
        ]);
    }

    /**
     * Decide how the plugin must be rendered so its cache is fresh at the expected call.
     *
     * @return bool|null null when the cache is already good, otherwise the force flag:
     *                   true re-fetches data that will be stale by then, false warms the cache.
     */
    private function renderModeAt(Plugin $plugin, CarbonInterface $expectedCallAt): ?bool
    {
        if ($plugin->current_image === null) {
            return false;
        }

        if ($plugin->data_strategy === 'polling'
            && $plugin->data_stale_minutes
            && $plugin->data_payload_updated_at !== null
            && $plugin->data_payload_updated_at->copy()->addMinutes($plugin->data_stale_minutes)->lte($expectedCallAt)) {
            return true;
        }

        return $plugin->isDataStale() ? false : null;
    }
}
