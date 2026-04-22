<?php

namespace App\Actions\Api;

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\Plugin;
use App\Services\DeviceImageResolver;
use App\Services\ImageGenerationService;
use Exception;
use Illuminate\Support\Facades\Log;

class RunDeviceDisplayCycle
{
    public function __construct(private DeviceImageResolver $imageResolver) {}

    /**
     * Resolve the image path and refresh-time override for the current display
     * cycle of a device, handling pause, sleep, mirrored devices, playlists,
     * and mashup playlist items.
     *
     * @return array{image_path: ?string, refresh_time_override: ?int}
     */
    public function handle(Device $device): array
    {
        if ($device->isPauseActive()) {
            return [
                'image_path' => $this->defaultImagePath($device, 'sleep'),
                'refresh_time_override' => (int) now()->diffInSeconds($device->pause_until),
            ];
        }

        if ($device->isSleepModeActive()) {
            return [
                'image_path' => $this->defaultImagePath($device, 'sleep'),
                'refresh_time_override' => $device->getSleepModeEndsInSeconds() ?? $device->default_refresh_interval,
            ];
        }

        $refreshTimeOverride = null;
        $imageUuid = $device->mirrorDevice?->current_screen_image;

        if (! $imageUuid) {
            $refreshTimeOverride = $this->processPlaylist($device);
            $device->refresh();
            $imageUuid = $device->current_screen_image;
        }

        if (! $imageUuid) {
            return [
                'image_path' => $this->defaultImagePath($device, 'setup-logo'),
                'refresh_time_override' => $refreshTimeOverride,
            ];
        }

        return [
            'image_path' => $this->imageResolver->resolve($device, $imageUuid),
            'refresh_time_override' => $refreshTimeOverride,
        ];
    }

    /**
     * Render and cache the next playlist item for the device.
     * Returns the refresh time override from the playlist (if any).
     */
    private function processPlaylist(Device $device): ?int
    {
        $playlistItem = $device->getNextPlaylistItem();

        if (! $playlistItem) {
            return null;
        }

        $refreshTimeOverride = $playlistItem->playlist()->first()->refresh_time;

        if (! $playlistItem->isMashup()) {
            $this->renderSinglePlugin($device, $playlistItem);
        } else {
            $this->renderMashup($device, $playlistItem);
        }

        return $refreshTimeOverride;
    }

    private function renderSinglePlugin(Device $device, $playlistItem): void
    {
        $plugin = $playlistItem->plugin;

        ImageGenerationService::resetIfNotCacheable($plugin, $device);
        $plugin->refresh();

        if ($plugin->isDataStale() || $plugin->current_image === null) {
            $plugin->updateDataPayload();
            try {
                $markup = $plugin->render(device: $device);
                GenerateScreenJob::dispatchSync($device->id, $plugin->id, $markup);
            } catch (Exception $e) {
                Log::error("Failed to render plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());
                $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $plugin->name);
                $device->update(['current_screen_image' => $errorImageUuid]);
            }
        }

        $plugin->refresh();

        if ($plugin->current_image !== null) {
            $playlistItem->update(['last_displayed_at' => now()]);
            $device->update(['current_screen_image' => $plugin->current_image]);
        }
    }

    private function renderMashup(Device $device, $playlistItem): void
    {
        $plugins = Plugin::whereIn('id', $playlistItem->getMashupPluginIds())->get();

        foreach ($plugins as $plugin) {
            ImageGenerationService::resetIfNotCacheable($plugin);
            if ($plugin->isDataStale() || $plugin->current_image === null) {
                $plugin->updateDataPayload();
            }
        }

        try {
            $markup = $playlistItem->render(device: $device);
            GenerateScreenJob::dispatchSync($device->id, null, $markup);
        } catch (Exception $e) {
            Log::error("Failed to render mashup playlist item {$playlistItem->id}: ".$e->getMessage());
            $pluginName = $plugins->first()?->name ?? 'Recipe';
            $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $pluginName);
            $device->update(['current_screen_image' => $errorImageUuid]);
        }

        $device->refresh();

        if ($device->current_screen_image !== null) {
            $playlistItem->update(['last_displayed_at' => now()]);
        }
    }

    /**
     * Return the path to a device-specific default image, generating one from
     * template if no device-specific image exists.
     */
    private function defaultImagePath(Device $device, string $type): string
    {
        $imagePath = ImageGenerationService::getDeviceSpecificDefaultImage($device, $type);

        if ($imagePath) {
            return $imagePath;
        }

        $imageUuid = ImageGenerationService::generateDefaultScreenImage($device, $type);

        return 'images/generated/'.$imageUuid.'.png';
    }
}
