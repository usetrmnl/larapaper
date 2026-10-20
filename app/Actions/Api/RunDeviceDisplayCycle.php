<?php

namespace App\Actions\Api;

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Services\DeviceImageResolver;
use App\Services\DeviceScreenFilename;
use App\Services\ImageGenerationService;
use Exception;
use Illuminate\Support\Facades\Log;

class RunDeviceDisplayCycle
{
    public function __construct(private readonly DeviceImageResolver $imageResolver) {}

    /**
     * Resolve the image path and refresh-time override for the current display
     * cycle of a device, handling pause, sleep, mirrored devices, playlists,
     * and mashup playlist items.
     *
     * `screen_identity` and `screen_prefix` describe *which* screen this is, so
     * DeviceScreenFilename can name it stably across re-renders — the firmware
     * uses that name to browse its cached screen history.
     *
     * @return array{image_path: ?string, refresh_time_override: ?int, screen_identity: string, screen_prefix: string}
     */
    public function handle(Device $device): array
    {
        if ($device->isPauseActive()) {
            return [
                'image_path' => $this->defaultImagePath($device, 'sleep'),
                'refresh_time_override' => min(Device::MAX_PAUSE_REFRESH_SECONDS, (int) now()->diffInSeconds($device->pause_until)),
                'screen_identity' => 'sleep',
                'screen_prefix' => DeviceScreenFilename::PREFIX_SYSTEM,
            ];
        }

        if ($device->isSleepModeActive()) {
            return [
                'image_path' => $this->defaultImagePath($device, 'sleep'),
                'refresh_time_override' => $device->getSleepModeEndsInSeconds() ?? $device->default_refresh_interval,
                'screen_identity' => 'sleep',
                'screen_prefix' => DeviceScreenFilename::PREFIX_SYSTEM,
            ];
        }

        $refreshTimeOverride = null;
        $imageUuid = $device->mirrorDevice?->current_screen_image;
        // A mirrored device shows whatever its source shows; treat that as one
        // screen rather than inheriting the source's per-plugin identities.
        $identity = 'mirror:'.$device->mirror_device_id;
        $prefix = DeviceScreenFilename::PREFIX_SYSTEM;

        if (! $imageUuid) {
            ['refresh_time_override' => $refreshTimeOverride, 'identity' => $identity, 'prefix' => $prefix]
                = $this->processPlaylist($device);
            $device->refresh();
            $imageUuid = $device->current_screen_image;
        }

        if (! $imageUuid) {
            return [
                'image_path' => $this->defaultImagePath($device, 'setup-logo'),
                'refresh_time_override' => $refreshTimeOverride,
                'screen_identity' => 'setup',
                'screen_prefix' => DeviceScreenFilename::PREFIX_SYSTEM,
            ];
        }

        return [
            'image_path' => $this->imageResolver->resolve($device, $imageUuid),
            'refresh_time_override' => $refreshTimeOverride,
            'screen_identity' => $identity,
            'screen_prefix' => $prefix,
        ];
    }

    /**
     * Render and cache the next playlist item for the device.
     *
     * Returns the refresh time override from the playlist (if any), plus the
     * identity of the item actually rendered — which is not necessarily the one
     * we started from, since items can skip themselves via TRMNL_SKIP_DISPLAY.
     *
     * @return array{refresh_time_override: ?int, identity: string, prefix: string}
     */
    private function processPlaylist(Device $device): array
    {
        // Whatever is already on screen, grouped under one history slot: we have
        // no playlist item to attribute it to.
        $unattributed = [
            'refresh_time_override' => null,
            'identity' => 'device:'.$device->id,
            'prefix' => DeviceScreenFilename::PREFIX_SYSTEM,
        ];

        $playlistItem = $device->getNextPlaylistItem();

        if (! $playlistItem) {
            return $unattributed;
        }

        $playlist = $playlistItem->playlist;
        $refreshTimeOverride = $playlist?->refresh_time;

        if (! $playlist) {
            return $unattributed;
        }

        foreach ($playlist->getCycleItemsStartingFrom($playlistItem) as $candidate) {
            if ($candidate->isMashup()) {
                $this->renderMashup($device, $candidate);

                return [
                    'refresh_time_override' => $refreshTimeOverride,
                    'identity' => 'mashup:'.$candidate->id,
                    'prefix' => DeviceScreenFilename::PREFIX_MASHUP,
                ];
            }

            if ($this->renderSinglePlugin($device, $candidate)) {
                return [
                    'refresh_time_override' => $refreshTimeOverride,
                    'identity' => 'plugin:'.$candidate->plugin_id,
                    'prefix' => DeviceScreenFilename::PREFIX_PLUGIN,
                ];
            }
        }

        return [...$unattributed, 'refresh_time_override' => $refreshTimeOverride];
    }

    private function renderSinglePlugin(Device $device, PlaylistItem $playlistItem): bool
    {
        $plugin = $playlistItem->plugin;

        ImageGenerationService::resetIfNotCacheable($plugin, $device);
        $plugin->refresh();

        $isDataStale = $plugin->isDataStale();

        if ($isDataStale) {
            $plugin->updateDataPayload();
            $plugin->refresh();
        }

        if (self::shouldSkipFromPayload($plugin)) {
            Log::info('Skipping rendering because payload sets TRMNL_SKIP_DISPLAY', [
                'device_id' => $device->id,
                'plugin_id' => $plugin->id,
            ]);

            return false;
        }

        $needsRender = $isDataStale || $plugin->current_image === null;

        if ($needsRender) {
            try {
                $usesMarkupPipeline = $plugin->handler()?->output() !== PluginOutput::Image;
                $markup = $usesMarkupPipeline ? $plugin->render(device: $device) : '';

                if ($usesMarkupPipeline && self::shouldSkipFromMarkup($markup)) {
                    Log::info('Skipping rendering because markup sets TRMNL_SKIP_DISPLAY', [
                        'device_id' => $device->id,
                        'plugin_id' => $plugin->id,
                    ]);

                    $plugin->clearCurrentImage();

                    return false;
                }

                GenerateScreenJob::dispatchSync($device->id, $plugin->id, $markup);
            } catch (Exception $e) {
                Log::error("Failed to render plugin {$plugin->id} ({$plugin->name}): ".$e->getMessage());
                $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $plugin->name);
                $device->update(['current_screen_image' => $errorImageUuid]);

                return true;
            }
        }

        $plugin->refresh();

        if ($plugin->current_image !== null) {
            $playlistItem->update(['last_displayed_at' => now()]);
            $device->update(['current_screen_image' => $plugin->current_image]);

            return true;
        }

        return true;
    }

    private function renderMashup(Device $device, PlaylistItem $playlistItem): void
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
            $firstPlugin = $plugins->first();
            $pluginName = $firstPlugin instanceof Plugin ? $firstPlugin->name : 'Recipe';
            $errorImageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'error', $pluginName);
            $device->update(['current_screen_image' => $errorImageUuid]);
        }

        $device->refresh();

        if ($device->current_screen_image !== null) {
            $playlistItem->update(['last_displayed_at' => now()]);
        }
    }

    public static function shouldSkipFromPayload(Plugin $plugin): bool
    {
        return is_array($plugin->data_payload)
            && ($plugin->data_payload['TRMNL_SKIP_DISPLAY'] ?? false) === true;
    }

    public static function shouldSkipFromMarkup(string $markup): bool
    {
        return preg_match(
            '/<script\b[^>]*>.*?window\.TRMNL_SKIP_DISPLAY\s*=\s*true\b.*?<\/script>/is',
            $markup
        ) === 1;
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
