<?php

namespace App\Jobs;

use App\Actions\RenderPluginImage;
use App\Models\PlaylistItem;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Pre-render a playlist item's plugin into its image cache for the playlist's device.
 * Warming ($force = false) only renders when nothing usable is cached; refreshing
 * ($force = true) re-fetches data and replaces the cached image. Never changes the
 * device's current screen.
 */
class RenderPlaylistItemImageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $playlistItemId,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->playlistItemId.':'.($this->force ? 'refresh' : 'warm');
    }

    public function handle(RenderPluginImage $renderPluginImage): void
    {
        $playlistItem = PlaylistItem::with(['playlist.device', 'plugin'])->find($this->playlistItemId);

        if (! $playlistItem instanceof PlaylistItem || ! $playlistItem->supportsImageCache()) {
            return;
        }

        $plugin = $playlistItem->plugin;

        try {
            $renderPluginImage->handle(
                $plugin,
                $playlistItem->playlist->device,
                force: $this->force,
                existingImageId: $plugin->current_image,
            );
        } catch (Exception $e) {
            Log::error("Failed to render playlist item {$playlistItem->id} ({$plugin->name}): ".$e->getMessage());
        }
    }
}
