<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Actions\Api\ResolveDeviceByApiKey;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Plugin;
use App\Services\DeviceImageResolver;
use App\Services\DeviceScreenFilename;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CurrentScreenController extends Controller
{
    public function __construct(
        private readonly ResolveDeviceByApiKey $resolveDevice,
        private readonly DeviceImageResolver $imageResolver,
        private readonly DeviceScreenFilename $screenFilename,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $device = $this->resolveDevice->handle($request);

        if (! $device) {
            return response()->json([
                'status' => 404,
                'message' => 'Device not found or invalid access token',
            ], 404);
        }

        $source = $device->mirrorDevice ?? $device;
        $imageUuid = $source->current_screen_image;
        $offlineImagePath = $this->offlineImagePath($device, $source);

        if ($offlineImagePath !== null) {
            $imagePath = $offlineImagePath;
        } elseif (! $imageUuid) {
            $imagePath = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');
            if (! $imagePath) {
                $imageUuid = ImageGenerationService::generateDefaultScreenImage($device, 'setup-logo');
                $imagePath = 'images/generated/'.$imageUuid.'.png';
            }
        } else {
            $imagePath = $this->imageResolver->resolve($device, $imageUuid);
        }

        // Same naming rules as /api/display, keyed off whichever plugin owns this
        // image so both endpoints land on the same history slot for one screen.
        $owner = $offlineImagePath === null && $imageUuid ? Plugin::where('current_image', $imageUuid)->first() : null;
        $identity = $owner ? 'plugin:'.$owner->id : 'device:'.$device->id;

        $response = [
            'status' => 200,
            'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
            'filename' => $this->screenFilename->make(
                $imagePath,
                $offlineImagePath !== null ? 'sleep' : $identity,
                $owner ? DeviceScreenFilename::PREFIX_PLUGIN : DeviceScreenFilename::PREFIX_SYSTEM,
            ),
            'refresh_rate' => $device->default_refresh_interval,
            'reset_firmware' => false,
            'update_firmware' => false,
            'firmware_url' => $device->firmware_url,
            'special_function' => $device->special_function ?? 'sleep',
        ];

        if (config('services.trmnl.image_url_timeout')) {
            $response['image_url_timeout'] = config('services.trmnl.image_url_timeout');
        }

        return response()->json($response);
    }

    /**
     * Resolve the screen a paused or sleeping source device is showing, without
     * rendering: this endpoint only reports state, so it never generates a plugin image.
     * Returns null while the source is awake, or if no sleep image exists yet.
     */
    private function offlineImagePath(Device $device, Device $source): ?string
    {
        if ($source->isPauseActive()) {
            $plugin = $source->pausePlugin;
        } elseif ($source->isSleepModeActive()) {
            $plugin = $source->sleepPlugin;
        } else {
            return null;
        }

        if ($plugin instanceof Plugin && $source->offline_screen_image) {
            return $this->imageResolver->resolve($device, $source->offline_screen_image);
        }

        return ImageGenerationService::getDeviceSpecificDefaultImage($device, 'sleep');
    }
}
