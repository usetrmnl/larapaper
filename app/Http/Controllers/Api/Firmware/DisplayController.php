<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Actions\Api\ResolveDeviceByApiKey;
use App\Actions\Api\RunDeviceDisplayCycle;
use App\Actions\Api\UpdateDeviceTelemetry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DisplayController extends Controller
{
    public function __construct(
        private ResolveDeviceByApiKey $resolveDevice,
        private UpdateDeviceTelemetry $updateTelemetry,
        private RunDeviceDisplayCycle $runDisplayCycle,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $device = $this->resolveDevice->handle($request, autoAssign: true);

        if (! $device) {
            return response()->json([
                'message' => 'MAC Address not registered (or not set), or invalid access token',
            ], 404);
        }

        $this->updateTelemetry->handle($request, $device);

        $cycle = $this->runDisplayCycle->handle($device);

        $imagePath = $cycle['image_path'];
        $refreshTimeOverride = $cycle['refresh_time_override'];

        $response = [
            'status' => 0,
            'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
            'filename' => $imagePath ? basename($imagePath) : null,
            'refresh_rate' => $refreshTimeOverride ?? $device->default_refresh_interval,
            'reset_firmware' => false,
            'update_firmware' => $device->update_firmware,
            'firmware_url' => $device->firmware_url,
            'special_function' => $device->special_function ?? 'sleep',
            'maximum_compatibility' => $device->maximum_compatibility,
        ];

        if (config('services.trmnl.image_url_timeout')) {
            $response['image_url_timeout'] = config('services.trmnl.image_url_timeout');
        }

        if ($device->update_firmware) {
            $device->resetUpdateFirmwareFlag();
        }

        return response()->json($response);
    }
}
