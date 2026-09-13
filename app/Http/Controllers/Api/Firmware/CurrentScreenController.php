<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Actions\Api\ResolveDeviceByApiKey;
use App\Http\Controllers\Controller;
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

        $imageUuid = $device->current_screen_image;

        if (! $imageUuid) {
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
        $owner = $imageUuid ? Plugin::where('current_image', $imageUuid)->first() : null;

        $response = [
            'status' => 200,
            'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
            'filename' => $this->screenFilename->make(
                $imagePath,
                $owner ? 'plugin:'.$owner->id : 'device:'.$device->id,
                $owner ? DeviceScreenFilename::PREFIX_PLUGIN : DeviceScreenFilename::PREFIX_SYSTEM,
            ),
            'refresh_rate' => $device->default_refresh_interval,
            'reset_firmware' => false,
            'update_firmware' => false,
            'firmware_url' => $device->firmware_url,
            'special_function' => $device->special_function ?? 'sleep',
            // Same hardcoded value as DisplayController. The comment there has the reason.
            'temperature_profile' => 'a',
        ];

        if (config('services.trmnl.image_url_timeout')) {
            $response['image_url_timeout'] = config('services.trmnl.image_url_timeout');
        }

        return response()->json($response);
    }
}
