<?php

namespace App\Http\Controllers\Api\Firmware;

use App\Actions\Api\ResolveDeviceByApiKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeviceLogRequest;
use App\Models\DeviceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DeviceLogController extends Controller
{
    public function __construct(private ResolveDeviceByApiKey $resolveDevice) {}

    public function store(StoreDeviceLogRequest $request): JsonResponse
    {
        $device = $this->resolveDevice->handle($request);

        if (! $device) {
            return response()->json([
                'status' => 404,
                'message' => 'Device not found or invalid access token',
            ], 404);
        }

        $device->update([
            'last_log_request' => $request->json()->all(),
        ]);

        foreach ($request->logs() as $log) {
            Log::info('Device Log', $log);
            DeviceLog::create([
                'device_id' => $device->id,
                'device_timestamp' => $log['creation_timestamp'] ?? now(),
                'log_entry' => $log,
            ]);
        }

        return response()->json([
            'status' => 200,
        ]);
    }
}
