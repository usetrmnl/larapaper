<?php

namespace App\Jobs;

use App\Actions\Api\RunDeviceDisplayCycle;
use App\Models\Device;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll and render one recipe for a device, as RunDeviceDisplayCycle does for a
 * playlist item, without advancing the playlist or setting the device's screen:
 * the device's next display request does both and finds the screen fresh.
 */
class PrerenderPluginScreenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A failed render is left to the display cycle rather than retried.
     */
    public int $tries = 1;

    /**
     * Release the uniqueness lock after this many seconds, should a worker die mid-render.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly Device $device,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->plugin->id;
    }

    /**
     * Cache key marking a plugin whose last pre-render failed.
     */
    public static function failedCacheKey(Plugin $plugin): string
    {
        return "prerender-failed:{$plugin->id}";
    }

    public function handle(): void
    {
        $plugin = $this->plugin;
        $device = $this->device;

        try {
            $plugin->updateDataPayload();
            $plugin->refresh();

            if (RunDeviceDisplayCycle::shouldSkipFromPayload($plugin)) {
                return;
            }

            $markup = $plugin->render(device: $device);

            if (RunDeviceDisplayCycle::shouldSkipFromMarkup($markup)) {
                $plugin->clearCurrentImage();

                return;
            }

            $imageId = ImageGenerationService::generateImageFromModel(
                markup: $markup,
                deviceModel: $device->deviceModel,
                user: $device->user,
                palette: $device->palette ?? $device->deviceModel?->palette,
                device: $device,
                plugin: $plugin,
                existingImageId: $plugin->current_image,
            );

            $plugin->update([
                'current_image' => $imageId,
                'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
                'data_payload_updated_at' => now(),
            ]);

            ImageGenerationService::cleanupFolder();
        } catch (Throwable $e) {
            Log::error("Failed to pre-render plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());
            Cache::put(self::failedCacheKey($plugin), true, now()->addMinutes($plugin->data_stale_minutes));
        }
    }
}
