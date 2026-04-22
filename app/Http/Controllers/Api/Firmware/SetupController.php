<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Actions\Api\ResolveDeviceByMacAddress;
use App\Http\Controllers\Controller;
use App\Services\ImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SetupController extends Controller
{
    public function __construct(private ResolveDeviceByMacAddress $resolveDevice) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->header('id')) {
            return response()->json([
                'status' => 404,
                'message' => 'MAC Address not registered',
            ], 404);
        }

        $device = $this->resolveDevice->handle($request);

        if (! $device) {
            return response()->json([
                'status' => 404,
                'message' => 'MAC Address not registered or invalid access token',
            ], 404);
        }

        $imagePath = ImageGenerationService::getDeviceSpecificDefaultImage($device, 'setup-logo');

        return response()->json([
            'status' => 200,
            'api_key' => $device->api_key,
            'friendly_id' => $device->friendly_id,
            'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
            'message' => 'Welcome to TRMNL BYOS',
        ]);
    }
}
